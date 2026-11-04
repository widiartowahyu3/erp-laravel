// Pure rules behind the permission matrix. The server enforces the same rules; these only keep the screen consistent.

function viewOf(resource) {
    return resource.actions.find((action) => action.action === 'view')?.name ?? null;
}

function otherNames(resource) {
    const view = viewOf(resource);

    return resource.actions.map((action) => action.name).filter((name) => name !== view);
}

export function lockReason(resource, action, selected, grantable) {
    const selectedSet = new Set(selected);
    const grantableSet = new Set(grantable);
    const view = viewOf(resource);

    if (!grantableSet.has(action.name)) {
        return 'Di luar izin Anda';
    }

    if (view !== null && action.name !== view && !selectedSet.has(view) && !grantableSet.has(view)) {
        return 'Memerlukan izin lihat yang tidak Anda miliki';
    }

    if (action.name === view && selectedSet.has(view) && otherNames(resource).some((name) => selectedSet.has(name) && !grantableSet.has(name))) {
        return 'Izin lain pada sumber ini berada di luar izin Anda';
    }

    return '';
}

export function togglePermission(selected, resource, action, checked) {
    const next = new Set(selected);
    const view = viewOf(resource);

    if (checked) {
        next.add(action.name);
        if (view !== null) {
            next.add(view);
        }
    } else {
        next.delete(action.name);
        if (action.name === view) {
            otherNames(resource).forEach((name) => next.delete(name));
        }
    }

    return [...next];
}

export function selectModule(selected, module, grantable) {
    const next = new Set(selected);
    const grantableSet = new Set(grantable);

    for (const resource of module.resources) {
        const view = viewOf(resource);

        if (view !== null && !next.has(view) && !grantableSet.has(view)) {
            continue;
        }

        resource.actions
            .filter((action) => grantableSet.has(action.name))
            .forEach((action) => next.add(action.name));
    }

    return [...next];
}

export function clearModule(selected, module, grantable) {
    const next = new Set(selected);
    const grantableSet = new Set(grantable);

    for (const resource of module.resources) {
        resource.actions
            .filter((action) => grantableSet.has(action.name))
            .forEach((action) => next.delete(action.name));

        const view = viewOf(resource);

        if (view !== null && selected.includes(view) && otherNames(resource).some((name) => next.has(name))) {
            next.add(view);
        }
    }

    return [...next];
}

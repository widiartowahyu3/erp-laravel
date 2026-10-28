import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function usePermission() {
    const page = usePage();

    const permissions = computed(() => {
        const auth = page.props?.auth;
        if (!auth || !auth.permissions || !Array.isArray(auth.permissions)) {
            return [];
        }
        return auth.permissions;
    });

    function can(permission) {
        if (!permission) return false;
        return permissions.value.includes(permission);
    }

    function canAny(perms = []) {
        if (!Array.isArray(perms) || perms.length === 0) return false;
        return perms.some((p) => can(p));
    }

    function canAll(perms = []) {
        if (!Array.isArray(perms) || perms.length === 0) return false;
        return perms.every((p) => can(p));
    }

    return {
        permissions,
        can,
        canAny,
        canAll,
    };
}

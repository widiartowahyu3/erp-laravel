import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive, nextTick } from 'vue';
import axe from 'axe-core';
import RoleIndex from '../Pages/Admin/Roles/Index.vue';
import RoleForm from '../Pages/Admin/Roles/Form.vue';
import RoleShow from '../Pages/Admin/Roles/Show.vue';
import { clearModule, lockReason, selectModule, togglePermission } from '../Support/permissionMatrix';

const { mockPageProps, mockConfirm, mockRouterGet, mockRouterPost, mockRouterDelete, lastForm } = vi.hoisted(() => ({
    mockPageProps: { auth: { user: { id: 1, name: 'Admin' }, permissions: [] }, flash: {}, errors: {} },
    mockConfirm: vi.fn(),
    mockRouterGet: vi.fn(),
    mockRouterPost: vi.fn(),
    mockRouterDelete: vi.fn(),
    lastForm: { current: null },
}));

vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: mockConfirm }) }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockPageProps }),
    useForm: (initialData) => {
        const form = reactive({ ...initialData, processing: false, errors: {}, hasErrors: false, post: vi.fn(), put: vi.fn() });
        lastForm.current = form;

        return form;
    },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
    router: { get: mockRouterGet, post: mockRouterPost, delete: mockRouterDelete },
}));

const stubs = { AppLayout: { template: '<main><slot /></main>' } };
const meta = { page: 1, last_page: 1, per_page: 25, total: 2, from: 1, to: 2 };

function action(resource, name, label) {
    return { action: name, name: `${resource}.${name}`, label };
}

const products = {
    key: 'products',
    label: 'Master Produk',
    actions: [action('products', 'view', 'Lihat'), action('products', 'create', 'Tambah'), action('products', 'update', 'Ubah')],
};

const units = {
    key: 'units',
    label: 'Satuan Ukuran',
    actions: [action('units', 'view', 'Lihat'), action('units', 'create', 'Tambah')],
};

const reports = {
    key: 'reports',
    label: 'Laporan',
    actions: [action('reports', 'view_sales', 'Melihat Laporan Penjualan')],
};

const masterData = { key: 'master_data', label: 'Data Master', resources: [products, units] };
const reporting = { key: 'reporting', label: 'Laporan', resources: [reports] };
const permissionMatrix = [masterData, reporting];
const allNames = [...products.actions, ...units.actions, ...reports.actions].map((a) => a.name);

const customRole = { id: 9, name: 'peran_kustom', display_name: 'Peran Kustom', description: 'Untuk uji', isSystem: false };
const systemRole = { id: 2, name: 'viewer', display_name: 'Pemirsa', description: null, isSystem: true };

const rolesPayload = {
    items: {
        data: [
            { ...customRole, usersCount: 0, permissionsCount: 2 },
            { id: 8, name: 'dipakai', display_name: 'Dipakai', description: null, isSystem: false, usersCount: 3, permissionsCount: 1 },
            { ...systemRole, usersCount: 5, permissionsCount: 40 },
            { id: 1, name: 'super_admin', display_name: 'Super Administrator', description: null, isSystem: true, usersCount: null, permissionsCount: 99 },
        ],
        meta,
        query: { filter: {} },
    },
};

function setPermissions(permissions) {
    mockPageProps.auth = { user: { id: 1, name: 'Admin' }, permissions };
    mockPageProps.errors = {};
}

function buttonByText(wrapper, text) {
    return wrapper.findAll('button').find((b) => b.text().includes(text));
}

async function expectNoAxeViolations(wrapper) {
    const results = await axe.run(wrapper.element, {
        rules: { 'color-contrast': { enabled: false }, region: { enabled: false } },
    });
    const severe = results.violations.filter((v) => ['critical', 'serious'].includes(v.impact) || v.id === 'heading-order');
    expect(severe).toEqual([]);
}

beforeEach(() => {
    vi.clearAllMocks();
    setPermissions([]);
});

describe('permission matrix rules', () => {
    it('checking a non-view action also checks view', () => {
        expect(togglePermission([], products, products.actions[1], true).sort()).toEqual(['products.create', 'products.view']);
    });

    it('unchecking view also unchecks the other actions of that resource only', () => {
        const next = togglePermission(['products.view', 'products.create', 'units.view'], products, products.actions[0], false);

        expect(next).toEqual(['units.view']);
    });

    it('unchecking a non-view action keeps view', () => {
        expect(togglePermission(['products.view', 'products.create'], products, products.actions[1], false)).toEqual(['products.view']);
    });

    it('a resource without view has no dependency', () => {
        expect(togglePermission([], reports, reports.actions[0], true)).toEqual(['reports.view_sales']);
    });

    it('explains which boxes are outside the ceiling', () => {
        expect(lockReason(products, products.actions[1], [], ['products.view'])).toBe('Di luar izin Anda');
        expect(lockReason(products, products.actions[0], [], ['products.view'])).toBe('');
    });

    it('locks an action whose view the actor cannot grant', () => {
        expect(lockReason(products, products.actions[1], [], ['products.create'])).toContain('izin lihat');
    });

    it('keeps view when another selected action is outside the ceiling', () => {
        expect(lockReason(products, products.actions[0], ['products.view', 'products.update'], ['products.view'])).toContain('di luar izin Anda');
    });

    it('select all adds only what the actor may grant', () => {
        expect(selectModule([], masterData, ['products.view', 'products.create']).sort()).toEqual(['products.create', 'products.view']);
    });

    it('clear removes only what the actor may grant and keeps needed view', () => {
        expect(clearModule(['products.view', 'products.update', 'units.view'], masterData, ['products.view', 'units.view']).sort())
            .toEqual(['products.update', 'products.view']);
    });
});

describe('Admin/Roles/Index', () => {
    it('renders rows, counts and hides the super admin count', () => {
        const wrapper = mount(RoleIndex, { props: rolesPayload, global: { stubs } });

        expect(wrapper.text()).toContain('Peran Kustom');
        expect(wrapper.text()).toContain('Bawaan');
        expect(wrapper.text()).toContain('Kustom');
        expect(wrapper.text()).toContain('Tidak ditampilkan');
    });

    it('offers duplicate on every role but edit and delete on custom roles only', () => {
        setPermissions(['roles.create', 'roles.update', 'roles.delete']);
        const wrapper = mount(RoleIndex, { props: rolesPayload, global: { stubs } });

        expect(wrapper.find('button[aria-label="Duplikat peran Pemirsa"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/admin/roles/2/edit"]').exists()).toBe(false);
        expect(wrapper.find('button[aria-label="Hapus peran Pemirsa"]').exists()).toBe(false);
        expect(wrapper.find('a[href="/admin/roles/9/edit"]').exists()).toBe(true);
    });

    it('hides every write action without permission', () => {
        const wrapper = mount(RoleIndex, { props: rolesPayload, global: { stubs } });

        expect(wrapper.text()).not.toContain('Tambah Peran');
        expect(wrapper.find('button[aria-label="Duplikat peran Pemirsa"]').exists()).toBe(false);
        expect(wrapper.find('button[aria-label="Hapus peran Peran Kustom"]').exists()).toBe(false);
    });

    it('disables delete for a role in use and states the count', () => {
        setPermissions(['roles.delete']);
        const wrapper = mount(RoleIndex, { props: rolesPayload, global: { stubs } });
        const button = wrapper.find('button[aria-label="Hapus peran Dipakai"]');

        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('title')).toContain('3 pengguna');
    });

    it('deletes through the dialog and locks a double submit', async () => {
        setPermissions(['roles.delete']);
        mockConfirm.mockResolvedValue(true);
        const wrapper = mount(RoleIndex, { props: rolesPayload, global: { stubs } });
        const button = wrapper.find('button[aria-label="Hapus peran Peran Kustom"]');

        await button.trigger('click');
        await button.trigger('click');
        await flushPromises();

        expect(mockRouterDelete).toHaveBeenCalledTimes(1);
        expect(mockRouterDelete.mock.calls[0][0]).toBe('/admin/roles/9');
    });

    it('sends the type filter to the server', async () => {
        const wrapper = mount(RoleIndex, { props: rolesPayload, global: { stubs } });

        await wrapper.find('select[aria-label="Filter berdasarkan jenis peran"]').setValue('0');

        expect(mockRouterGet.mock.calls.at(-1)[1]['filter[is_system]']).toBe('0');
    });

    it('has no axe violations', async () => {
        setPermissions(['roles.create', 'roles.update', 'roles.delete']);
        await expectNoAxeViolations(mount(RoleIndex, { props: rolesPayload, global: { stubs }, attachTo: document.body }));
    });
});

describe('Admin/Roles/Form', () => {
    const formProps = { permissionMatrix, grantablePermissions: allNames };

    it('groups the matrix by module with labelled boxes', () => {
        const wrapper = mount(RoleForm, { props: formProps, global: { stubs } });

        expect(wrapper.text()).toContain('Data Master');
        expect(wrapper.text()).toContain('Master Produk');
        expect(wrapper.find('input[aria-label="Tambah Master Produk"]').exists()).toBe(true);
        expect(wrapper.find('input[aria-label="Melihat Laporan Penjualan Laporan"]').exists()).toBe(true);
    });

    it('sends permission names, with view added automatically', async () => {
        const wrapper = mount(RoleForm, { props: formProps, global: { stubs } });

        await wrapper.find('#role-display-name').setValue('Staf Baru');
        await wrapper.find('#permission-products-create').setValue(true);

        expect(lastForm.current.permissions.sort()).toEqual(['products.create', 'products.view']);
        expect(wrapper.find('#permission-products-view').element.checked).toBe(true);

        await wrapper.find('form').trigger('submit');

        expect(lastForm.current.post.mock.calls[0][0]).toBe('/admin/roles');
        expect(lastForm.current.display_name).toBe('Staf Baru');
    });

    it('unchecking view clears the rest of the resource on screen', async () => {
        const wrapper = mount(RoleForm, { props: { ...formProps, selectedPermissions: ['products.view', 'products.create'] }, global: { stubs } });

        await wrapper.find('#permission-products-view').setValue(false);

        expect(lastForm.current.permissions).toEqual([]);
        expect(wrapper.find('#permission-products-create').element.checked).toBe(false);
    });

    it('disables boxes outside the ceiling', () => {
        const wrapper = mount(RoleForm, { props: { permissionMatrix, grantablePermissions: ['products.view'] }, global: { stubs } });

        expect(wrapper.find('#permission-products-view').attributes('disabled')).toBeUndefined();
        expect(wrapper.find('#permission-products-create').attributes('disabled')).toBeDefined();
        expect(wrapper.find('#permission-products-create').attributes('title')).toBe('Di luar izin Anda');
    });

    it('select all and clear work per module', async () => {
        const wrapper = mount(RoleForm, { props: formProps, global: { stubs } });

        await wrapper.find('button[aria-label="Pilih semua izin modul Data Master"]').trigger('click');
        expect(lastForm.current.permissions.sort()).toEqual(['products.create', 'products.update', 'products.view', 'units.create', 'units.view']);

        await wrapper.find('button[aria-label="Kosongkan izin modul Data Master"]').trigger('click');
        expect(lastForm.current.permissions).toEqual([]);
    });

    it('updates through PUT and locks a double submit', async () => {
        const wrapper = mount(RoleForm, { props: { ...formProps, role: customRole, selectedPermissions: ['products.view'] }, global: { stubs } });

        await wrapper.find('form').trigger('submit');
        await wrapper.find('form').trigger('submit');

        expect(lastForm.current.put).toHaveBeenCalledTimes(1);
        expect(lastForm.current.put.mock.calls[0][0]).toBe('/admin/roles/9');
    });

    it('allows an empty permission set', async () => {
        const wrapper = mount(RoleForm, { props: formProps, global: { stubs } });

        await wrapper.find('form').trigger('submit');

        expect(lastForm.current.permissions).toEqual([]);
        expect(lastForm.current.post).toHaveBeenCalledTimes(1);
    });

    it('shows server errors', async () => {
        const wrapper = mount(RoleForm, { props: formProps, global: { stubs } });

        lastForm.current.errors = { permissions: 'Izin products.create memerlukan izin products.view.', display_name: 'Wajib diisi.' };
        await nextTick();

        expect(wrapper.text()).toContain('memerlukan izin products.view');
        expect(wrapper.text()).toContain('Wajib diisi.');
    });

    it('has no axe violations', async () => {
        await expectNoAxeViolations(mount(RoleForm, { props: formProps, global: { stubs }, attachTo: document.body }));
    });
});

describe('Admin/Roles/Show', () => {
    const showProps = { selectedPermissions: ['products.view'], usersCount: 0, permissionMatrix, grantablePermissions: allNames };

    it('shows a system role read-only with duplicate only', async () => {
        setPermissions(['roles.create', 'roles.update', 'roles.delete']);
        const wrapper = mount(RoleShow, { props: { ...showProps, item: systemRole }, global: { stubs } });

        expect(wrapper.text()).toContain('Peran bawaan tidak dapat diubah');
        expect(wrapper.find('a[href="/admin/roles/2/edit"]').exists()).toBe(false);
        expect(buttonByText(wrapper, 'Hapus')).toBeUndefined();
        expect(wrapper.find('#permission-products-view').attributes('disabled')).toBeDefined();
        expect(wrapper.find('#permission-products-view').element.checked).toBe(true);
        expect(wrapper.text()).not.toContain('Pilih Semua');

        await buttonByText(wrapper, 'Duplikat').trigger('click');

        expect(mockRouterPost.mock.calls[0][0]).toBe('/admin/roles/2/duplicate');
    });

    it('a custom role can be edited and deleted', async () => {
        setPermissions(['roles.update', 'roles.delete']);
        mockConfirm.mockResolvedValue(true);
        const wrapper = mount(RoleShow, { props: { ...showProps, item: customRole }, global: { stubs } });

        expect(wrapper.find('a[href="/admin/roles/9/edit"]').exists()).toBe(true);

        await buttonByText(wrapper, 'Hapus').trigger('click');
        await flushPromises();

        expect(mockRouterDelete.mock.calls[0][0]).toBe('/admin/roles/9');
    });

    it('disables delete while the role is in use and says how many users', () => {
        setPermissions(['roles.delete']);
        const wrapper = mount(RoleShow, { props: { ...showProps, usersCount: 4, item: customRole }, global: { stubs } });

        expect(buttonByText(wrapper, 'Hapus').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('dipakai oleh 4 pengguna');
    });

    it('hides the user count when the server withholds it', () => {
        const wrapper = mount(RoleShow, { props: { ...showProps, usersCount: null, item: systemRole }, global: { stubs } });

        expect(wrapper.text()).toContain('Tidak ditampilkan');
    });

    it('hides write actions without permission', () => {
        const wrapper = mount(RoleShow, { props: { ...showProps, item: customRole }, global: { stubs } });

        expect(buttonByText(wrapper, 'Duplikat')).toBeUndefined();
        expect(buttonByText(wrapper, 'Hapus')).toBeUndefined();
    });

    it('shows a refused duplicate', () => {
        mockPageProps.errors = { permissions: 'Anda tidak dapat mengubah izin yang tidak Anda miliki.' };
        const wrapper = mount(RoleShow, { props: { ...showProps, item: systemRole }, global: { stubs } });

        expect(wrapper.text()).toContain('tidak Anda miliki');
    });

    it('has no axe violations', async () => {
        await expectNoAxeViolations(mount(RoleShow, { props: { ...showProps, item: systemRole }, global: { stubs }, attachTo: document.body }));
    });
});

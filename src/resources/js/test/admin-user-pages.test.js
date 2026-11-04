import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive, nextTick } from 'vue';
import axe from 'axe-core';
import UserIndex from '../Pages/Admin/Users/Index.vue';
import UserForm from '../Pages/Admin/Users/Form.vue';
import UserShow from '../Pages/Admin/Users/Show.vue';

const { mockPageProps, mockConfirm, mockRouterGet, mockRouterPost, forms } = vi.hoisted(() => ({
    mockPageProps: { auth: { user: { id: 1, name: 'Admin' }, permissions: [] }, flash: {}, errors: {} },
    mockConfirm: vi.fn(),
    mockRouterGet: vi.fn(),
    mockRouterPost: vi.fn(),
    forms: [],
}));

vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: mockConfirm }) }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockPageProps }),
    useForm: (initialData) => {
        const form = reactive({
            ...initialData,
            processing: false,
            errors: {},
            hasErrors: false,
            post: vi.fn(),
            put: vi.fn(),
            reset: vi.fn(() => {
                Object.assign(form, initialData);
            }),
        });
        forms.push(form);

        return form;
    },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
    router: { get: mockRouterGet, post: mockRouterPost },
}));

const stubs = { AppLayout: { template: '<main><slot /></main>' }, teleport: true };
const meta = { page: 1, last_page: 2, per_page: 25, total: 30, from: 1, to: 25 };

const user = {
    id: 7,
    name: 'Budi Staf',
    email: 'budi@example.test',
    is_active: true,
    last_login_at: '2026-11-01 08:00',
    roles: [{ id: 3, name: 'viewer', display_name: 'Pemirsa' }],
};

const usersPayload = {
    items: { data: [user], meta, query: { filter: {} } },
    roles: [{ id: 3, display_name: 'Pemirsa' }, { id: 4, display_name: 'Keuangan' }],
};

const assignableRoles = [
    { id: 3, name: 'viewer', display_name: 'Pemirsa', description: 'Hanya melihat', assignable: true },
    { id: 4, name: 'finance', display_name: 'Keuangan', description: 'Akuntansi', assignable: false },
    { id: 1, name: 'super_admin', display_name: 'Super Administrator', description: 'Semua', assignable: true },
];

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
    forms.length = 0;
    setPermissions([]);
});

afterEach(() => {
    vi.useRealTimers();
});

describe('Admin/Users/Index', () => {
    it('renders rows from the server payload', () => {
        const wrapper = mount(UserIndex, { props: usersPayload, global: { stubs } });

        expect(wrapper.text()).toContain('Budi Staf');
        expect(wrapper.text()).toContain('budi@example.test');
        expect(wrapper.text()).toContain('Pemirsa');
        expect(wrapper.text()).toContain('Aktif');
    });

    it('shows create and edit links only with their permissions', () => {
        let wrapper = mount(UserIndex, { props: usersPayload, global: { stubs } });

        expect(wrapper.text()).not.toContain('Tambah Pengguna');
        expect(wrapper.find('a[href="/admin/users/7/edit"]').exists()).toBe(false);

        setPermissions(['users.create', 'users.update']);
        wrapper = mount(UserIndex, { props: usersPayload, global: { stubs } });

        expect(wrapper.text()).toContain('Tambah Pengguna');
        expect(wrapper.find('a[href="/admin/users/7/edit"]').exists()).toBe(true);
    });

    it('sends the status and role filters to the server', async () => {
        const wrapper = mount(UserIndex, { props: usersPayload, global: { stubs } });

        await wrapper.find('select[aria-label="Filter berdasarkan peran"]').setValue('4');
        expect(mockRouterGet.mock.calls.at(-1)[0]).toBe('/admin/users');
        expect(mockRouterGet.mock.calls.at(-1)[1]['filter[role_id]']).toBe('4');

        await wrapper.find('select[aria-label="Filter berdasarkan status"]').setValue('0');
        expect(mockRouterGet.mock.calls.at(-1)[1]['filter[is_active]']).toBe('0');
    });

    it('has no axe violations, with rows and when empty', async () => {
        await expectNoAxeViolations(mount(UserIndex, { props: usersPayload, global: { stubs }, attachTo: document.body }));
        await expectNoAxeViolations(mount(UserIndex, {
            props: { ...usersPayload, items: { ...usersPayload.items, data: [] } },
            global: { stubs },
            attachTo: document.body,
        }));
    });
});

describe('Admin/Users/Form', () => {
    it('asks for a password only when creating', () => {
        const create = mount(UserForm, { props: { assignableRoles }, global: { stubs } });
        expect(create.find('input[name="password"]').exists()).toBe(true);

        const edit = mount(UserForm, { props: { user, assignableRoles }, global: { stubs } });
        expect(edit.find('input[name="password"]').exists()).toBe(false);
    });

    it('disables a role beyond the actor ceiling and explains why', () => {
        const wrapper = mount(UserForm, { props: { assignableRoles }, global: { stubs } });

        expect(wrapper.find('#user-role-3').attributes('disabled')).toBeUndefined();
        expect(wrapper.find('#user-role-4').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Peran ini memuat izin yang tidak Anda miliki.');
    });

    it('locks every role on your own account', () => {
        const wrapper = mount(UserForm, { props: { user, assignableRoles, isSelf: true }, global: { stubs } });

        expect(wrapper.find('#user-role-3').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Anda tidak dapat mengubah peran akun sendiri.');
    });

    it('locks the super admin role on the last active super admin', () => {
        const wrapper = mount(UserForm, { props: { user, assignableRoles, isLastSuperAdmin: true }, global: { stubs } });

        expect(wrapper.find('#user-role-1').attributes('disabled')).toBeDefined();
        expect(wrapper.find('#user-role-3').attributes('disabled')).toBeUndefined();
    });

    it('sends chosen role ids and blocks a double submit', async () => {
        const wrapper = mount(UserForm, { props: { assignableRoles }, global: { stubs } });
        const form = forms.at(-1);

        await wrapper.find('#user-role-3').setValue(true);
        expect(form.roles).toEqual([3]);

        await wrapper.find('form').trigger('submit');
        await wrapper.find('form').trigger('submit');

        expect(form.post).toHaveBeenCalledTimes(1);
        expect(form.post.mock.calls[0][0]).toBe('/admin/users');
    });

    it('updates through PUT on the user url and shows server errors', async () => {
        const wrapper = mount(UserForm, { props: { user, assignableRoles }, global: { stubs } });
        const form = forms.at(-1);

        expect(form.roles).toEqual([3]);

        await wrapper.find('form').trigger('submit');
        expect(form.put.mock.calls[0][0]).toBe('/admin/users/7');

        form.errors = { email: 'Email sudah dipakai.', roles: 'Peran tidak dikenal.', form: 'Tidak diizinkan.' };
        await nextTick();

        expect(wrapper.text()).toContain('Email sudah dipakai.');
        expect(wrapper.text()).toContain('Peran tidak dikenal.');
        expect(wrapper.text()).toContain('Tidak diizinkan.');
    });

    it('has no axe violations', async () => {
        await expectNoAxeViolations(mount(UserForm, { props: { assignableRoles }, global: { stubs }, attachTo: document.body }));
    });
});

describe('Admin/Users/Show', () => {
    const showProps = { item: user, isSelf: false, isLastSuperAdmin: false, canManage: true };

    it('shows actions only with their permissions', () => {
        let wrapper = mount(UserShow, { props: showProps, global: { stubs } });

        expect(buttonByText(wrapper, 'Nonaktifkan')).toBeUndefined();
        expect(buttonByText(wrapper, 'Reset Kata Sandi')).toBeUndefined();

        setPermissions(['users.update', 'users.delete']);
        wrapper = mount(UserShow, { props: showProps, global: { stubs } });

        expect(buttonByText(wrapper, 'Nonaktifkan')).toBeDefined();
        expect(buttonByText(wrapper, 'Reset Kata Sandi')).toBeDefined();
    });

    it('deactivates only after the dialog, warns about sessions, and locks a double submit', async () => {
        setPermissions(['users.delete']);
        mockConfirm.mockResolvedValue(true);
        const wrapper = mount(UserShow, { props: showProps, global: { stubs } });

        await buttonByText(wrapper, 'Nonaktifkan').trigger('click');
        await buttonByText(wrapper, 'Nonaktifkan').trigger('click');
        await flushPromises();

        expect(mockConfirm.mock.calls[0][0].message).toContain('sesi aktif');
        expect(mockRouterPost).toHaveBeenCalledTimes(1);
        expect(mockRouterPost.mock.calls[0][0]).toBe('/admin/users/7/active');
        expect(mockRouterPost.mock.calls[0][1]).toEqual({ is_active: false });
    });

    it('does nothing when the dialog is cancelled', async () => {
        setPermissions(['users.delete']);
        mockConfirm.mockResolvedValue(false);
        const wrapper = mount(UserShow, { props: showProps, global: { stubs } });

        await buttonByText(wrapper, 'Nonaktifkan').trigger('click');
        await flushPromises();

        expect(mockRouterPost).not.toHaveBeenCalled();
    });

    it('offers to activate an inactive account', async () => {
        setPermissions(['users.delete']);
        mockConfirm.mockResolvedValue(true);
        const wrapper = mount(UserShow, { props: { ...showProps, item: { ...user, is_active: false } }, global: { stubs } });

        await buttonByText(wrapper, 'Aktifkan').trigger('click');
        await flushPromises();

        expect(mockRouterPost.mock.calls[0][1]).toEqual({ is_active: true });
    });

    it('disables actions on your own account and says why', () => {
        setPermissions(['users.update', 'users.delete']);
        const wrapper = mount(UserShow, { props: { ...showProps, isSelf: true }, global: { stubs } });

        expect(buttonByText(wrapper, 'Nonaktifkan').attributes('disabled')).toBeDefined();
        expect(buttonByText(wrapper, 'Reset Kata Sandi').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Anda tidak dapat menonaktifkan akun sendiri.');
        expect(wrapper.text()).toContain('gunakan halaman Akun');
    });

    it('disables deactivating the last super admin and says why', () => {
        setPermissions(['users.delete']);
        const wrapper = mount(UserShow, { props: { ...showProps, isLastSuperAdmin: true }, global: { stubs } });

        expect(buttonByText(wrapper, 'Nonaktifkan').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Super Administrator aktif yang terakhir');
    });

    it('hides editing and blocks actions on an account beyond the actor', () => {
        setPermissions(['users.update', 'users.delete']);
        const wrapper = mount(UserShow, { props: { ...showProps, canManage: false }, global: { stubs } });

        expect(wrapper.find('a[href="/admin/users/7/edit"]').exists()).toBe(false);
        expect(buttonByText(wrapper, 'Reset Kata Sandi').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('melebihi izin Anda');
    });

    it('resets a password through a dialog, never displaying it, and locks a double submit', async () => {
        setPermissions(['users.update']);
        const wrapper = mount(UserShow, { props: showProps, global: { stubs } });
        const form = forms.at(-1);

        expect(wrapper.find('input[name="password"]').exists()).toBe(false);

        await buttonByText(wrapper, 'Reset Kata Sandi').trigger('click');
        await nextTick();

        const password = wrapper.find('input[name="password"]');
        expect(password.attributes('type')).toBe('password');
        expect(wrapper.text()).toContain('sesi aktif');

        await password.setValue('Valid#Password2026');
        await wrapper.find('input[name="password_confirmation"]').setValue('Valid#Password2026');
        await wrapper.find('form').trigger('submit');
        await wrapper.find('form').trigger('submit');

        expect(form.post).toHaveBeenCalledTimes(1);
        expect(form.post.mock.calls[0][0]).toBe('/admin/users/7/reset-password');
        expect(wrapper.text()).not.toContain('Valid#Password2026');

        form.post.mock.calls[0][1].onSuccess();
        await nextTick();

        expect(wrapper.find('input[name="password"]').exists()).toBe(false);
        expect(form.password).toBe('');
    });

    it('shows server errors from a refused action', () => {
        mockPageProps.errors = { form: 'Tindakan ini tidak dapat dilakukan pada akun Anda sendiri.' };
        const wrapper = mount(UserShow, { props: showProps, global: { stubs } });

        expect(wrapper.text()).toContain('Tindakan ini tidak dapat dilakukan');
    });

    it('has no axe violations, including with the dialog open', async () => {
        setPermissions(['users.update', 'users.delete']);
        const wrapper = mount(UserShow, { props: showProps, global: { stubs }, attachTo: document.body });

        await expectNoAxeViolations(wrapper);

        await buttonByText(wrapper, 'Reset Kata Sandi').trigger('click');
        await nextTick();

        await expectNoAxeViolations(wrapper);
    });
});

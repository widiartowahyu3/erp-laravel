import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import axe from 'axe-core';
import AccountIndex from '../Pages/Accounting/Accounts/Index.vue';
import AccountForm from '../Pages/Accounting/Accounts/Form.vue';
import AccountShow from '../Pages/Accounting/Accounts/Show.vue';

const { mockPageProps, mockConfirm, mockRouterGet, mockRouterPost, mockRouterDelete, lastForm } = vi.hoisted(() => ({
    mockPageProps: { auth: { user: { id: 1, name: 'Admin' }, permissions: [] }, flash: {}, errors: {} },
    mockConfirm: vi.fn(),
    mockRouterGet: vi.fn(),
    mockRouterPost: vi.fn(),
    mockRouterDelete: vi.fn(),
    lastForm: { current: null },
}));

vi.mock('@/composables/useConfirm', () => ({
    useConfirm: () => ({ confirm: mockConfirm }),
}));

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
        });
        lastForm.current = form;

        return form;
    },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
    router: { get: mockRouterGet, post: mockRouterPost, delete: mockRouterDelete },
}));

const stubs = {
    AppLayout: { template: '<main><slot /></main>' },
};

const meta = { page: 1, last_page: 3, per_page: 25, total: 60, from: 1, to: 25 };

function setPermissions(permissions) {
    mockPageProps.auth = { user: { id: 1, name: 'Admin' }, permissions };
    mockPageProps.errors = {};
    mockPageProps.flash = {};
}

async function expectNoAxeViolations(wrapper) {
    const results = await axe.run(wrapper.element, {
        rules: {
            'color-contrast': { enabled: false },
            region: { enabled: false },
        },
    });
    const severe = results.violations.filter((v) => ['critical', 'serious'].includes(v.impact) || v.id === 'heading-order');
    expect(severe).toEqual([]);
}

function buttonByText(wrapper, text) {
    return wrapper.findAll('button').find((b) => b.text().includes(text));
}

const accountsPayload = {
    items: {
        data: [
            { id: 1, code: '1000', name: 'ASET', type: 'asset', type_label: 'Aset', normal_balance: 'debit', normal_balance_label: 'Debit', is_postable: false, is_active: true, level: 0 },
            { id: 2, code: '1101', name: 'Kas', type: 'asset', type_label: 'Aset', normal_balance: 'debit', normal_balance_label: 'Debit', is_postable: true, is_active: false, level: 1 },
        ],
        meta,
        query: { filter: {} },
    },
    types: [{ value: 'asset', label: 'Aset' }, { value: 'liability', label: 'Kewajiban' }],
};

describe('Bagan Akun: Index', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setPermissions(['chart_of_accounts.view', 'chart_of_accounts.create', 'chart_of_accounts.update', 'chart_of_accounts.delete']);
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('renders rows from real data with indentation by level', () => {
        const wrapper = mount(AccountIndex, { props: accountsPayload, global: { stubs } });
        const text = wrapper.text();

        expect(text).toContain('1000');
        expect(text).toContain('ASET');
        expect(text).toContain('(Induk)');
        expect(text).toContain('Kas');
        expect(text).toContain('Aset');
        expect(text).toContain('Nonaktif');
        expect(wrapper.html()).toContain('pl-5');
    });

    it('calls router.get with the right page, per_page and filter parameters', async () => {
        const wrapper = mount(AccountIndex, { props: accountsPayload, global: { stubs } });

        wrapper.findComponent({ name: 'Pagination' }).vm.$emit('change-page', 2);
        await nextTick();
        expect(mockRouterGet.mock.lastCall[1]).toMatchObject({ page: 2 });

        wrapper.findComponent({ name: 'Pagination' }).vm.$emit('change-per-page', 50);
        await nextTick();
        expect(mockRouterGet.mock.lastCall[1]).toMatchObject({ per_page: 50 });

        const typeFilter = wrapper.findAll('select').find((s) => s.attributes('aria-label') === 'Filter berdasarkan tipe akun');
        await typeFilter.setValue('liability');
        await nextTick();
        expect(mockRouterGet.mock.lastCall[1]).toMatchObject({ 'filter[type]': 'liability' });
    });

    it('sends the search term to the server', async () => {
        vi.useFakeTimers();
        const wrapper = mount(AccountIndex, { props: accountsPayload, global: { stubs } });

        wrapper.findComponent({ name: 'FilterBar' }).vm.$emit('update:search', 'kas');
        await nextTick();
        vi.runAllTimers();
        vi.useRealTimers();

        expect(mockRouterGet.mock.lastCall[1]).toMatchObject({ search: 'kas' });
    });

    it('shows actions according to permissions', () => {
        const full = mount(AccountIndex, { props: accountsPayload, global: { stubs } });
        expect(full.text()).toContain('Tambah Akun');
        expect(full.find('[aria-label="Hapus akun 1101"]').exists()).toBe(true);
        expect(full.find('[aria-label="Ubah akun 1101"]').exists()).toBe(true);

        setPermissions(['chart_of_accounts.view']);
        const readOnly = mount(AccountIndex, { props: accountsPayload, global: { stubs } });
        expect(readOnly.text()).not.toContain('Tambah Akun');
        expect(readOnly.find('[aria-label="Hapus akun 1101"]').exists()).toBe(false);
        expect(readOnly.find('[aria-label="Ubah akun 1101"]').exists()).toBe(false);
        expect(readOnly.find('[aria-label="Lihat akun 1101"]').exists()).toBe(true);
    });

    it('deletes through the confirmation dialog once, and not when cancelled', async () => {
        const wrapper = mount(AccountIndex, { props: accountsPayload, global: { stubs } });

        mockConfirm.mockResolvedValueOnce(false);
        await wrapper.find('[aria-label="Hapus akun 1101"]').trigger('click');
        await flushPromises();
        expect(mockRouterDelete).not.toHaveBeenCalled();

        mockConfirm.mockResolvedValue(true);
        await wrapper.find('[aria-label="Hapus akun 1101"]').trigger('click');
        await wrapper.find('[aria-label="Hapus akun 1101"]').trigger('click');
        await flushPromises();
        expect(mockRouterDelete).toHaveBeenCalledTimes(1);
        expect(mockRouterDelete.mock.calls[0][0]).toBe('/accounting/accounts/2');
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(AccountIndex, { props: accountsPayload, global: { stubs }, attachTo: document.body });
        await expectNoAxeViolations(wrapper);
    });
});

const accountFormProps = {
    parents: [{ id: 1, code: '1000', name: 'ASET' }],
    types: [{ value: 'asset', label: 'Aset' }],
    normalBalances: [{ value: 'debit', label: 'Debit' }],
};

describe('Bagan Akun: Form dan Show', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setPermissions(['chart_of_accounts.update']);
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    const account = { id: 7, code: '1103', name: 'Kas Kecil', type: 'asset', normal_balance: 'debit', parent_id: 1, is_postable: true, is_active: true };

    it('keeps fields editable for an unused account', () => {
        const wrapper = mount(AccountForm, { props: { ...accountFormProps, account, isEdit: true }, global: { stubs } });

        expect(wrapper.find('#account-code').attributes('disabled')).toBeUndefined();
        expect(wrapper.find('#account-type').attributes('disabled')).toBeUndefined();
    });

    it('disables locked fields with an explanation for an account used by journals', () => {
        const wrapper = mount(AccountForm, { props: { ...accountFormProps, account, isEdit: true, isUsed: true }, global: { stubs } });

        expect(wrapper.find('#account-code').attributes('disabled')).toBeDefined();
        expect(wrapper.find('#account-type').attributes('disabled')).toBeDefined();
        expect(wrapper.find('#account-normal-balance').attributes('disabled')).toBeDefined();
        expect(wrapper.find('#account-name').attributes('disabled')).toBeUndefined();
        expect(wrapper.text()).toContain('sudah dipakai pada jurnal');
    });

    it('shows server errors per field', async () => {
        const wrapper = mount(AccountForm, { props: accountFormProps, global: { stubs } });

        lastForm.current.errors = { parent_id: 'Akun induk harus berupa akun header.' };
        await nextTick();

        expect(wrapper.text()).toContain('Akun induk harus berupa akun header.');
    });

    it('locks double submit', async () => {
        const wrapper = mount(AccountForm, { props: accountFormProps, global: { stubs } });

        await wrapper.find('form').trigger('submit');
        await wrapper.find('form').trigger('submit');

        expect(lastForm.current.post).toHaveBeenCalledTimes(1);
        expect(lastForm.current.post.mock.calls[0][0]).toBe('/accounting/accounts');
    });

    it('renders the account detail and has no accessibility violations', async () => {
        const wrapper = mount(AccountShow, {
            props: { item: { ...account, parent: { id: 1, code: '1000', name: 'ASET' } }, isUsed: true, isMapped: false },
            global: { stubs },
            attachTo: document.body,
        });

        expect(wrapper.text()).toContain('Kas Kecil');
        expect(wrapper.text()).toContain('Sudah dipakai pada jurnal');
        expect(wrapper.find('h2').text()).toBe('Informasi Akun');
        await expectNoAxeViolations(wrapper);
    });

    it('has no accessibility violations on the form', async () => {
        const wrapper = mount(AccountForm, { props: { ...accountFormProps, account, isEdit: true }, global: { stubs }, attachTo: document.body });
        await expectNoAxeViolations(wrapper);
    });
});

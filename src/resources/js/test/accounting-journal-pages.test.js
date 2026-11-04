import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import axe from 'axe-core';
import JournalIndex from '../Pages/Accounting/Journals/Index.vue';
import JournalForm from '../Pages/Accounting/Journals/Form.vue';
import JournalShow from '../Pages/Accounting/Journals/Show.vue';

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

const journalsPayload = {
    items: {
        data: [
            { id: 1, number: 'JV/202611/00001', journal_date: '2026-11-04', description: 'Setoran modal', status: 'posted', total_debit: '500000.00', sourceLabel: null, sourceUrl: null, isReversal: false, isReversed: true },
            { id: 2, number: 'JV/202611/00002', journal_date: '2026-11-04', description: 'Pembalik JV/202611/00001', status: 'posted', total_debit: '500000.00', sourceLabel: 'Penerimaan Barang', sourceUrl: '/purchasing/goods-receipts/9', isReversal: true, isReversed: false },
            { id: 3, number: 'JV/202611/00003', journal_date: '2026-11-05', description: 'Draf', status: 'draft', total_debit: '100.00', sourceLabel: null, sourceUrl: null, isReversal: false, isReversed: false },
        ],
        meta,
        query: { filter: {} },
    },
    statuses: ['draft', 'posted', 'void'],
    sources: [{ value: 'goods_receipt', label: 'Penerimaan Barang' }],
};

describe('Jurnal: Index', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setPermissions(['journals.view', 'journals.create']);
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('renders real rows with status badges, reversal labels and source links', () => {
        const wrapper = mount(JournalIndex, { props: journalsPayload, global: { stubs } });
        const text = wrapper.text();

        expect(text).toContain('JV/202611/00001');
        expect(text).toContain('Setoran modal');
        expect(text).toContain('Diposting');
        expect(text).toContain('Draf');
        expect(text).toContain('Pembalik');
        expect(text).toContain('Sudah dibalik');
        expect(text).toContain('Jurnal Manual');
        expect(wrapper.find('a[href="/purchasing/goods-receipts/9"]').text()).toBe('Penerimaan Barang');
    });

    it('calls router.get for pagination and filters', async () => {
        const wrapper = mount(JournalIndex, { props: journalsPayload, global: { stubs } });

        wrapper.findComponent({ name: 'Pagination' }).vm.$emit('change-page', 3);
        await nextTick();
        expect(mockRouterGet.mock.lastCall[1]).toMatchObject({ page: 3 });

        const statusFilter = wrapper.findAll('select').find((s) => s.attributes('aria-label') === 'Filter berdasarkan status');
        await statusFilter.setValue('draft');
        await nextTick();
        expect(mockRouterGet.mock.lastCall[1]).toMatchObject({ 'filter[status]': 'draft' });

        const sourceFilter = wrapper.findAll('select').find((s) => s.attributes('aria-label') === 'Filter berdasarkan sumber');
        await sourceFilter.setValue('manual');
        await nextTick();
        expect(mockRouterGet.mock.lastCall[1]).toMatchObject({ 'filter[source_type]': 'manual' });
    });

    it('hides the create button without permission and has no accessibility violations', async () => {
        const allowed = mount(JournalIndex, { props: journalsPayload, global: { stubs }, attachTo: document.body });
        expect(allowed.text()).toContain('Buat Jurnal Manual');
        await expectNoAxeViolations(allowed);

        setPermissions(['journals.view']);
        const denied = mount(JournalIndex, { props: journalsPayload, global: { stubs } });
        expect(denied.text()).not.toContain('Buat Jurnal Manual');
    });
});

const manualPosted = {
    id: 1, number: 'JV/202611/00001', journal_date: '2026-11-04', description: 'Setoran modal', status: 'posted',
    total_debit: '500000.00', total_credit: '500000.00', source_type: null, reversal_of_id: null, reversal: null, reversal_of: null,
    lines: [
        { id: 1, account: { code: '1101', name: 'Kas' }, debit: '500000.00', credit: '0.00', description: null },
        { id: 2, account: { code: '3101', name: 'Modal Pemilik' }, debit: '0.00', credit: '500000.00', description: null },
    ],
};

const allJournalPermissions = ['journals.view', 'journals.update', 'journals.post', 'journals.void', 'journals.reverse'];

describe('Jurnal: Show', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        setPermissions(allJournalPermissions);
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('offers edit, post and void on a draft, and no reversal', () => {
        const wrapper = mount(JournalShow, { props: { item: { ...manualPosted, status: 'draft' } }, global: { stubs } });

        expect(wrapper.text()).toContain('Ubah');
        expect(buttonByText(wrapper, 'Posting')).toBeTruthy();
        expect(buttonByText(wrapper, 'Batalkan Draf')).toBeTruthy();
        expect(buttonByText(wrapper, 'Balik')).toBeFalsy();
    });

    it('offers only reversal on a posted manual journal, with lines and totals', () => {
        const wrapper = mount(JournalShow, { props: { item: manualPosted }, global: { stubs } });

        expect(buttonByText(wrapper, 'Balik')).toBeTruthy();
        expect(buttonByText(wrapper, 'Posting')).toBeFalsy();
        expect(buttonByText(wrapper, 'Batalkan Draf')).toBeFalsy();
        expect(wrapper.findAll('[data-testid="journal-line"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('Modal Pemilik');
    });

    it('shows no actions on an automatic journal and points to the source document', () => {
        const wrapper = mount(JournalShow, {
            props: { item: { ...manualPosted, source_type: 'goods_receipt' }, isAutomatic: true, sourceLabel: 'Penerimaan Barang', sourceUrl: '/purchasing/goods-receipts/9' },
            global: { stubs },
        });

        expect(buttonByText(wrapper, 'Balik')).toBeFalsy();
        expect(buttonByText(wrapper, 'Batalkan Draf')).toBeFalsy();
        expect(wrapper.text()).toContain('batalkan dokumen sumbernya');
        expect(wrapper.find('a[href="/purchasing/goods-receipts/9"]').text()).toBe('Penerimaan Barang');
    });

    it('marks reversed journals and reversal journals, and offers no second reversal', () => {
        const reversed = mount(JournalShow, { props: { item: { ...manualPosted, reversal: { id: 2, number: 'JV/202611/00002' } } }, global: { stubs } });
        expect(reversed.text()).toContain('Sudah dibalik');
        expect(reversed.find('a[href="/accounting/journals/2"]').exists()).toBe(true);
        expect(buttonByText(reversed, 'Balik')).toBeFalsy();

        const reversal = mount(JournalShow, { props: { item: { ...manualPosted, reversal_of_id: 1, reversal_of: { id: 1, number: 'JV/202611/00001' } } }, global: { stubs } });
        expect(reversal.text()).toContain('Pembalik');
        expect(reversal.find('a[href="/accounting/journals/1"]').exists()).toBe(true);
        expect(buttonByText(reversal, 'Balik')).toBeFalsy();
    });

    it('hides actions without permission', () => {
        setPermissions(['journals.view']);
        const wrapper = mount(JournalShow, { props: { item: { ...manualPosted, status: 'draft' } }, global: { stubs } });

        expect(buttonByText(wrapper, 'Posting')).toBeFalsy();
        expect(buttonByText(wrapper, 'Batalkan Draf')).toBeFalsy();
    });

    it('asks for a reason before reversing, explains the effect, and locks double submit', async () => {
        const wrapper = mount(JournalShow, { props: { item: manualPosted }, global: { stubs } });

        mockConfirm.mockResolvedValueOnce(null);
        await buttonByText(wrapper, 'Balik').trigger('click');
        await flushPromises();
        expect(mockRouterPost).not.toHaveBeenCalled();
        expect(mockConfirm.mock.calls[0][0].requiresReason).toBe(true);
        expect(mockConfirm.mock.calls[0][0].message).toContain('menukar debit dan kredit');

        mockConfirm.mockResolvedValue({ confirmed: true, reason: 'Salah akun' });
        await buttonByText(wrapper, 'Balik').trigger('click');
        await buttonByText(wrapper, 'Balik').trigger('click');
        await flushPromises();

        expect(mockRouterPost).toHaveBeenCalledTimes(1);
        expect(mockRouterPost.mock.calls[0][0]).toBe('/accounting/journals/1/reverse');
        expect(mockRouterPost.mock.calls[0][1]).toEqual({ reason: 'Salah akun' });
    });

    it('voids a draft with a reason', async () => {
        const wrapper = mount(JournalShow, { props: { item: { ...manualPosted, status: 'draft' } }, global: { stubs } });

        mockConfirm.mockResolvedValue({ confirmed: true, reason: 'Salah input' });
        await buttonByText(wrapper, 'Batalkan Draf').trigger('click');
        await flushPromises();

        expect(mockRouterPost.mock.calls[0][0]).toBe('/accounting/journals/1/void');
        expect(mockRouterPost.mock.calls[0][1]).toEqual({ reason: 'Salah input' });
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(JournalShow, { props: { item: manualPosted }, global: { stubs }, attachTo: document.body });
        await expectNoAxeViolations(wrapper);
    });
});

describe('Jurnal: Form', () => {
    const accounts = [{ id: 11, code: '1101', name: 'Kas' }, { id: 31, code: '3101', name: 'Modal Pemilik' }];

    function inputFor(wrapper, label) {
        return wrapper.find(`[aria-label="${label}"]`);
    }

    beforeEach(() => {
        vi.clearAllMocks();
        setPermissions(['journals.create', 'journals.post']);
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('starts with two lines and offers only active postable accounts by code and name', () => {
        const wrapper = mount(JournalForm, { props: { accounts }, global: { stubs } });

        expect(wrapper.findAll('[data-testid="journal-line"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('1101 - Kas');
    });

    it('adds and removes lines, keeping at least two', async () => {
        const wrapper = mount(JournalForm, { props: { accounts }, global: { stubs } });

        expect(inputFor(wrapper, 'Hapus baris 1').attributes('disabled')).toBeDefined();

        await buttonByText(wrapper, 'Tambah Baris').trigger('click');
        expect(wrapper.findAll('[data-testid="journal-line"]')).toHaveLength(3);

        await inputFor(wrapper, 'Hapus baris 3').trigger('click');
        expect(wrapper.findAll('[data-testid="journal-line"]')).toHaveLength(2);
    });

    it('clears the other side when one side is filled', async () => {
        const wrapper = mount(JournalForm, { props: { accounts }, global: { stubs } });

        await inputFor(wrapper, 'Debit baris 1').setValue('100');
        await inputFor(wrapper, 'Kredit baris 1').setValue('50');

        expect(lastForm.current.lines[0].credit).toBe('50');
        expect(lastForm.current.lines[0].debit).toBe('');

        await inputFor(wrapper, 'Debit baris 1').setValue('75');
        expect(lastForm.current.lines[0].credit).toBe('');
    });

    it('computes exact totals and difference without float drift', async () => {
        const wrapper = mount(JournalForm, { props: { accounts }, global: { stubs } });

        await inputFor(wrapper, 'Debit baris 1').setValue('0.10');
        await inputFor(wrapper, 'Debit baris 2').setValue('0.20');

        expect(wrapper.find('[data-testid="total-debit"]').text()).toBe('0.30');
        expect(wrapper.find('[data-testid="total-credit"]').text()).toBe('0.00');
        expect(wrapper.find('[data-testid="difference"]').text()).toBe('0.30');
    });

    it('enables Posting only when balanced above zero; Simpan stays available', async () => {
        const wrapper = mount(JournalForm, { props: { accounts }, global: { stubs } });
        const post = () => wrapper.find('[data-testid="post-button"]');
        const save = () => wrapper.find('[data-testid="save-button"]');

        expect(post().attributes('disabled')).toBeDefined();

        await inputFor(wrapper, 'Debit baris 1').setValue('500.00');
        await inputFor(wrapper, 'Kredit baris 2').setValue('499.99');
        expect(wrapper.find('[data-testid="difference"]').text()).toBe('0.01');
        expect(post().attributes('disabled')).toBeDefined();
        expect(save().attributes('disabled')).toBeUndefined();

        await inputFor(wrapper, 'Kredit baris 2').setValue('500.00');
        expect(wrapper.find('[data-testid="difference"]').text()).toBe('0.00');
        expect(post().attributes('disabled')).toBeUndefined();
    });

    it('shows invalid input without throwing and keeps Posting disabled', async () => {
        const wrapper = mount(JournalForm, { props: { accounts }, global: { stubs } });

        await inputFor(wrapper, 'Debit baris 1').setValue('abc');

        expect(wrapper.find('[data-testid="difference"]').text()).toBe('Nilai tidak valid');
        expect(wrapper.find('[data-testid="post-button"]').attributes('disabled')).toBeDefined();
    });

    it('shows server errors per line and for the whole journal', async () => {
        const wrapper = mount(JournalForm, { props: { accounts }, global: { stubs } });

        lastForm.current.errors = { lines: 'Total debit harus sama dengan total kredit.', 'lines.1.account_id': 'Akun wajib dipilih.' };
        await nextTick();

        expect(wrapper.text()).toContain('Total debit harus sama dengan total kredit.');
        expect(wrapper.text()).toContain('Akun wajib dipilih.');
    });

    it('locks double submit and posts after saving', async () => {
        const wrapper = mount(JournalForm, { props: { accounts }, global: { stubs } });

        await inputFor(wrapper, 'Debit baris 1').setValue('10');
        await inputFor(wrapper, 'Kredit baris 2').setValue('10');
        await wrapper.find('[data-testid="post-button"]').trigger('click');
        await wrapper.find('[data-testid="post-button"]').trigger('click');

        expect(lastForm.current.post).toHaveBeenCalledTimes(1);

        lastForm.current.post.mock.calls[0][1].onSuccess({ props: { item: { id: 42 } } });

        expect(mockRouterPost.mock.calls[0][0]).toBe('/accounting/journals/42/post');
    });

    it('loads an existing draft with trimmed amounts', () => {
        const wrapper = mount(JournalForm, {
            props: {
                accounts,
                isEdit: true,
                journal: { id: 5, journal_date: '2026-11-04', description: 'Draf', lines: [
                    { account_id: 11, debit: '150.50', credit: '0.00', description: null },
                    { account_id: 31, debit: '0.00', credit: '150.50', description: null },
                ] },
            },
            global: { stubs },
        });

        expect(lastForm.current.lines[0].debit).toBe('150.5');
        expect(lastForm.current.lines[0].credit).toBe('');
        expect(wrapper.find('[data-testid="total-debit"]').text()).toBe('150.50');
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(JournalForm, { props: { accounts }, global: { stubs }, attachTo: document.body });
        await expectNoAxeViolations(wrapper);
    });
});

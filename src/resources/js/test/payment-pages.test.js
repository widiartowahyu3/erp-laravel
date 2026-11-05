import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import axe from 'axe-core';
import PaymentIndex from '../Pages/Accounting/Payments/Index.vue';
import PaymentCreate from '../Pages/Accounting/Payments/Create.vue';
import PaymentEdit from '../Pages/Accounting/Payments/Edit.vue';
import PaymentShow from '../Pages/Accounting/Payments/Show.vue';
import Pagination from '../Components/ui/Pagination.vue';

const ALL_PERMISSIONS = ['payments.view', 'payments.create', 'payments.update', 'payments.post', 'payments.void', 'journals.view'];

const { mockPageProps, mockConfirm, mockRouterGet, mockRouterPost, forms } = vi.hoisted(() => ({
    mockPageProps: { auth: { user: { id: 1 }, permissions: [] }, flash: {}, errors: {} },
    mockConfirm: vi.fn(),
    mockRouterGet: vi.fn(),
    mockRouterPost: vi.fn(),
    forms: [],
}));

vi.mock('@/composables/useConfirm', () => ({
    useConfirm: () => ({ confirm: mockConfirm }),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockPageProps }),
    useForm: (initial) => {
        const form = reactive({ ...initial, processing: false, errors: {}, post: vi.fn(), put: vi.fn() });
        forms.push(form);

        return form;
    },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
    router: { get: mockRouterGet, post: mockRouterPost, put: vi.fn(), delete: vi.fn() },
}));

// Only the layout is stubbed; every UI contract under test uses the real component.
const stubs = { AppLayout: { template: '<main><slot /></main>' } };

async function expectNoViolations(element) {
    const results = await axe.run(element, {
        rules: { 'color-contrast': { enabled: false }, region: { enabled: false } },
    });

    expect(results.violations.filter((v) => ['critical', 'serious'].includes(v.impact) || v.id === 'heading-order')).toEqual([]);
}

function listRow(overrides = {}) {
    return {
        id: 7,
        number: 'PAY/202611/00007',
        paymentDate: '2026-11-04',
        customer: { id: 3, code: 'CUST-1', name: 'PT Maju Jaya' },
        method: 'bank_transfer',
        methodLabel: 'Transfer Bank',
        account: { id: 2, code: '1102', name: 'Bank Operasional' },
        reference: 'TRF-1',
        amount: '1500.50',
        status: 'posted',
        ...overrides,
    };
}

const methods = [
    { value: 'cash', label: 'Tunai' },
    { value: 'bank_transfer', label: 'Transfer Bank' },
    { value: 'other', label: 'Lainnya' },
];

function listProps(rows = [listRow()]) {
    return {
        items: {
            data: rows,
            meta: { page: 1, per_page: 25, total: 60, last_page: 3, from: 1, to: 25 },
            query: { search: null, sort: null, direction: null, filter: {} },
        },
        customers: [{ id: 3, code: 'CUST-1', name: 'PT Maju Jaya' }],
        methods,
        statuses: ['draft', 'posted', 'void'],
    };
}

const openInvoice = (id, outstanding, overrides = {}) => ({
    id,
    number: `SI/202611/0000${id}`,
    invoiceDate: '2026-11-01',
    dueDate: '2026-11-10',
    total: outstanding,
    paidAmount: '0.00',
    outstanding,
    ...overrides,
});

function createProps(invoices = [openInvoice(1, '100.25'), openInvoice(2, '200.50'), openInvoice(3, '300.00')]) {
    return {
        customers: [{ id: 3, code: 'CUST-1', name: 'PT Maju Jaya' }, { id: 4, code: 'CUST-2', name: 'CV Lain' }],
        cashBankAccounts: [{ id: 1, code: '1101', name: 'Kas Utama' }, { id: 2, code: '1102', name: 'Bank Operasional' }],
        methods,
        selectedCustomerId: 3,
        outstandingInvoices: invoices,
        defaults: { paymentDate: '2026-11-05' },
    };
}

function payment(overrides = {}) {
    return {
        id: 7,
        number: 'PAY/202611/00007',
        status: 'draft',
        typeLabel: 'Penerimaan',
        customerId: 3,
        customer: { id: 3, code: 'CUST-1', name: 'PT Maju Jaya' },
        paymentDate: '2026-11-04',
        method: 'bank_transfer',
        methodLabel: 'Transfer Bank',
        accountId: 2,
        account: { id: 2, code: '1102', name: 'Bank Operasional' },
        amount: '1000.00',
        reference: 'TRF-1',
        notes: null,
        voidReason: null,
        allocations: [{ id: 1, salesInvoiceId: 9, invoiceNumber: 'SI/202611/00009', amount: '1000.00' }],
        ...overrides,
    };
}

const mountCreate = (props = createProps()) => mount(PaymentCreate, { props, global: { stubs } });
const mountShow = (item, permissions = ALL_PERMISSIONS, journal = null) => {
    mockPageProps.auth.permissions = permissions;

    return mount(PaymentShow, { props: { item, journal }, global: { stubs } });
};

const allocationInput = (wrapper, id) => wrapper.find(`input[aria-label="Alokasi untuk faktur SI/202611/0000${id}"]`);
const button = (wrapper, label) => wrapper.findAll('button').find((b) => b.text().trim() === label);
const buttonLabels = (wrapper) => wrapper.findAll('button').map((b) => b.text().trim());
const postButton = (wrapper) => wrapper.find('[data-testid="post-button"]');

async function setAmount(wrapper, value) {
    forms[0].amount = value;
    await wrapper.vm.$nextTick();
}

beforeEach(() => {
    vi.clearAllMocks();
    forms.length = 0;
    mockPageProps.auth.permissions = [...ALL_PERMISSIONS];
    mockPageProps.flash = {};
    mockPageProps.errors = {};
});

describe('Payment list', () => {
    it('renders real rows', () => {
        const text = mount(PaymentIndex, { props: listProps(), global: { stubs } }).text();

        expect(text).toContain('PAY/202611/00007');
        expect(text).toContain('PT Maju Jaya');
        expect(text).toContain('Transfer Bank');
        expect(text).toContain('1102 - Bank Operasional');
        expect(text).toContain('1.500,50');
        expect(text).not.toContain('Belum ada pembayaran');
    });

    it.each([
        ['draft', 'Draf'],
        ['posted', 'Diposting'],
        ['void', 'Dibatalkan'],
    ])('labels status %s as %s through the real badge', (status, label) => {
        const wrapper = mount(PaymentIndex, { props: listProps([listRow({ status })]), global: { stubs } });

        expect(wrapper.find('tbody').text()).toContain(label);
    });

    it('shows the empty state only without rows', () => {
        expect(mount(PaymentIndex, { props: listProps([]), global: { stubs } }).text()).toContain('Belum ada pembayaran');
    });

    it('offers creating only with the permission and editing only on drafts', () => {
        const allowed = mount(PaymentIndex, {
            props: listProps([listRow({ id: 1, status: 'draft' }), listRow({ id: 2, status: 'posted' })]),
            global: { stubs },
        });

        expect(allowed.find('a[href="/accounting/payments/create"]').exists()).toBe(true);
        expect(allowed.find('a[href="/accounting/payments/1/edit"]').exists()).toBe(true);
        expect(allowed.find('a[href="/accounting/payments/2/edit"]').exists()).toBe(false);

        mockPageProps.auth.permissions = ['payments.view'];
        const denied = mount(PaymentIndex, { props: listProps([listRow({ status: 'draft' })]), global: { stubs } });

        expect(denied.find('a[href="/accounting/payments/create"]').exists()).toBe(false);
        expect(denied.find('a[href="/accounting/payments/7/edit"]').exists()).toBe(false);
    });

    it.each([
        ['Filter berdasarkan status', 'posted', 'status'],
        ['Filter berdasarkan metode', 'cash', 'method'],
        ['Filter berdasarkan pelanggan', '3', 'customer_id'],
    ])('sends %s to the server as filter[...]', async (label, value, key) => {
        const wrapper = mount(PaymentIndex, { props: listProps(), global: { stubs } });

        await wrapper.find(`select[aria-label="${label}"]`).setValue(value);

        const [url, query] = mockRouterGet.mock.calls.at(-1);

        expect(url).toBe('/accounting/payments');
        expect(query[`filter[${key}]`] ?? query.filter?.[key]).toBe(value);
    });

    it('asks the server for the next page and page size', async () => {
        const wrapper = mount(PaymentIndex, { props: listProps(), global: { stubs } });
        const pagination = wrapper.findComponent(Pagination);

        await pagination.vm.$emit('change-page', 2);
        expect(mockRouterGet.mock.calls.at(-1)[1].page).toBe(2);

        await pagination.vm.$emit('change-per-page', 50);
        expect(mockRouterGet.mock.calls.at(-1)[1].per_page).toBe(50);
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(PaymentIndex, { props: listProps(), attachTo: document.body, global: { stubs } });

        await expectNoViolations(wrapper.element);
        wrapper.unmount();
    });
});

describe('Payment form', () => {
    it('explains what to do before a customer is chosen', () => {
        const wrapper = mountCreate({ ...createProps([]), selectedCustomerId: null });

        expect(wrapper.text()).toContain('Pilih pelanggan untuk melihat faktur terbukanya');
    });

    it('explains when the customer owes nothing', () => {
        expect(mountCreate(createProps([])).text()).toContain('tidak memiliki faktur terbuka');
    });

    it('reloads the open invoices through a partial visit when the customer changes', async () => {
        const wrapper = mountCreate();

        await wrapper.findAll('select')[0].setValue('4');

        const [url, query, options] = mockRouterGet.mock.calls.at(-1);

        expect(url).toBe('/accounting/payments/create');
        expect(query).toEqual({ customer_id: '4' });
        expect(options.only).toEqual(['outstandingInvoices', 'selectedCustomerId']);
    });

    it('lists open invoices with their remainder', () => {
        const text = mountCreate().find('tbody').text();

        expect(text).toContain('SI/202611/00001');
        expect(text).toContain('100,25');
        expect(text).toContain('200,50');
    });

    describe('automatic allocation', () => {
        it('fills oldest first and stops when the amount runs out, down to the cent', async () => {
            const wrapper = mountCreate();

            await setAmount(wrapper, '250.75');
            await button(wrapper, 'Alokasikan otomatis').trigger('click');

            expect(allocationInput(wrapper, 1).element.value).toBe('100.25');
            expect(allocationInput(wrapper, 2).element.value).toBe('150.50');
            expect(allocationInput(wrapper, 3).element.value).toBe('');
            expect(wrapper.find('[data-testid="allocated"]').text()).toContain('250,75');
            expect(wrapper.find('[data-testid="unallocated"]').text()).toMatch(/0(,00)?$/);
            expect(postButton(wrapper).attributes('disabled')).toBeUndefined();
        });

        it('leaves the excess unallocated when the amount is larger than everything owed', async () => {
            const wrapper = mountCreate();

            await setAmount(wrapper, '1000.00');
            await button(wrapper, 'Alokasikan otomatis').trigger('click');

            expect(allocationInput(wrapper, 1).element.value).toBe('100.25');
            expect(allocationInput(wrapper, 2).element.value).toBe('200.50');
            expect(allocationInput(wrapper, 3).element.value).toBe('300.00');
            expect(wrapper.find('[data-testid="allocated"]').text()).toContain('600,75');
            expect(wrapper.find('[data-testid="unallocated"]').text()).toContain('399,25');
            expect(postButton(wrapper).attributes('disabled')).toBeDefined();
        });

        it('clears earlier allocations when run again with a smaller amount', async () => {
            const wrapper = mountCreate();

            await setAmount(wrapper, '600.75');
            await button(wrapper, 'Alokasikan otomatis').trigger('click');
            await setAmount(wrapper, '0.10');
            await button(wrapper, 'Alokasikan otomatis').trigger('click');

            expect(allocationInput(wrapper, 1).element.value).toBe('0.10');
            expect(allocationInput(wrapper, 2).element.value).toBe('');
            expect(allocationInput(wrapper, 3).element.value).toBe('');
        });

        it('is unavailable without a valid amount', async () => {
            const wrapper = mountCreate();

            expect(button(wrapper, 'Alokasikan otomatis').attributes('disabled')).toBeDefined();

            await setAmount(wrapper, '12,5');

            expect(button(wrapper, 'Alokasikan otomatis').attributes('disabled')).toBeDefined();
        });
    });

    it('keeps posting off until the difference is exactly zero, while a draft can still be saved', async () => {
        const wrapper = mountCreate();

        await setAmount(wrapper, '100.25');
        await allocationInput(wrapper, 1).setValue('100.24');

        expect(wrapper.find('[data-testid="unallocated"]').text()).toContain('0,01');
        expect(postButton(wrapper).attributes('disabled')).toBeDefined();

        await wrapper.find('form').trigger('submit');

        expect(forms[0].post).toHaveBeenCalledTimes(1);
        expect(forms[0].allocations).toEqual([{ sales_invoice_id: 1, amount: '100.24' }]);
    });

    it('turns posting on at zero and saves before posting', async () => {
        const wrapper = mountCreate();

        await setAmount(wrapper, '100.25');
        await allocationInput(wrapper, 1).setValue('100.25');

        expect(postButton(wrapper).attributes('disabled')).toBeUndefined();

        await postButton(wrapper).trigger('click');

        const [url, options] = forms[0].post.mock.calls[0];

        expect(url).toBe('/accounting/payments');

        options.onSuccess({ props: { item: { id: 44 } } });

        expect(mockRouterPost.mock.calls[0][0]).toBe('/accounting/payments/44/post');
    });

    it('hides posting without the permission', async () => {
        mockPageProps.auth.permissions = ['payments.create'];

        expect(postButton(mountCreate()).exists()).toBe(false);
    });

    it('never posts when the amount is zero or nothing is allocated', async () => {
        const wrapper = mountCreate();

        expect(postButton(wrapper).attributes('disabled')).toBeDefined();

        await setAmount(wrapper, '0.00');

        expect(postButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it.each([
        ['more than the invoice owes', '100.26', 'Melebihi sisa tagihan'],
        ['too many decimals', '1.001', 'Isi dengan angka'],
        ['a comma as the separator', '1,5', 'Isi dengan angka'],
    ])('refuses %s on the row', async (_case, value, message) => {
        const wrapper = mountCreate();

        await setAmount(wrapper, '100.25');
        await allocationInput(wrapper, 1).setValue(value);
        await wrapper.find('form').trigger('submit');

        expect(forms[0].post).not.toHaveBeenCalled();
        expect(wrapper.find('tbody').text()).toContain(message);
        expect(postButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('refuses allocations above the amount before saving', async () => {
        const wrapper = mountCreate();

        await setAmount(wrapper, '50.00');
        await allocationInput(wrapper, 1).setValue('60.00');
        await wrapper.find('form').trigger('submit');

        expect(forms[0].post).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('melebihi jumlah pembayaran');
    });

    it('shows server errors on the row they belong to, even with blank rows skipped', async () => {
        const wrapper = mountCreate();

        await setAmount(wrapper, '100.00');
        await allocationInput(wrapper, 2).setValue('100.00');
        await wrapper.find('form').trigger('submit');

        forms[0].errors = { 'allocations.0.amount': 'Alokasi ditolak server.', allocations: 'Melebihi sisa pada faktur.' };
        await wrapper.vm.$nextTick();

        const rows = wrapper.findAll('tbody tr');

        expect(rows[0].text()).not.toContain('Alokasi ditolak server.');
        expect(rows[1].text()).toContain('Alokasi ditolak server.');
        expect(wrapper.text()).toContain('Melebihi sisa pada faktur.');
    });

    it('sends only once on a double submit', async () => {
        const wrapper = mountCreate();

        await setAmount(wrapper, '100.25');
        await wrapper.find('form').trigger('submit');
        await wrapper.find('form').trigger('submit');

        expect(forms[0].post).toHaveBeenCalledTimes(1);
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(PaymentCreate, { props: createProps(), attachTo: document.body, global: { stubs } });

        await expectNoViolations(wrapper.element);
        wrapper.unmount();
    });

    it('edit starts from the saved values and updates the same payment', async () => {
        const wrapper = mount(PaymentEdit, {
            props: {
                ...createProps(),
                item: payment({ amount: '100.25', allocations: [{ salesInvoiceId: 1, amount: '100.25' }] }),
            },
            global: { stubs },
        });

        expect(forms[0].customer_id).toBe('3');
        expect(forms[0].account_id).toBe('2');
        expect(forms[0].amount).toBe('100.25');
        expect(allocationInput(wrapper, 1).element.value).toBe('100.25');
        expect(allocationInput(wrapper, 2).element.value).toBe('');

        await wrapper.find('form').trigger('submit');

        expect(forms[0].put.mock.calls[0][0]).toBe('/accounting/payments/7');
        expect(forms[0].post).not.toHaveBeenCalled();
    });

    it('warns when a saved allocation points at an invoice that is no longer open', () => {
        const wrapper = mount(PaymentEdit, {
            props: {
                ...createProps(),
                item: payment({ amount: '100.25', allocations: [{ salesInvoiceId: 99, amount: '100.25' }] }),
            },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('1 alokasi tersimpan merujuk faktur yang tidak lagi terbuka');
    });
});

describe('Payment detail', () => {
    it('shows the header, allocations as links and the journal', () => {
        const wrapper = mountShow(payment({ status: 'posted' }), ALL_PERMISSIONS, { id: 44, number: 'JV/202611/00044', status: 'posted' });
        const text = wrapper.text();

        expect(text).toContain('PAY/202611/00007');
        expect(text).toContain('Diposting');
        expect(text).toContain('Transfer Bank');
        expect(text).toContain('1102 - Bank Operasional');
        expect(text).toContain('TRF-1');
        expect(wrapper.find('a[href="/sales/invoices/9"]').text()).toBe('SI/202611/00009');
        expect(wrapper.find('a[href="/accounting/journals/44"]').exists()).toBe(true);
    });

    it.each([
        ['draft', ['Ubah', 'Posting', 'Batalkan']],
        ['posted', ['Batalkan']],
        ['void', []],
    ])('offers the right actions on status %s', (status, expected) => {
        const labels = buttonLabels(mountShow(payment({ status })));

        for (const action of ['Ubah', 'Posting', 'Batalkan']) {
            expect(labels.includes(action)).toBe(expected.includes(action));
        }
    });

    it('hides every action without permission', () => {
        const labels = buttonLabels(mountShow(payment(), ['payments.view']));

        expect(labels).not.toContain('Ubah');
        expect(labels).not.toContain('Posting');
        expect(labels).not.toContain('Batalkan');
    });

    it('disables posting with an explanation while the allocation falls short', () => {
        const wrapper = mountShow(payment({ allocations: [{ id: 1, salesInvoiceId: 9, invoiceNumber: 'SI/202611/00009', amount: '999.99' }] }));

        expect(button(wrapper, 'Posting').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('belum dialokasikan');
    });

    it('posts after confirmation, and only once on a double click', async () => {
        mockConfirm.mockResolvedValue(true);
        const wrapper = mountShow(payment());
        const post = button(wrapper, 'Posting');

        await post.trigger('click');
        await flushPromises();
        await post.trigger('click');
        await flushPromises();

        expect(mockRouterPost).toHaveBeenCalledTimes(1);
        expect(mockRouterPost.mock.calls[0][0]).toBe('/accounting/payments/7/post');
    });

    it('does nothing when the confirmation is declined', async () => {
        mockConfirm.mockResolvedValue(false);
        const wrapper = mountShow(payment());

        await button(wrapper, 'Posting').trigger('click');
        await flushPromises();

        expect(mockRouterPost).not.toHaveBeenCalled();
    });

    it.each([
        ['posted', 'Sisa tagihan faktur'],
        ['draft', 'belum memengaruhi faktur'],
    ])('voids a %s payment with a reason and explains the effect', async (status, effect) => {
        mockConfirm.mockResolvedValue({ confirmed: true, reason: 'Salah transfer' });
        const wrapper = mountShow(payment({ status }));

        await button(wrapper, 'Batalkan').trigger('click');
        await flushPromises();

        expect(mockConfirm.mock.calls[0][0].requiresReason).toBe(true);
        expect(mockConfirm.mock.calls[0][0].message).toContain(effect);
        expect(mockRouterPost.mock.calls[0][0]).toBe('/accounting/payments/7/void');
        expect(mockRouterPost.mock.calls[0][1]).toEqual({ reason: 'Salah transfer' });
    });

    it('shows server refusals and the void reason', () => {
        mockPageProps.errors = { allocations: 'Alokasi melebihi sisa tagihan.' };

        const wrapper = mountShow(payment({ status: 'void', voidReason: 'Salah transfer' }));

        expect(wrapper.text()).toContain('Alokasi melebihi sisa tagihan.');
        expect(wrapper.text()).toContain('Salah transfer');
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(PaymentShow, {
            props: { item: payment({ status: 'posted' }), journal: { id: 44, number: 'JV/202611/00044', status: 'posted' } },
            attachTo: document.body,
            global: { stubs },
        });

        await expectNoViolations(wrapper.element);
        wrapper.unmount();
    });
});

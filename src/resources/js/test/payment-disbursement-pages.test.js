import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import axe from 'axe-core';
import PaymentIndex from '../Pages/Accounting/Payments/Index.vue';
import PaymentCreate from '../Pages/Accounting/Payments/Create.vue';
import PaymentEdit from '../Pages/Accounting/Payments/Edit.vue';
import PaymentShow from '../Pages/Accounting/Payments/Show.vue';

const { mockPageProps, mockRouterGet, mockRouterPost, forms } = vi.hoisted(() => ({
    mockPageProps: { auth: { user: { id: 1 }, permissions: [] }, flash: {}, errors: {} },
    mockRouterGet: vi.fn(),
    mockRouterPost: vi.fn(),
    forms: [],
}));

vi.mock('@/composables/useConfirm', () => ({
    useConfirm: () => ({ confirm: vi.fn() }),
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

const stubs = { AppLayout: { template: '<main><slot /></main>' } };

const methods = [{ value: 'cash', label: 'Tunai' }, { value: 'bank_transfer', label: 'Transfer Bank' }];

function listProps() {
    return {
        items: {
            data: [{
                id: 7,
                number: 'PAY/202611/00007',
                paymentDate: '2026-11-04',
                paymentType: 'disbursement',
                customer: null,
                supplier: { id: 8, code: 'SUP-1', name: 'PT Pemasok Utama' },
                method: 'bank_transfer',
                methodLabel: 'Transfer Bank',
                account: { id: 2, code: '1102', name: 'Bank Operasional' },
                reference: null,
                amount: '1000.00',
                status: 'posted',
            }],
            meta: { page: 1, per_page: 25, total: 1, last_page: 1, from: 1, to: 1 },
            query: { search: null, sort: null, direction: null, filter: {} },
        },
        customers: [{ id: 3, code: 'CUST-1', name: 'PT Maju Jaya' }],
        suppliers: [{ id: 8, code: 'SUP-1', name: 'PT Pemasok Utama' }],
        methods,
        statuses: ['draft', 'posted', 'void'],
    };
}

function createProps() {
    return {
        paymentType: 'disbursement',
        customers: [],
        suppliers: [{ id: 8, code: 'SUP-1', name: 'PT Pemasok Utama' }, { id: 9, code: 'SUP-2', name: 'CV Lain' }],
        cashBankAccounts: [{ id: 2, code: '1102', name: 'Bank Operasional' }],
        methods,
        selectedCustomerId: null,
        outstandingInvoices: [],
        selectedSupplierId: 8,
        outstandingPurchaseInvoices: [
            { id: 1, number: 'PI/202611/00001', supplierInvoiceNumber: 'INV-1', invoiceDate: '2026-11-01', dueDate: '2026-11-10', total: '100.25', paidAmount: '0.00', outstanding: '100.25' },
            { id: 2, number: 'PI/202611/00002', supplierInvoiceNumber: 'INV-2', invoiceDate: '2026-11-01', dueDate: '2026-11-20', total: '200.50', paidAmount: '0.00', outstanding: '200.50' },
        ],
        defaults: { paymentDate: '2026-11-05' },
    };
}

function shownPayment(overrides = {}) {
    return {
        id: 7,
        number: 'PAY/202611/00007',
        paymentType: 'disbursement',
        status: 'posted',
        typeLabel: 'Pengeluaran',
        customer: null,
        supplier: { id: 8, code: 'SUP-1', name: 'PT Pemasok Utama' },
        paymentDate: '2026-11-04',
        methodLabel: 'Transfer Bank',
        account: { id: 2, code: '1102', name: 'Bank Operasional' },
        amount: '1000.00',
        voidReason: null,
        allocations: [{ id: 1, salesInvoiceId: null, purchaseInvoiceId: 5, invoiceNumber: 'PI/202611/00005', amount: '1000.00' }],
        ...overrides,
    };
}

const input = (wrapper, id) => wrapper.find(`input[aria-label="Alokasi untuk faktur PI/202611/0000${id}"]`);
const button = (wrapper, label) => wrapper.findAll('button').find((b) => b.text().trim() === label);

async function expectNoViolations(element) {
    const results = await axe.run(element, {
        rules: { 'color-contrast': { enabled: false }, region: { enabled: false } },
    });

    expect(results.violations.filter((v) => ['critical', 'serious'].includes(v.impact) || v.id === 'heading-order')).toEqual([]);
}

beforeEach(() => {
    vi.clearAllMocks();
    forms.length = 0;
    mockPageProps.auth.permissions = ['payments.view', 'payments.create', 'payments.update', 'payments.post', 'payments.void', 'journals.view'];
    mockPageProps.errors = {};
    mockPageProps.flash = {};
});

describe('Payment list with both types', () => {
    it('names the supplier on a disbursement', () => {
        const text = mount(PaymentIndex, { props: listProps(), global: { stubs } }).find('tbody').text();

        expect(text).toContain('PT Pemasok Utama');
        expect(text).toContain('SUP-1');
    });

    it.each([
        ['Penerimaan', 'receipt'],
        ['Pengeluaran', 'disbursement'],
    ])('tab %s asks the server with filter[payment_type]', async (label, value) => {
        const wrapper = mount(PaymentIndex, { props: listProps(), global: { stubs } });

        await button(wrapper, label).trigger('click');

        const [url, query] = mockRouterGet.mock.calls.at(-1);

        expect(url).toBe('/accounting/payments');
        expect(query['filter[payment_type]'] ?? query.filter?.payment_type).toBe(value);
    });

    it('offers paying a supplier', () => {
        const wrapper = mount(PaymentIndex, { props: listProps(), global: { stubs } });

        expect(wrapper.find('a[href="/accounting/payments/create?payment_type=disbursement"]').exists()).toBe(true);
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(PaymentIndex, { props: listProps(), attachTo: document.body, global: { stubs } });

        await expectNoViolations(wrapper.element);
        wrapper.unmount();
    });
});

describe('Disbursement form', () => {
    it('is labelled for paying a supplier and lists purchase invoices with the supplier number', () => {
        const wrapper = mount(PaymentCreate, { props: createProps(), global: { stubs } });

        expect(wrapper.text()).toContain('Pembayaran ke Pemasok');
        expect(wrapper.text()).toContain('Pemasok');
        expect(wrapper.find('tbody').text()).toContain('PI/202611/00001');
        expect(wrapper.find('tbody').text()).toContain('INV-1');
        expect(forms[0].supplier_id).toBe('8');
        expect(forms[0].payment_type).toBe('disbursement');
        expect('customer_id' in forms[0]).toBe(false);
    });

    it('reloads the open invoices of another supplier through a partial visit', async () => {
        const wrapper = mount(PaymentCreate, { props: createProps(), global: { stubs } });

        await wrapper.findAll('select')[0].setValue('9');

        const [url, query, options] = mockRouterGet.mock.calls.at(-1);

        expect(url).toBe('/accounting/payments/create');
        expect(query).toEqual({ payment_type: 'disbursement', supplier_id: '9' });
        expect(options.only).toEqual(['outstandingPurchaseInvoices', 'selectedSupplierId']);
    });

    it('allocates oldest first and submits purchase invoice ids', async () => {
        const wrapper = mount(PaymentCreate, { props: createProps(), global: { stubs } });

        forms[0].amount = '150.25';
        await wrapper.vm.$nextTick();
        await button(wrapper, 'Alokasikan otomatis').trigger('click');

        expect(input(wrapper, 1).element.value).toBe('100.25');
        expect(input(wrapper, 2).element.value).toBe('50.00');

        await wrapper.find('form').trigger('submit');

        expect(forms[0].post.mock.calls[0][0]).toBe('/accounting/payments');
        expect(forms[0].allocations).toEqual([
            { purchase_invoice_id: 1, amount: '100.25' },
            { purchase_invoice_id: 2, amount: '50.00' },
        ]);
    });

    it('keeps posting off until fully allocated', async () => {
        const wrapper = mount(PaymentCreate, { props: createProps(), global: { stubs } });
        const post = wrapper.find('[data-testid="post-button"]');

        forms[0].amount = '100.25';
        await wrapper.vm.$nextTick();

        expect(post.attributes('disabled')).toBeDefined();

        await input(wrapper, 1).setValue('100.25');

        expect(post.attributes('disabled')).toBeUndefined();
    });

    it('shows server errors on the row by purchase invoice', async () => {
        const wrapper = mount(PaymentCreate, { props: createProps(), global: { stubs } });

        forms[0].amount = '100.00';
        await input(wrapper, 2).setValue('100.00');
        await wrapper.find('form').trigger('submit');

        forms[0].errors = { 'allocations.0.purchase_invoice_id': 'Faktur ditolak server.' };
        await wrapper.vm.$nextTick();

        const rows = wrapper.findAll('tbody tr');

        expect(rows[0].text()).not.toContain('Faktur ditolak server.');
        expect(rows[1].text()).toContain('Faktur ditolak server.');
    });

    it('edit starts from the saved values and updates the same payment', async () => {
        const wrapper = mount(PaymentEdit, {
            props: {
                ...createProps(),
                item: {
                    id: 7,
                    number: 'PAY/202611/00007',
                    paymentType: 'disbursement',
                    customerId: null,
                    supplierId: 8,
                    paymentDate: '2026-11-04',
                    method: 'cash',
                    accountId: 2,
                    amount: '100.25',
                    reference: null,
                    notes: null,
                    allocations: [{ salesInvoiceId: null, purchaseInvoiceId: 1, amount: '100.25' }],
                },
            },
            global: { stubs },
        });

        expect(forms[0].supplier_id).toBe('8');
        expect(input(wrapper, 1).element.value).toBe('100.25');

        await wrapper.find('form').trigger('submit');

        expect(forms[0].put.mock.calls[0][0]).toBe('/accounting/payments/7');
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(PaymentCreate, { props: createProps(), attachTo: document.body, global: { stubs } });

        await expectNoViolations(wrapper.element);
        wrapper.unmount();
    });
});

describe('Disbursement detail', () => {
    it('names the supplier and links allocations to purchase invoices', () => {
        const wrapper = mount(PaymentShow, { props: { item: shownPayment() }, global: { stubs } });

        expect(wrapper.text()).toContain('Pemasok:');
        expect(wrapper.text()).toContain('SUP-1 - PT Pemasok Utama');
        expect(wrapper.text()).toContain('Pembayaran ke pemasok');
        expect(wrapper.find('a[href="/purchasing/invoices/5"]').text()).toBe('PI/202611/00005');
    });

    it('still links a receipt to its sales invoice and customer', () => {
        const wrapper = mount(PaymentShow, {
            props: {
                item: shownPayment({
                    paymentType: 'receipt',
                    supplier: null,
                    customer: { id: 3, code: 'CUST-1', name: 'PT Maju Jaya' },
                    allocations: [{ id: 1, salesInvoiceId: 9, purchaseInvoiceId: null, invoiceNumber: 'SI/202611/00009', amount: '1000.00' }],
                }),
            },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Pelanggan:');
        expect(wrapper.find('a[href="/sales/invoices/9"]').exists()).toBe(true);
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(PaymentShow, { props: { item: shownPayment() }, attachTo: document.body, global: { stubs } });

        await expectNoViolations(wrapper.element);
        wrapper.unmount();
    });
});

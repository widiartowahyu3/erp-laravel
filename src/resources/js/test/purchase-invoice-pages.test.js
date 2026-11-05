import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import axe from 'axe-core';
import InvoiceIndex from '../Pages/Purchasing/Invoices/Index.vue';
import InvoiceCreate from '../Pages/Purchasing/Invoices/Create.vue';
import InvoiceEdit from '../Pages/Purchasing/Invoices/Edit.vue';
import InvoiceShow from '../Pages/Purchasing/Invoices/Show.vue';

const ALL_PERMISSIONS = [
    'purchase_invoices.view',
    'purchase_invoices.create',
    'purchase_invoices.update',
    'purchase_invoices.post',
    'purchase_invoices.void',
    'journals.view',
];

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
        const form = reactive({
            ...initial,
            processing: false,
            errors: {},
            post: vi.fn(),
            put: vi.fn(),
        });
        forms.push(form);

        return form;
    },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
    router: { get: mockRouterGet, post: mockRouterPost, put: vi.fn(), delete: vi.fn() },
}));

// Only the layout is stubbed; every UI contract under test uses the real component.
const stubs = { AppLayout: { template: '<main><slot /></main>' } };

async function expectNoViolations(element) {
    const results = await axe.run(element, {
        rules: {
            'color-contrast': { enabled: false },
            region: { enabled: false },
        },
    });

    expect(results.violations.filter((v) => ['critical', 'serious'].includes(v.impact) || v.id === 'heading-order')).toEqual([]);
}

function listRow(overrides = {}) {
    return {
        id: 7,
        number: 'PI/202611/00007',
        supplierInvoiceNumber: 'INV-555',
        invoiceDate: '2026-11-03',
        dueDate: '2026-12-03',
        supplier: { id: 3, code: 'CUST-1', name: 'PT Maju Jaya' },
        purchaseOrderNumber: 'PO/202611/00001',
        total: '147645.55',
        paidAmount: '0.00',
        outstanding: '147645.55',
        status: 'posted',
        isOverdue: false,
        ...overrides,
    };
}

function listProps(rows = [listRow()]) {
    return {
        items: {
            data: rows,
            meta: { page: 1, per_page: 25, total: 60, last_page: 3, from: 1, to: 25 },
            query: { search: null, sort: null, direction: null, filter: {} },
        },
        suppliers: [{ id: 3, code: 'CUST-1', name: 'PT Maju Jaya' }],
        statuses: ['draft', 'posted', 'partially_paid', 'paid', 'void'],
    };
}

const purchaseOrder = {
    id: 11,
    number: 'PO/202611/00011',
    orderDate: '2026-11-01',
    supplier: { id: 3, code: 'CUST-1', name: 'PT Maju Jaya', address: 'Jl. Merdeka' },
    termDays: 30,
};

function eligible(overrides = {}) {
    return {
        goodsReceiptItemId: 21,
        goodsReceiptId: 5,
        goodsReceiptNumber: 'GR/202611/00005',
        productId: 9,
        productName: 'Beras 5 Kg',
        receivedQuantity: '10.0000',
        remainingQuantity: '6.0000',
        quantity: '6.0000',
        unitPrice: '1000.0000',
        discountAmount: '0.00',
        taxAmount: '0.00',
        lineTotal: '6000.00',
        ...overrides,
    };
}

function createProps(items = [eligible()]) {
    return {
        purchaseOrder,
        eligibleItems: items,
        defaults: { invoiceDate: '2026-11-05', dueDate: '2026-12-05' },
        billableOrders: [],
        filters: { search: '' },
    };
}

function invoice(overrides = {}) {
    return {
        id: 7,
        number: 'PI/202611/00007',
        supplierInvoiceNumber: 'INV-555',
        status: 'draft',
        invoiceDate: '2026-11-03',
        dueDate: '2026-12-03',
        isOverdue: false,
        subtotal: '135005.00',
        discountAmount: '1000.00',
        taxAmount: '13640.55',
        total: '147645.55',
        paidAmount: '0.00',
        outstanding: '147645.55',
        notes: null,
        supplier: { id: 3, code: 'CUST-1', name: 'PT Maju Jaya', address: 'Jl. Merdeka' },
        purchaseOrder: { id: 11, number: 'PO/202611/00011' },
        voidReason: null,
        items: [{
            id: 1,
            lineNo: 1,
            description: 'Beras 5 Kg',
            goodsReceiptId: 5,
            goodsReceiptNumber: 'GR/202611/00005',
            quantity: '10.0000',
            unitPrice: '12500.5000',
            discountAmount: '1000.00',
            taxRate: { code: 'PPN11', rate: '11.00' },
            taxAmount: '13640.55',
            lineTotal: '137645.55',
        }],
        ...overrides,
    };
}

function mountShow(item, permissions = ALL_PERMISSIONS, extra = {}) {
    mockPageProps.auth.permissions = permissions;

    return mount(InvoiceShow, { props: { item, journal: null, allocations: [], ...extra }, global: { stubs } });
}

const buttonLabels = (wrapper) => wrapper.findAll('button').map((button) => button.text().trim());

beforeEach(() => {
    vi.clearAllMocks();
    forms.length = 0;
    mockPageProps.auth.permissions = [...ALL_PERMISSIONS];
    mockPageProps.flash = {};
    mockPageProps.errors = {};
});

describe('Purchase invoice list', () => {
    it('renders real rows with amounts and the overdue mark', () => {
        const wrapper = mount(InvoiceIndex, {
            props: listProps([listRow({ isOverdue: true, paidAmount: '1000.00', outstanding: '146645.55' })]),
            global: { stubs },
        });
        const text = wrapper.text();

        expect(text).toContain('PI/202611/00007');
        expect(text).toContain('PT Maju Jaya');
        expect(text).toContain('147.645,55');
        expect(text).toContain('1.000');
        expect(text).toContain('146.645,55');
        expect(text).toContain('Lewat tempo');
        expect(text).not.toContain('Belum ada faktur pembelian');
    });

    it.each([
        ['draft', 'Draf'],
        ['posted', 'Diposting'],
        ['partially_paid', 'Dibayar Sebagian'],
        ['paid', 'Lunas'],
        ['void', 'Dibatalkan'],
    ])('labels status %s as %s through the real badge', (status, label) => {
        const wrapper = mount(InvoiceIndex, { props: listProps([listRow({ status })]), global: { stubs } });

        expect(wrapper.find('tbody').text()).toContain(label);
    });

    it('shows the empty state only without rows', () => {
        const wrapper = mount(InvoiceIndex, { props: listProps([]), global: { stubs } });

        expect(wrapper.text()).toContain('Belum ada faktur pembelian');
    });

    it('links Buat Faktur to the order picker only for those allowed to create', () => {
        const allowed = mount(InvoiceIndex, { props: listProps(), global: { stubs } });

        expect(allowed.find('a[href="/purchasing/invoices/create"]').exists()).toBe(true);

        mockPageProps.auth.permissions = ['purchase_invoices.view'];
        const denied = mount(InvoiceIndex, { props: listProps(), global: { stubs } });

        expect(denied.find('a[href="/purchasing/invoices/create"]').exists()).toBe(false);
    });

    it('offers editing only on drafts', () => {
        const wrapper = mount(InvoiceIndex, {
            props: listProps([listRow({ id: 1, status: 'draft' }), listRow({ id: 2, status: 'posted' })]),
            global: { stubs },
        });

        expect(wrapper.find('a[href="/purchasing/invoices/1/edit"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/purchasing/invoices/2/edit"]').exists()).toBe(false);
    });

    it.each([
        ['Filter berdasarkan status', 'posted', 'status'],
        ['Filter jatuh tempo', '1', 'overdue'],
        ['Filter berdasarkan pemasok', '3', 'supplier_id'],
    ])('sends %s to the server as filter[...]', async (label, value, key) => {
        const wrapper = mount(InvoiceIndex, { props: listProps(), global: { stubs } });

        await wrapper.find(`select[aria-label="${label}"]`).setValue(value);

        expect(mockRouterGet).toHaveBeenCalled();
        const [url, query] = mockRouterGet.mock.calls.at(-1);

        expect(url).toBe('/purchasing/invoices');
        expect(query[`filter[${key}]`] ?? query.filter?.[key]).toBe(value);
    });

    it('asks the server for the next page and for another page size', async () => {
        const wrapper = mount(InvoiceIndex, { props: listProps(), global: { stubs } });
        const pagination = wrapper.findComponent({ name: 'Pagination' });
        const target = pagination.exists() ? pagination : wrapper.findAllComponents({ __name: 'Pagination' })[0];

        await target.vm.$emit('change-page', 2);
        expect(mockRouterGet.mock.calls.at(-1)[1].page).toBe(2);

        await target.vm.$emit('change-per-page', 50);
        expect(mockRouterGet.mock.calls.at(-1)[1].per_page).toBe(50);
    });

    it('sends the search after the debounce', async () => {
        vi.useFakeTimers();

        try {
            const wrapper = mount(InvoiceIndex, { props: listProps(), global: { stubs } });

            await wrapper.find('input[placeholder^="Cari nomor faktur"]').setValue('SI/2026');
            vi.advanceTimersByTime(700);

            expect(mockRouterGet.mock.calls.at(-1)[1].search).toBe('SI/2026');
        } finally {
            vi.useRealTimers();
        }
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(InvoiceIndex, { props: listProps(), attachTo: document.body, global: { stubs } });

        await expectNoViolations(wrapper.element);
        wrapper.unmount();
    });
});

describe('Purchase invoice order picker', () => {
    const pickerProps = {
        purchaseOrder: null,
        eligibleItems: [],
        defaults: null,
        billableOrders: [{
            id: 11,
            number: 'PO/202611/00011',
            orderDate: '2026-11-01',
            status: 'delivered',
            total: '6000.00',
            supplier: { id: 3, code: 'CUST-1', name: 'PT Maju Jaya' },
        }],
        filters: { search: '' },
    };

    it('lists billable orders with a link that opens the form', () => {
        const wrapper = mount(InvoiceCreate, { props: pickerProps, global: { stubs } });

        expect(wrapper.text()).toContain('PO/202611/00011');
        expect(wrapper.text()).toContain('PT Maju Jaya');
        expect(wrapper.find('a[href="/purchasing/invoices/create?purchase_order_id=11"]').exists()).toBe(true);
    });

    it('explains when nothing can be billed', () => {
        const wrapper = mount(InvoiceCreate, { props: { ...pickerProps, billableOrders: [] }, global: { stubs } });

        expect(wrapper.text()).toContain('Tidak ada pesanan yang bisa ditagih');
    });

    it('shows the error flash from a refused order', () => {
        mockPageProps.flash = { error: 'Pesanan pembelian tidak ditemukan.' };

        const wrapper = mount(InvoiceCreate, { props: pickerProps, global: { stubs } });

        expect(wrapper.text()).toContain('Pesanan pembelian tidak ditemukan.');
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(InvoiceCreate, { props: pickerProps, attachTo: document.body, global: { stubs } });

        await expectNoViolations(wrapper.element);
        wrapper.unmount();
    });
});

describe('Purchase invoice form', () => {
    const fillSupplierNumber = async (wrapper) => {
        forms[0].supplier_invoice_number = 'INV-1';
        await wrapper.vm.$nextTick();
    };
    const inputFor = (wrapper, product = 'Beras 5 Kg') => wrapper.find(`input[aria-label^="Kuantitas ditagih untuk ${product}"]`);

    it('shows the delivery lines with the remainder as the default and no price inputs', () => {
        const wrapper = mount(InvoiceCreate, { props: createProps(), global: { stubs } });
        const row = wrapper.find('tbody tr');

        expect(row.text()).toContain('GR/202611/00005');
        expect(row.text()).toContain('Beras 5 Kg');
        expect(inputFor(wrapper).element.value).toBe('6');
        expect(forms[0].invoice_date).toBe('2026-11-05');
        expect(forms[0].due_date).toBe('2026-12-05');
        expect(wrapper.find('input[aria-label*="Harga"]').exists()).toBe(false);
        expect(wrapper.find('input[aria-label*="Diskon"]').exists()).toBe(false);
        expect(wrapper.find('input[aria-label*="Pajak"]').exists()).toBe(false);
    });

    it('submits only the lines with a quantity', async () => {
        const wrapper = mount(InvoiceCreate, {
            props: createProps([eligible(), eligible({ goodsReceiptItemId: 22, productName: 'Gula 1 Kg' })]),
            global: { stubs },
        });

        await fillSupplierNumber(wrapper);
        await inputFor(wrapper).setValue('2.5');
        await inputFor(wrapper, 'Gula 1 Kg').setValue('');
        await wrapper.find('form').trigger('submit');

        expect(forms[0].post).toHaveBeenCalledTimes(1);
        expect(forms[0].post.mock.calls[0][0]).toBe('/purchasing/invoices');
        expect(forms[0].items).toEqual([{ goods_receipt_item_id: 21, quantity: '2.5' }]);
        expect(forms[0].purchase_order_id).toBe(11);
    });

    it.each([
        ['more than the remainder', '6.0001', 'Melebihi sisa'],
        ['text instead of a number', 'abc', 'Isi dengan angka'],
        ['a comma as the separator', '1,5', 'Isi dengan angka'],
    ])('refuses %s in the browser', async (_case, value, message) => {
        const wrapper = mount(InvoiceCreate, { props: createProps(), global: { stubs } });

        await inputFor(wrapper).setValue(value);
        await wrapper.find('form').trigger('submit');

        expect(forms[0].post).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain(message);
    });

    it('refuses an invoice without a supplier invoice number and shows the server refusal on the field', async () => {
        const wrapper = mount(InvoiceCreate, { props: createProps(), global: { stubs } });

        await wrapper.find('form').trigger('submit');

        expect(forms[0].post).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Isi nomor faktur pemasok');

        forms[0].errors = { supplier_invoice_number: 'Nomor faktur pemasok sudah dipakai untuk pemasok ini.' };
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Nomor faktur pemasok sudah dipakai untuk pemasok ini.');
    });

    it('refuses an invoice without any quantity', async () => {
        const wrapper = mount(InvoiceCreate, { props: createProps(), global: { stubs } });

        await fillSupplierNumber(wrapper);
        await inputFor(wrapper).setValue('');
        await wrapper.find('form').trigger('submit');

        expect(forms[0].post).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('minimal satu baris');
    });

    it('refuses a due date before the invoice date', async () => {
        const wrapper = mount(InvoiceCreate, { props: createProps(), global: { stubs } });

        await fillSupplierNumber(wrapper);
        forms[0].due_date = '2026-11-01';
        await wrapper.find('form').trigger('submit');

        expect(forms[0].post).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Tanggal jatuh tempo tidak boleh sebelum');
    });

    it('shows server errors on the row they belong to, even with blank rows skipped', async () => {
        const wrapper = mount(InvoiceCreate, {
            props: createProps([eligible({ goodsReceiptItemId: 22, productName: 'Gula 1 Kg' }), eligible()]),
            global: { stubs },
        });

        await fillSupplierNumber(wrapper);
        await inputFor(wrapper, 'Gula 1 Kg').setValue('');
        await wrapper.find('form').trigger('submit');

        forms[0].errors = { 'items.0.quantity': 'Kuantitas ditolak server.', items: 'Melebihi sisa pada DO.' };
        await wrapper.vm.$nextTick();

        const [gula, beras] = wrapper.findAll('tbody tr');

        expect(gula.text()).not.toContain('Kuantitas ditolak server.');
        expect(beras.text()).toContain('Kuantitas ditolak server.');
        expect(wrapper.text()).toContain('Melebihi sisa pada DO.');
    });

    it('sends only once on a double submit', async () => {
        const wrapper = mount(InvoiceCreate, { props: createProps(), global: { stubs } });

        await fillSupplierNumber(wrapper);
        await wrapper.find('form').trigger('submit');
        await wrapper.find('form').trigger('submit');

        expect(forms[0].post).toHaveBeenCalledTimes(1);
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(InvoiceCreate, { props: createProps(), attachTo: document.body, global: { stubs } });

        await expectNoViolations(wrapper.element);
        wrapper.unmount();
    });

    it('edit starts from the saved quantities and updates the same invoice', async () => {
        const wrapper = mount(InvoiceEdit, {
            props: {
                item: { id: 7, number: 'PI/202611/00007', supplierInvoiceNumber: 'INV-777', invoiceDate: '2026-11-03', dueDate: '2026-11-10', notes: 'Catatan' },
                purchaseOrder,
                eligibleItems: [
                    eligible({ quantity: '2.0000' }),
                    eligible({ goodsReceiptItemId: 22, productName: 'Gula 1 Kg', quantity: '0.0000' }),
                ],
            },
            global: { stubs },
        });

        expect(inputFor(wrapper).element.value).toBe('2');
        expect(inputFor(wrapper, 'Gula 1 Kg').element.value).toBe('');
        expect(forms[0].notes).toBe('Catatan');
        expect(forms[0].supplier_invoice_number).toBe('INV-777');

        await wrapper.find('form').trigger('submit');

        expect(forms[0].put.mock.calls[0][0]).toBe('/purchasing/invoices/7');
        expect(forms[0].post).not.toHaveBeenCalled();
    });
});

describe('Purchase invoice detail', () => {
    it('shows the supplier invoice number', () => {
        expect(mountShow(invoice({ status: 'posted' })).text()).toContain('INV-555');
    });

    it('shows the header, lines, summary and the empty payment table', () => {
        const wrapper = mountShow(invoice({ status: 'posted', paidAmount: '1000.00', outstanding: '146645.55' }));
        const text = wrapper.text();

        expect(text).toContain('PI/202611/00007');
        expect(text).toContain('Diposting');
        expect(text).toContain('PT Maju Jaya');
        expect(text).toContain('Beras 5 Kg');
        expect(text).toContain('135.005');
        expect(text).toContain('13.640,55');
        expect(text).toContain('147.645,55');
        expect(text).toContain('146.645,55');
        expect(text).toContain('Belum ada pembayaran');
        expect(wrapper.find('a[href="/purchasing/goods-receipts/5"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/purchasing/purchase-orders/11"]').exists()).toBe(true);
    });

    it('lists allocations and links the journal', () => {
        const wrapper = mountShow(invoice({ status: 'posted' }), ALL_PERMISSIONS, {
            journal: { id: 44, number: 'JV/202611/00044', status: 'posted' },
            allocations: [{ paymentId: 1, number: 'PAY/202611/00001', paymentDate: '2026-11-04', status: 'posted', amount: '1000.00' }],
        });

        expect(wrapper.text()).toContain('PAY/202611/00001');
        expect(wrapper.text()).not.toContain('Belum ada pembayaran');
        expect(wrapper.find('a[href="/accounting/journals/44"]').exists()).toBe(true);
    });

    it.each([
        ['draft', ['Ubah', 'Posting', 'Batalkan']],
        ['posted', ['Batalkan']],
        ['partially_paid', ['Batalkan']],
        ['paid', ['Batalkan']],
        ['void', []],
    ])('offers the right actions on status %s', (status, expected) => {
        const paid = ['partially_paid', 'paid'].includes(status) ? '1000.00' : '0.00';
        const labels = buttonLabels(mountShow(invoice({ status, paidAmount: paid })));

        for (const action of ['Ubah', 'Posting', 'Batalkan']) {
            expect(labels.includes(action)).toBe(expected.includes(action));
        }
    });

    it('hides every action without permission', () => {
        const labels = buttonLabels(mountShow(invoice({ status: 'draft' }), ['purchase_invoices.view']));

        expect(labels).not.toContain('Ubah');
        expect(labels).not.toContain('Posting');
        expect(labels).not.toContain('Batalkan');
    });

    it('disables voiding with an explanation once money was received', () => {
        const wrapper = mountShow(invoice({ status: 'partially_paid', paidAmount: '1000.00' }));
        const button = wrapper.findAll('button').find((b) => b.text().trim() === 'Batalkan');

        expect(button.attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('batalkan pembayaran terlebih dahulu');
    });

    it('posts after confirmation, and only once on a double click', async () => {
        mockConfirm.mockResolvedValue(true);
        const wrapper = mountShow(invoice({ status: 'draft' }));
        const button = wrapper.findAll('button').find((b) => b.text().trim() === 'Posting');

        await button.trigger('click');
        await flushPromises();
        await button.trigger('click');
        await flushPromises();

        expect(mockRouterPost).toHaveBeenCalledTimes(1);
        expect(mockRouterPost.mock.calls[0][0]).toBe('/purchasing/invoices/7/post');
    });

    it('does nothing when the confirmation is declined', async () => {
        mockConfirm.mockResolvedValue(false);
        const wrapper = mountShow(invoice({ status: 'draft' }));

        await wrapper.findAll('button').find((b) => b.text().trim() === 'Posting').trigger('click');
        await flushPromises();

        expect(mockRouterPost).not.toHaveBeenCalled();
    });

    it('voids with the reason from the dialog', async () => {
        mockConfirm.mockResolvedValue({ confirmed: true, reason: 'Salah tagih' });
        const wrapper = mountShow(invoice({ status: 'posted' }));

        await wrapper.findAll('button').find((b) => b.text().trim() === 'Batalkan').trigger('click');
        await flushPromises();

        expect(mockConfirm.mock.calls[0][0].requiresReason).toBe(true);
        expect(mockRouterPost.mock.calls[0][0]).toBe('/purchasing/invoices/7/void');
        expect(mockRouterPost.mock.calls[0][1]).toEqual({ reason: 'Salah tagih' });
    });

    it('shows server refusals and the void reason', () => {
        mockPageProps.errors = { items: 'Kuantitas melebihi sisa.' };

        const wrapper = mountShow(invoice({ status: 'void', voidReason: 'Salah harga' }));

        expect(wrapper.text()).toContain('Kuantitas melebihi sisa.');
        expect(wrapper.text()).toContain('Salah harga');
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(InvoiceShow, {
            props: { item: invoice({ status: 'posted' }), journal: { id: 44, number: 'JV/202611/00044', status: 'posted' }, allocations: [] },
            attachTo: document.body,
            global: { stubs },
        });

        await expectNoViolations(wrapper.element);
        wrapper.unmount();
    });
});

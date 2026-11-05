import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import axe from 'axe-core';
import PurchaseOrderShow from '../Pages/Purchasing/PurchaseOrders/Show.vue';
import GoodsReceiptShow from '../Pages/Purchasing/GoodsReceipts/Show.vue';
import PurchaseInvoiceShow from '../Pages/Purchasing/Invoices/Show.vue';
import Dashboard from '../Pages/Dashboard.vue';

const { mockPageProps } = vi.hoisted(() => ({
    mockPageProps: { auth: { user: { id: 1, name: 'Staf' }, permissions: [] }, flash: {}, errors: {} },
}));

vi.mock('@/composables/useConfirm', () => ({
    useConfirm: () => ({ confirm: vi.fn() }),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockPageProps }),
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

const stubs = { AppLayout: { template: '<main><slot /></main>' } };

const order = {
    id: 11,
    number: 'PO/202611/00011',
    status: 'received',
    order_date: '2026-11-01',
    subtotal: '0.00',
    discount_amount: '0.00',
    tax_amount: '0.00',
    total: '0.00',
    supplier: { id: 8, code: 'SUP-1', name: 'PT Pemasok Utama' },
    warehouse: { id: 1, code: 'WH', name: 'Gudang' },
    items: [],
    goods_receipts: [],
};

const receipt = {
    id: 5,
    number: 'GR/202611/00005',
    status: 'posted',
    purchase_order_id: 11,
    purchase_order: { id: 11, number: 'PO/202611/00011' },
    receipt_date: '2026-11-01',
    supplier: { id: 8, code: 'SUP-1', name: 'PT Pemasok Utama' },
    warehouse: { id: 1, code: 'WH', name: 'Gudang' },
    items: [],
};

const invoice = (status) => ({
    id: 7,
    number: 'PI/202611/00007',
    supplierInvoiceNumber: 'INV-555',
    status,
    invoiceDate: '2026-11-03',
    dueDate: '2026-12-03',
    isOverdue: false,
    subtotal: '100.00',
    discountAmount: '0.00',
    taxAmount: '0.00',
    total: '100.00',
    paidAmount: ['partially_paid', 'paid'].includes(status) ? '50.00' : '0.00',
    outstanding: '100.00',
    notes: null,
    supplier: { id: 8, code: 'SUP-1', name: 'PT Pemasok Utama' },
    purchaseOrder: { id: 11, number: 'PO/202611/00011' },
    voidReason: null,
    items: [],
});

beforeEach(() => {
    mockPageProps.auth.permissions = ['purchase_orders.view', 'goods_receipts.view', 'purchase_invoices.view', 'purchase_invoices.create', 'payments.create'];
});

describe('Buat Faktur Pembelian shortcut', () => {
    const href = 'a[href="/purchasing/invoices/create?purchase_order_id=11"]';

    it.each([
        ['purchase order', PurchaseOrderShow, order],
        ['goods receipt', GoodsReceiptShow, receipt],
    ])('appears on the %s only with something to bill and the permission', (_name, page, item) => {
        const render = (hasBillableItems) => mount(page, { props: { item, hasBillableItems }, global: { stubs } });

        expect(render(true).find(href).exists()).toBe(true);
        expect(render(false).find(href).exists()).toBe(false);

        mockPageProps.auth.permissions = ['purchase_orders.view', 'goods_receipts.view'];

        expect(render(true).find(href).exists()).toBe(false);
    });
});

describe('Bayar Pemasok shortcut', () => {
    const href = 'a[href="/accounting/payments/create?payment_type=disbursement&supplier_id=8"]';

    it.each([
        ['draft', false],
        ['posted', true],
        ['partially_paid', true],
        ['paid', false],
        ['void', false],
    ])('on status %s: %s', (status, expected) => {
        const render = () => mount(PurchaseInvoiceShow, { props: { item: invoice(status), journal: null, allocations: [] }, global: { stubs } });

        expect(render().find(href).exists()).toBe(expected);

        mockPageProps.auth.permissions = ['purchase_invoices.view'];

        expect(render().find(href).exists()).toBe(false);
    });

    it('links each allocation to its payment', () => {
        const wrapper = mount(PurchaseInvoiceShow, {
            props: {
                item: invoice('posted'),
                journal: null,
                allocations: [{ paymentId: 5, number: 'PAY/202611/00005', paymentDate: '2026-11-04', status: 'posted', amount: '50.00' }],
            },
            global: { stubs },
        });

        expect(wrapper.find('a[href="/accounting/payments/5"]').text()).toBe('PAY/202611/00005');
    });
});

describe('Payable cards on the dashboard', () => {
    const dashboardProps = {
        purchaseOrders: null,
        goodsReceipts: null,
        salesOrders: null,
        deliveryOrders: null,
        salesInvoices: null,
        purchaseInvoices: { outstanding_total: '147645.55', overdue_count: 3 },
        stockBalances: null,
        stockMovements: null,
    };

    it('shows the amounts and links with the filter[...] shape', () => {
        const wrapper = mount(Dashboard, { props: dashboardProps, global: { stubs } });

        expect(wrapper.text()).toContain('Utang Beredar');
        expect(wrapper.text()).toContain('147.645,55');
        expect(wrapper.text()).toContain('Faktur Pembelian Jatuh Tempo');
        expect(wrapper.find('a[href="/purchasing/invoices?filter[status]=posted"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/purchasing/invoices?filter[overdue]=1"]').exists()).toBe(true);
    });

    it('hides the cards without the permission', () => {
        const wrapper = mount(Dashboard, { props: { ...dashboardProps, purchaseInvoices: null }, global: { stubs } });

        expect(wrapper.text()).not.toContain('Utang Beredar');
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(Dashboard, { props: dashboardProps, attachTo: document.body, global: { stubs } });
        const results = await axe.run(wrapper.element, {
            rules: { 'color-contrast': { enabled: false }, region: { enabled: false } },
        });

        expect(results.violations.filter((v) => ['critical', 'serious'].includes(v.impact) || v.id === 'heading-order')).toEqual([]);
        wrapper.unmount();
    });
});

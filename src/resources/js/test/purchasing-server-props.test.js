import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import PurchaseOrderShow from '../Pages/Purchasing/PurchaseOrders/Show.vue';
import PurchaseOrderEdit from '../Pages/Purchasing/PurchaseOrders/Edit.vue';
import GoodsReceiptShow from '../Pages/Purchasing/GoodsReceipts/Show.vue';
import GoodsReceiptEdit from '../Pages/Purchasing/GoodsReceipts/Edit.vue';
import GoodsReceiptCreate from '../Pages/Purchasing/GoodsReceipts/Create.vue';

// These pages are mounted with exactly the prop names the controllers send.

const { mockPageProps, lastForm } = vi.hoisted(() => ({
    mockPageProps: {
        auth: { user: { id: 1, name: 'Admin' }, permissions: ['goods_receipts.create', 'purchase_orders.view', 'goods_receipts.view'] },
        flash: {},
        errors: {},
    },
    lastForm: { current: null },
}));

vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: vi.fn() }) }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockPageProps }),
    useForm: (initialData) => {
        const form = reactive({ ...initialData, processing: false, errors: {}, hasErrors: false, post: vi.fn(), put: vi.fn() });
        lastForm.current = form;

        return form;
    },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

const stubs = { AppLayout: { template: '<main><slot /></main>' } };

const orderItem = {
    id: 21, line_no: 1, product_id: 5, quantity: '10.0000', received_quantity: '4.0000', unit_price: '950.0000',
    discount_amount: '0.00', tax_rate_id: null, tax_amount: '0.00', line_total: '9500.00',
    product: { id: 5, sku: 'MIE-001', name: 'Indomie Goreng', base_unit: { code: 'CTN' } },
};

const purchaseOrder = {
    id: 9, number: 'PO/202611/00009', status: 'partially_received', supplier_id: 3, warehouse_id: 4,
    order_date: '2026-11-01', subtotal: '9500.00', discount_amount: '0.00', tax_amount: '0.00', total: '9500.00',
    supplier: { id: 3, code: 'SUP-1', name: 'PT Sumber Pangan' },
    warehouse: { id: 4, code: 'GD-JKT', name: 'Gudang Jakarta' },
    items: [orderItem],
    goods_receipts: [],
};

const goodsReceipt = {
    id: 12, number: 'GR/202611/00012', status: 'draft', purchase_order_id: 9, supplier_id: 3, warehouse_id: 4,
    receipt_date: '2026-11-02', notes: null,
    purchase_order: purchaseOrder,
    supplier: purchaseOrder.supplier,
    warehouse: purchaseOrder.warehouse,
    items: [{ id: 31, line_no: 1, purchase_order_item_id: 21, product_id: 5, quantity: '2.0000', unit_cost: '950.0000', product: orderItem.product, purchase_order_item: orderItem }],
};

describe('Purchasing pages receive the props the controllers really send', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('PurchaseOrders/Show renders from the item prop', () => {
        const wrapper = mount(PurchaseOrderShow, { props: { item: purchaseOrder }, global: { stubs } });

        expect(wrapper.text()).toContain('PO/202611/00009');
        expect(wrapper.text()).toContain('PT Sumber Pangan');
    });

    it('PurchaseOrders/Edit renders from the item prop', () => {
        const wrapper = mount(PurchaseOrderEdit, {
            props: { item: purchaseOrder, suppliers: [], warehouses: [], paymentTerms: [], taxRates: [], products: [] },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('PO/202611/00009');
        expect(lastForm.current.supplier_id).toBe('3');
    });

    it('GoodsReceipts/Show renders from the item prop', () => {
        const wrapper = mount(GoodsReceiptShow, { props: { item: goodsReceipt }, global: { stubs } });

        expect(wrapper.text()).toContain('GR/202611/00012');
    });

    it('GoodsReceipts/Edit renders from the item prop', () => {
        const wrapper = mount(GoodsReceiptEdit, { props: { item: goodsReceipt }, global: { stubs } });

        expect(wrapper.text()).toContain('GR/202611/00012');
        expect(lastForm.current.items).toHaveLength(1);
    });

    it('GoodsReceipts/Create preselects the order sent by the server and loads its remaining items', async () => {
        const wrapper = mount(GoodsReceiptCreate, {
            props: { purchaseOrder, eligibleItems: [orderItem] },
            global: { stubs },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('PO/202611/00009');
        expect(lastForm.current.purchase_order_id).toBe('9');
        expect(lastForm.current.items).toHaveLength(1);
        expect(lastForm.current.items[0].purchase_order_item_id).toBe('21');
        expect(lastForm.current.items[0].quantity).toBe('6');
    });

    it('GoodsReceipts form sends the supplier and warehouse the server requires', async () => {
        const wrapper = mount(GoodsReceiptCreate, {
            props: { purchaseOrder, eligibleItems: [orderItem] },
            global: { stubs },
        });
        await flushPromises();

        await wrapper.find('form').trigger('submit');

        expect(lastForm.current.post.mock.calls[0][0]).toBe('/purchasing/goods-receipts');
        expect(lastForm.current.supplier_id).toBe('3');
        expect(lastForm.current.warehouse_id).toBe('4');
    });
});

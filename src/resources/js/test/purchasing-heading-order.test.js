import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import axe from 'axe-core';
import PurchaseOrderIndex from '../Pages/Purchasing/PurchaseOrders/Index.vue';
import PurchaseOrderShow from '../Pages/Purchasing/PurchaseOrders/Show.vue';
import PurchaseOrderEdit from '../Pages/Purchasing/PurchaseOrders/Edit.vue';
import GoodsReceiptIndex from '../Pages/Purchasing/GoodsReceipts/Index.vue';
import GoodsReceiptShow from '../Pages/Purchasing/GoodsReceipts/Show.vue';
import GoodsReceiptEdit from '../Pages/Purchasing/GoodsReceipts/Edit.vue';

const { mockPageProps } = vi.hoisted(() => ({
    mockPageProps: {
        auth: { user: { id: 1, name: 'Admin' }, permissions: ['purchase_orders.view', 'goods_receipts.view', 'goods_receipts.create'] },
        flash: {},
        errors: {},
    },
}));

vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: vi.fn() }) }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockPageProps }),
    useForm: (initialData) => reactive({ ...initialData, processing: false, errors: {}, hasErrors: false, post: vi.fn(), put: vi.fn() }),
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

const stubs = { AppLayout: { template: '<main><slot /></main>' } };
const meta = { page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 };

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

async function headingOrderViolations(component, props) {
    const wrapper = mount(component, { props, global: { stubs }, attachTo: document.body });
    const results = await axe.run(wrapper.element, {
        rules: { 'color-contrast': { enabled: false }, region: { enabled: false } },
    });
    wrapper.unmount();

    return results.violations.filter((v) => ['critical', 'serious'].includes(v.impact) || v.id === 'heading-order').map((v) => v.id);
}

describe('Purchasing pages have a valid heading order', () => {
    it.each([
        ['PurchaseOrders/Index with rows', PurchaseOrderIndex, { items: { data: [purchaseOrder], meta, query: { filter: {} } } }],
        ['PurchaseOrders/Index when empty', PurchaseOrderIndex, { items: { data: [], meta: { ...meta, total: 0, from: null, to: null }, query: { filter: {} } } }],
        ['PurchaseOrders/Show', PurchaseOrderShow, { item: purchaseOrder }],
        ['PurchaseOrders/Form', PurchaseOrderEdit, { item: purchaseOrder, suppliers: [], warehouses: [], paymentTerms: [], taxRates: [], products: [] }],
        ['GoodsReceipts/Index with rows', GoodsReceiptIndex, { items: { data: [goodsReceipt], meta, query: { filter: {} } } }],
        ['GoodsReceipts/Show', GoodsReceiptShow, { item: goodsReceipt }],
        ['GoodsReceipts/Form', GoodsReceiptEdit, { item: goodsReceipt, purchaseOrders: [purchaseOrder], warehouses: [], suppliers: [] }],
    ])('%s', async (_name, component, props) => {
        expect(await headingOrderViolations(component, props)).toEqual([]);
    });
});

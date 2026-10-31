import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import PurchaseOrderShow from '../Pages/Purchasing/PurchaseOrders/Show.vue';
import GoodsReceiptIndex from '../Pages/Purchasing/GoodsReceipts/Index.vue';
import GoodsReceiptForm from '../Pages/Purchasing/GoodsReceipts/Form.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            auth: {
                user: { id: 1, name: 'Admin', role: 'admin' },
                permissions: ['purchase_orders.create_receipt', 'purchase_orders.view', 'goods_receipts.create', 'goods_receipts.view'],
            },
            flash: {},
            errors: {},
        },
    }),
    useForm: (initialData) => ({
        ...initialData,
        processing: false,
        errors: {},
        post: vi.fn(),
        put: vi.fn(),
        reset: vi.fn(),
    }),
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    Head: { name: 'Head', template: '<head-stub><slot /></head-stub>' },
    router: {
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

const defaultStubs = {
    AppLayout: { template: '<div><slot /></div>' },
    PageHeader: { template: '<div><slot /><slot name="actions" /></div>' },
    Breadcrumbs: { template: '<nav><slot /></nav>' },
    Can: { template: '<div><slot /></div>' },
    Card: { template: '<div><slot /></div>' },
    Button: { template: '<button><slot /></button>' },
    IconButton: { template: '<button><slot /></button>' },
    StatusBadge: { template: '<span><slot /></span>' },
    DateDisplay: { template: '<span><slot /></span>' },
    Quantity: { template: '<span><slot /></span>' },
    Rupiah: { template: '<span><slot /></span>' },
    Alert: { template: '<div><slot /></div>' },
    DataTable: { template: '<div><slot /></div>' },
    FilterBar: { template: '<div><slot /></div>' },
    Pagination: { template: '<div><slot /></div>' },
    Select: { template: '<select><slot /></select>' },
    TextInput: { template: '<input />' },
    Textarea: { template: '<textarea></textarea>' },
};

describe('PO to GR Navigation and Linking (Butir 9a)', () => {
    it('PurchaseOrders/Show renders link with purchase_order_id query param', () => {
        const order = {
            id: 42,
            number: 'PO/202610/00042',
            order_date: '2026-10-31',
            status: 'approved',
            subtotal: '10000.00',
            tax_total: '0.00',
            grand_total: '10000.00',
            creator: { name: 'Admin' },
            items: [
                {
                    id: 101,
                    product_id: 1,
                    quantity: '10.0000',
                    received_quantity: '0.0000',
                    unit_price: '1000.0000',
                    line_total: '10000.00',
                    product: { id: 1, sku: 'SKU1', name: 'Item 1' },
                },
            ],
        };

        const wrapper = mount(PurchaseOrderShow, {
            props: { order },
            global: { stubs: defaultStubs },
        });

        const receiptLink = wrapper.find('a[href*="goods-receipts/create"]');
        expect(receiptLink.exists()).toBe(true);
        expect(receiptLink.attributes('href')).toBe('/purchasing/goods-receipts/create?purchase_order_id=42');
    });

    it('GoodsReceipts/Index guides user to PO list to create receipt from approved PO', () => {
        const wrapper = mount(GoodsReceiptIndex, {
            props: {
                items: {
                    data: [],
                    meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 },
                    query: {},
                },
                suppliers: [],
                warehouses: [],
                statuses: [],
            },
            global: { stubs: defaultStubs },
        });

        // The button should direct to purchase orders to select an approved PO
        const actionLink = wrapper.find('a[href*="/purchasing/purchase-orders"]');
        expect(actionLink.exists()).toBe(true);
    });
});

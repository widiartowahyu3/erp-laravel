import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import PurchaseOrderShow from '../Pages/Purchasing/PurchaseOrders/Show.vue';
import PurchaseOrderForm from '../Pages/Purchasing/PurchaseOrders/Form.vue';
import GoodsReceiptShow from '../Pages/Purchasing/GoodsReceipts/Show.vue';
import GoodsReceiptForm from '../Pages/Purchasing/GoodsReceipts/Form.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            auth: {
                user: { id: 1, name: 'Admin', role: 'admin' },
                permissions: [
                    'purchase_orders.view',
                    'purchase_orders.create',
                    'purchase_orders.edit',
                    'purchase_orders.approve',
                    'purchase_orders.create_receipt',
                    'goods_receipts.view',
                    'goods_receipts.create',
                    'goods_receipts.post',
                    'goods_receipts.void',
                ],
            },
            flash: {},
            errors: {},
        },
    }),
    useForm: (initialData) => ({
        ...initialData,
        data: () => initialData,
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

// Notice: Card is intentionally NOT stubbed so the real Card component renders!
const defaultStubs = {
    AppLayout: { template: '<div><slot /></div>' },
    PageHeader: { template: '<div><slot /><slot name="actions" /></div>' },
    Breadcrumbs: { template: '<nav><slot /></nav>' },
    Can: { template: '<div><slot /></div>' },
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
    FormField: { template: '<div><slot /></div>' },
};

describe('Purchasing Card Header Rendering (Butir 2)', () => {
    it('PurchaseOrders/Show renders card titles through real Card header slot', () => {
        const order = {
            id: 42,
            number: 'PO/202611/00042',
            order_date: '2026-11-01',
            status: 'approved',
            subtotal: '10000.00',
            tax_total: '0.00',
            grand_total: '10000.00',
            supplier: { code: 'SUP-01', name: 'PT Vendor Utama' },
            warehouse: { code: 'WH-01', name: 'Gudang Pusat' },
            creator: { name: 'Admin' },
            items: [],
            goods_receipts: [],
        };

        const wrapper = mount(PurchaseOrderShow, {
            props: { order },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Informasi Pengadaan');
        expect(wrapper.text()).toContain('Ringkasan Biaya');
        expect(wrapper.text()).toContain('Daftar Barang Pesanan');
        expect(wrapper.text()).toContain('Riwayat Penerimaan Barang (GR)');
    });

    it('PurchaseOrders/Form renders card titles through real Card header slot', () => {
        const wrapper = mount(PurchaseOrderForm, {
            props: {
                order: null,
                suppliers: [{ id: 1, code: 'SUP-01', name: 'PT Vendor Utama' }],
                warehouses: [{ id: 1, code: 'WH-01', name: 'Gudang Pusat' }],
                products: [{ id: 1, sku: 'SKU1', name: 'Barang 1' }],
                paymentTerms: [],
                taxRates: [],
                isEdit: false,
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Informasi Pesanan Pembelian');
        expect(wrapper.text()).toContain('Rincian Barang yang Dipesan');
    });

    it('GoodsReceipts/Show renders card titles through real Card header slot', () => {
        const receipt = {
            id: 1,
            number: 'GR/202611/00001',
            receipt_date: '2026-11-01',
            status: 'draft',
            purchase_order: { id: 42, number: 'PO/202611/00042' },
            supplier: { code: 'SUP-01', name: 'PT Vendor Utama' },
            warehouse: { code: 'WH-01', name: 'Gudang Pusat' },
            items: [],
        };

        const wrapper = mount(GoodsReceiptShow, {
            props: { receipt },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Informasi Penerimaan');
        expect(wrapper.text()).toContain('Rincian Barang yang Diterima');
    });

    it('GoodsReceipts/Form renders card titles through real Card header slot', () => {
        const wrapper = mount(GoodsReceiptForm, {
            props: {
                receipt: null,
                purchaseOrders: [
                    {
                        id: 42,
                        number: 'PO/202611/00042',
                        supplier: { name: 'PT Vendor Utama' },
                        warehouse: { name: 'Gudang Pusat' },
                        items: [],
                    },
                ],
                isEdit: false,
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Informasi Penerimaan Barang');
        expect(wrapper.text()).toContain('Rincian Barang yang Diterima');
    });
});

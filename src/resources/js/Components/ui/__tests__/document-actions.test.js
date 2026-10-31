import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { setMockPageProps, resetMockPageProps } from '@/test-setup';
import StockAdjustmentShow from '@/Pages/Inventory/StockAdjustments/Show.vue';
import PurchaseOrderShow from '@/Pages/Purchasing/PurchaseOrders/Show.vue';
import PurchaseOrderForm from '@/Pages/Purchasing/PurchaseOrders/Form.vue';
import GoodsReceiptShow from '@/Pages/Purchasing/GoodsReceipts/Show.vue';

// Mock useConfirm to automatically confirm actions
const mockConfirm = vi.fn();
vi.mock('@/composables/useConfirm', () => ({
    useConfirm: () => ({
        confirm: mockConfirm,
    }),
}));

describe('Document Action Double-Click Protection', () => {
    beforeEach(() => {
        resetMockPageProps();
        vi.clearAllMocks();
    });

    it('prevents double click on StockAdjustments/Show post action', async () => {
        mockConfirm.mockResolvedValue(true);
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Admin' },
                permissions: ['stock_adjustments.post', 'stock_adjustments.view'],
                roles: ['super_admin'],
            },
        });

        const wrapper = mount(StockAdjustmentShow, {
            props: {
                item: {
                    id: 10,
                    number: 'SA/202610/00001',
                    status: 'draft',
                    warehouse: { name: 'Gudang Utama' },
                    items: [],
                },
            },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        const postButton = wrapper.findAll('button').find((b) => b.text().includes('Posting Stok'));
        expect(postButton).toBeDefined();

        await postButton.trigger('click');
        await postButton.trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/inventory/stock-adjustments/10/post',
            {},
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it('prevents double click on PurchaseOrders/Show approve action', async () => {
        mockConfirm.mockResolvedValue(true);
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Admin' },
                permissions: ['purchase_orders.approve', 'purchase_orders.view'],
                roles: ['super_admin'],
            },
        });

        const wrapper = mount(PurchaseOrderShow, {
            props: {
                order: {
                    id: 20,
                    number: 'PO/202610/00001',
                    status: 'draft',
                    supplier: { name: 'PT Supplier' },
                    warehouse: { name: 'Gudang Utama' },
                    items: [],
                },
            },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        const approveButton = wrapper.findAll('button').find((b) => b.text().includes('Setujui PO'));
        expect(approveButton).toBeDefined();

        await approveButton.trigger('click');
        await approveButton.trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/purchasing/purchase-orders/20/approve',
            {},
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it('prevents double click on GoodsReceipts/Show post action', async () => {
        mockConfirm.mockResolvedValue(true);
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Admin' },
                permissions: ['goods_receipts.post', 'goods_receipts.view'],
                roles: ['super_admin'],
            },
        });

        const wrapper = mount(GoodsReceiptShow, {
            props: {
                receipt: {
                    id: 30,
                    number: 'GR/202610/00001',
                    status: 'draft',
                    warehouse: { name: 'Gudang Utama' },
                    purchase_order: { number: 'PO/202610/00001' },
                    items: [],
                },
            },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        const postButton = wrapper.findAll('button').find((b) => b.text().includes('Posting Stok'));
        expect(postButton).toBeDefined();

        await postButton.trigger('click');
        await postButton.trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/purchasing/goods-receipts/30/post',
            {},
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it('prevents double click on PurchaseOrders/Show close action', async () => {
        mockConfirm.mockResolvedValue(true);
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Admin' },
                permissions: ['purchase_orders.update', 'purchase_orders.view'],
                roles: ['super_admin'],
            },
        });

        const wrapper = mount(PurchaseOrderShow, {
            props: {
                order: {
                    id: 21,
                    number: 'PO/202610/00021',
                    status: 'partially_received',
                    supplier: { name: 'PT Supplier' },
                    warehouse: { name: 'Gudang Utama' },
                    items: [],
                },
            },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        const closeButton = wrapper.findAll('button').find((b) => b.text().includes('Tutup PO'));
        expect(closeButton).toBeDefined();

        await closeButton.trigger('click');
        await closeButton.trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/purchasing/purchase-orders/21/close',
            {},
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it('prevents double click on PurchaseOrders/Show void action', async () => {
        mockConfirm.mockResolvedValue({ confirmed: true, reason: 'Salah pemesanan barang' });
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Admin' },
                permissions: ['purchase_orders.void', 'purchase_orders.view'],
                roles: ['super_admin'],
            },
        });

        const wrapper = mount(PurchaseOrderShow, {
            props: {
                order: {
                    id: 22,
                    number: 'PO/202610/00022',
                    status: 'draft',
                    supplier: { name: 'PT Supplier' },
                    warehouse: { name: 'Gudang Utama' },
                    items: [],
                },
            },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        const voidButton = wrapper.findAll('button').find((b) => b.text().includes('Batalkan'));
        expect(voidButton).toBeDefined();

        await voidButton.trigger('click');
        await voidButton.trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/purchasing/purchase-orders/22/void',
            { reason: 'Salah pemesanan barang' },
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it('prevents double click on GoodsReceipts/Show void action', async () => {
        mockConfirm.mockResolvedValue({ confirmed: true, reason: 'Barang rusak saat pengiriman' });
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Admin' },
                permissions: ['goods_receipts.void', 'goods_receipts.view'],
                roles: ['super_admin'],
            },
        });

        const wrapper = mount(GoodsReceiptShow, {
            props: {
                receipt: {
                    id: 31,
                    number: 'GR/202610/00031',
                    status: 'draft',
                    warehouse: { name: 'Gudang Utama' },
                    purchase_order: { number: 'PO/202610/00031' },
                    items: [],
                },
            },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        const voidButton = wrapper.findAll('button').find((b) => b.text().includes('Batalkan'));
        expect(voidButton).toBeDefined();

        await voidButton.trigger('click');
        await voidButton.trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/purchasing/goods-receipts/31/void',
            { reason: 'Barang rusak saat pengiriman' },
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it('prevents double click on StockAdjustments/Show void action', async () => {
        mockConfirm.mockResolvedValue({ confirmed: true, reason: 'Koreksi salah hitung opname' });
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Admin' },
                permissions: ['stock_adjustments.void', 'stock_adjustments.view'],
                roles: ['super_admin'],
            },
        });

        const wrapper = mount(StockAdjustmentShow, {
            props: {
                item: {
                    id: 11,
                    number: 'SA/202610/00011',
                    status: 'draft',
                    warehouse: { name: 'Gudang Utama' },
                    items: [],
                },
            },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        const voidButton = wrapper.findAll('button').find((b) => b.text().includes('Batalkan'));
        expect(voidButton).toBeDefined();

        await voidButton.trigger('click');
        await voidButton.trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/inventory/stock-adjustments/11/void',
            { reason: 'Koreksi salah hitung opname' },
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it('renders discount error message in PurchaseOrders/Form when discount validation fails', async () => {
        const wrapper = mount(PurchaseOrderForm, {
            props: {
                suppliers: [{ id: 1, code: 'S1', name: 'Supplier 1' }],
                warehouses: [{ id: 1, code: 'W1', name: 'Gudang 1' }],
                products: [{ id: 10, sku: 'SKU10', name: 'Produk 10', base_unit: { code: 'PCS' } }],
                taxRates: [],
            },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                },
            },
        });

        // Add an item row first if empty
        if (wrapper.vm.form.items.length === 0) {
            wrapper.vm.addItem();
            await wrapper.vm.$nextTick();
        }

        wrapper.vm.form.errors['items.0.discount_percent'] = 'Diskon baris tidak boleh melebihi nilai barang.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Diskon baris tidak boleh melebihi nilai barang.');
    });
});

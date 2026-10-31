import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { setMockPageProps, resetMockPageProps } from '@/test-setup';
import StockAdjustmentShow from '@/Pages/Inventory/StockAdjustments/Show.vue';
import PurchaseOrderShow from '@/Pages/Purchasing/PurchaseOrders/Show.vue';
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
});

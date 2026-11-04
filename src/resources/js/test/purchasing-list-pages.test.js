import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import PurchaseOrderIndex from '../Pages/Purchasing/PurchaseOrders/Index.vue';
import GoodsReceiptIndex from '../Pages/Purchasing/GoodsReceipts/Index.vue';

// The lists use the real DataTable and FilterBar, so a wrong prop or slot name shows up as missing content.

const { mockPageProps, mockRouterGet } = vi.hoisted(() => ({
    mockPageProps: {
        auth: { user: { id: 1, name: 'Admin' }, permissions: ['purchase_orders.view', 'goods_receipts.view'] },
        flash: {},
        errors: {},
    },
    mockRouterGet: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockPageProps }),
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
    router: { get: mockRouterGet, post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

const stubs = { AppLayout: { template: '<main><slot /></main>' } };
const meta = { page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 };
const supplier = { id: 3, code: 'SUP-1', name: 'PT Sumber Pangan' };
const warehouse = { id: 4, code: 'GD-JKT', name: 'Gudang Jakarta' };

const orders = {
    items: {
        data: [{ id: 9, number: 'PO/202611/00009', status: 'approved', order_date: '2026-11-01', total: '9500.00', supplier, warehouse }],
        meta,
        query: { filter: {} },
    },
    suppliers: [supplier],
    warehouses: [warehouse],
    statuses: ['draft', 'approved'],
};

const receipts = {
    items: {
        data: [{ id: 12, number: 'GR/202611/00012', status: 'draft', receipt_date: '2026-11-02', items_count: 1, supplier, warehouse, purchase_order: { id: 9, number: 'PO/202611/00009' } }],
        meta,
        query: { filter: {} },
    },
    suppliers: [supplier],
    warehouses: [warehouse],
    statuses: ['draft', 'posted'],
};

describe.each([
    ['PurchaseOrders/Index', PurchaseOrderIndex, orders, 'PO/202611/00009', 'Cari no. PO', 'Belum ada pesanan pembelian'],
    ['GoodsReceipts/Index', GoodsReceiptIndex, receipts, 'GR/202611/00012', 'Cari no. GR', 'Belum ada penerimaan barang'],
])('%s', (_name, component, props, number, placeholder, emptyTitle) => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the rows the server sends', () => {
        const wrapper = mount(component, { props, global: { stubs } });

        expect(wrapper.text()).toContain(number);
        expect(wrapper.text()).toContain('PT Sumber Pangan');
        expect(wrapper.text()).not.toContain(emptyTitle);
    });

    it('shows its empty state only when there are no rows', () => {
        const wrapper = mount(component, {
            props: { ...props, items: { ...props.items, data: [] } },
            global: { stubs },
        });

        expect(wrapper.text()).toContain(emptyTitle);
    });

    it('renders the supplier filter and sends it to the server', async () => {
        const wrapper = mount(component, { props, global: { stubs } });
        const select = wrapper.find('select[aria-label="Filter berdasarkan pemasok"]');

        expect(select.exists()).toBe(true);

        await select.setValue('3');

        expect(mockRouterGet).toHaveBeenCalled();
        const query = mockRouterGet.mock.calls.at(-1)[1];
        expect(query['filter[supplier_id]'] ?? query.filter?.supplier_id).toBe('3');
    });

    it('passes its search placeholder to the filter bar', () => {
        const wrapper = mount(component, { props, global: { stubs } });

        expect(wrapper.find(`input[placeholder^="${placeholder}"]`).exists()).toBe(true);
    });
});

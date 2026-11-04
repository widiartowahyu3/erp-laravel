import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import StockBalancesIndex from '../Pages/Inventory/StockBalances/Index.vue';
import StockMovementsIndex from '../Pages/Inventory/StockMovements/Index.vue';
import StockCardIndex from '../Pages/Inventory/StockCard/Index.vue';

const { mockPageProps } = vi.hoisted(() => ({
    mockPageProps: { auth: { user: { id: 1 }, permissions: ['stock_movements.view', 'stock_balances.view'] }, flash: {}, errors: {} },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockPageProps }),
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
    router: { get: vi.fn() },
}));

const stubs = { AppLayout: { template: '<main><slot /></main>' } };
const meta = { page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 };

describe('Stock pages show what the server sends', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('Saldo Stok shows the category and base unit', () => {
        const wrapper = mount(StockBalancesIndex, {
            props: {
                items: {
                    data: [{
                        id: 1, product_id: 1, warehouse_id: 1, quantity: '99.0000', avg_cost: '16000.0000', stock_value: '1584000.00', below_minimum: false,
                        product: { id: 1, sku: 'MIE-001', name: 'Indomie', min_stock: '0', category: { id: 2, name: 'Mi Instan' }, base_unit: { id: 3, code: 'CTN', name: 'Karton' } },
                        warehouse: { id: 1, code: 'GD-JKT', name: 'Gudang Jakarta' },
                    }],
                    meta,
                    query: { filter: {} },
                },
                warehouses: [],
            },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Mi Instan');
        expect(wrapper.text()).toContain('CTN');
    });

    it('Mutasi Stok links the document number and keeps the document type as a caption', () => {
        const wrapper = mount(StockMovementsIndex, {
            props: {
                items: {
                    data: [{
                        id: 1, movement_date: '2026-11-04T08:57:00+00:00', product_id: 1, warehouse_id: 1, movement_type: 'delivery', movement_type_label: 'Pengeluaran',
                        quantity: '-1.0000', unit_cost: '16000.0000', balance_after: '99.0000', avg_cost_after: '16000.0000',
                        source_type: 'delivery_order', source_id: 3, source_url: '/sales/delivery-orders/3', source_label: 'Surat Jalan', source_number: 'DO/202611/00003',
                        creator: { id: 1, name: 'Admin' },
                        product: { id: 1, sku: 'MIE-001', name: 'Indomie' },
                        warehouse: { id: 1, code: 'GD-JKT', name: 'Gudang Jakarta' },
                    }],
                    meta,
                    query: { filter: {} },
                },
                warehouses: [],
                movementTypes: [],
            },
            global: { stubs },
        });

        const link = wrapper.find('a[href="/sales/delivery-orders/3"]');
        expect(link.text()).toBe('DO/202611/00003');
        expect(wrapper.text()).toContain('Surat Jalan');
    });

    it('Kartu Stok links the document number', () => {
        const wrapper = mount(StockCardIndex, {
            props: {
                filters: { product_id: 1, warehouse_id: 1, date_from: null, date_to: null },
                opening: { quantity: '0.0000', avg_cost: '0.0000' },
                current: { quantity: '100.0000', avg_cost: '16000.0000' },
                rows: {
                    data: [{
                        id: 1, movement_date: '2026-11-04T08:56:00+00:00', movement_type: 'receipt', movement_type_label: 'Penerimaan', source_type: 'goods_receipt', source_id: 1,
                        source_url: '/purchasing/goods-receipts/1', source_number: 'GR/202611/00001', in: '100.0000', out: null, unit_cost: '16000.0000', balance_after: '100.0000', avg_cost_after: '16000.0000',
                    }],
                    meta,
                    query: { filter: {} },
                },
                products: [{ id: 1, sku: 'MIE-001', name: 'Indomie' }],
                warehouses: [{ id: 1, code: 'GD-JKT', name: 'Gudang Jakarta' }],
            },
            global: { stubs },
        });

        expect(wrapper.find('a[href="/purchasing/goods-receipts/1"]').text()).toBe('GR/202611/00001');
    });
});

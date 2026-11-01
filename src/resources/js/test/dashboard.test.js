import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import axe from 'axe-core';
import Dashboard from '../Pages/Dashboard.vue';
import { setMockPageProps, resetMockPageProps } from '../test-setup.js';

const defaultStubs = {
    Head: true,
    Link: { template: '<a><slot /></a>' },
};

async function expectNoViolations(element) {
    const results = await axe.run(element, {
        rules: {
            'color-contrast': { enabled: false },
            region: { enabled: false },
        },
    });

    const severeViolations = results.violations.filter((v) =>
        ['critical', 'serious'].includes(v.impact)
    );

    if (severeViolations.length > 0) {
        console.error('A11y Violations in Dashboard:', JSON.stringify(severeViolations, null, 2));
    }

    expect(severeViolations).toEqual([]);
}

describe('Dashboard Component & Permissions', () => {
    beforeEach(() => {
        resetMockPageProps();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('renders empty operational summary when all props are null (no permissions)', () => {
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Guest User', email: 'user@example.com' },
                permissions: [],
            },
        });

        const wrapper = mount(Dashboard, {
            props: {
                purchaseOrders: null,
                goodsReceipts: null,
                salesOrders: null,
                deliveryOrders: null,
                stockBalances: null,
                stockMovements: null,
            },
            global: { stubs: defaultStubs },
        });

        // Summary metric cards must not exist
        expect(wrapper.text()).not.toContain('Pesanan Pembelian Berjalan');
        expect(wrapper.text()).not.toContain('Penerimaan Barang Menunggu');
        expect(wrapper.text()).not.toContain('Pesanan Penjualan Berjalan');
        expect(wrapper.text()).not.toContain('Surat Jalan Menunggu');
        expect(wrapper.text()).not.toContain('Peringatan Stok Minimum');
        expect(wrapper.text()).not.toContain('Produk Paling Kekurangan Stok');
        expect(wrapper.text()).not.toContain('Mutasi Persediaan Terkini');
    });

    it('renders only authorized metric card when only sales_orders prop is provided', () => {
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Sales Staff', email: 'sales@example.com' },
                permissions: ['sales_orders.view'],
            },
        });

        const wrapper = mount(Dashboard, {
            props: {
                purchaseOrders: null,
                goodsReceipts: null,
                salesOrders: {
                    active_so_count: 4,
                    active_so_total: '4500000.00',
                },
                deliveryOrders: null,
                stockBalances: null,
                stockMovements: null,
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Pesanan Penjualan Berjalan');
        expect(wrapper.text()).toContain('4 SO');
        expect(wrapper.text()).toContain('Rp 4.500.000');
        expect(wrapper.text()).not.toContain('Pesanan Pembelian Berjalan');
    });

    it('renders only authorized metric card when only delivery_orders prop is provided', () => {
        const wrapper = mount(Dashboard, {
            props: {
                purchaseOrders: null,
                goodsReceipts: null,
                salesOrders: null,
                deliveryOrders: {
                    draft_do_count: 5,
                },
                stockBalances: null,
                stockMovements: null,
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Surat Jalan Menunggu');
        expect(wrapper.text()).toContain('5 Draf');
        expect(wrapper.text()).not.toContain('Pesanan Penjualan Berjalan');
    });

    it('renders only authorized metric card when only purchase_orders prop is provided', () => {
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Purchasing Staff', email: 'staff@example.com' },
                permissions: ['purchase_orders.view'],
            },
        });

        const wrapper = mount(Dashboard, {
            props: {
                purchaseOrders: {
                    active_po_count: 5,
                    active_po_total: '12500000.00',
                },
                goodsReceipts: null,
                stockBalances: null,
                stockMovements: null,
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Pesanan Pembelian Berjalan');
        expect(wrapper.text()).toContain('5 PO');
        expect(wrapper.text()).toContain('Rp 12.500.000');

        // Other sections should not exist
        expect(wrapper.text()).not.toContain('Penerimaan Barang Menunggu');
        expect(wrapper.text()).not.toContain('Peringatan Stok Minimum');
    });

    it('renders only authorized metric card when only goods_receipts prop is provided', () => {
        const wrapper = mount(Dashboard, {
            props: {
                purchaseOrders: null,
                goodsReceipts: {
                    draft_gr_count: 3,
                },
                stockBalances: null,
                stockMovements: null,
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Penerimaan Barang Menunggu');
        expect(wrapper.text()).toContain('3 Draf');
        expect(wrapper.text()).not.toContain('Pesanan Pembelian Berjalan');
    });

    it('renders only stock balances summary and deficient products when only stock_balances prop is provided', () => {
        const wrapper = mount(Dashboard, {
            props: {
                purchaseOrders: null,
                goodsReceipts: null,
                stockBalances: {
                    low_stock_count: 2,
                    deficient_products: [
                        {
                            id: 10,
                            sku: 'SKU-001',
                            name: 'Biji Kopi Arabika',
                            unit_code: 'KG',
                            current_stock: '4.0000',
                            min_stock: '10.0000',
                            shortage: '6.0000',
                        },
                    ],
                },
                stockMovements: null,
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Peringatan Stok Minimum');
        expect(wrapper.text()).toContain('2 Produk');
        expect(wrapper.text()).toContain('Produk Paling Kekurangan Stok (Top 5)');
        expect(wrapper.text()).toContain('Biji Kopi Arabika');
        expect(wrapper.text()).toContain('SKU-001');
    });

    it('renders full operational dashboard when all props are provided', () => {
        const wrapper = mount(Dashboard, {
            props: {
                purchaseOrders: {
                    active_po_count: 2,
                    active_po_total: '200000.75',
                },
                goodsReceipts: {
                    draft_gr_count: 1,
                },
                salesOrders: {
                    active_so_count: 3,
                    active_so_total: '350000.00',
                },
                deliveryOrders: {
                    draft_do_count: 2,
                },
                stockBalances: {
                    low_stock_count: 1,
                    deficient_products: [
                        {
                            id: 1,
                            sku: 'SKU-TEST',
                            name: 'Barang Uji',
                            unit_code: 'PCS',
                            current_stock: '5.0000',
                            min_stock: '10.0000',
                            shortage: '5.0000',
                        },
                    ],
                },
                stockMovements: [
                    {
                        id: 101,
                        movement_date: '2026-10-31T08:00:00.000Z',
                        movement_type: 'receipt',
                        product_sku: 'SKU-TEST',
                        product_name: 'Barang Uji',
                        warehouse_code: 'WH-01',
                        warehouse_name: 'Gudang Utama',
                        quantity: '10.0000',
                        unit_cost: '20000.00',
                    },
                ],
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Pesanan Pembelian Berjalan');
        expect(wrapper.text()).toContain('Penerimaan Barang Menunggu');
        expect(wrapper.text()).toContain('Pesanan Penjualan Berjalan');
        expect(wrapper.text()).toContain('Surat Jalan Menunggu');
        expect(wrapper.text()).toContain('Peringatan Stok Minimum');
        expect(wrapper.text()).toContain('Produk Paling Kekurangan Stok');
        expect(wrapper.text()).toContain('Mutasi Persediaan Terkini');
        expect(wrapper.text()).toContain('Barang Uji');
        expect(wrapper.text()).toContain('Gudang Utama');
    });

    it('renders empty states gracefully inside tables when collections are empty', () => {
        const wrapper = mount(Dashboard, {
            props: {
                purchaseOrders: { active_po_count: 0, active_po_total: '0.00' },
                goodsReceipts: { draft_gr_count: 0 },
                stockBalances: { low_stock_count: 0, deficient_products: [] },
                stockMovements: [],
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Stok Aman');
        expect(wrapper.text()).toContain('Belum Ada Mutasi');
    });

    it('passes axe-core accessibility audit with populated dashboard metrics', async () => {
        const wrapper = mount(Dashboard, {
            attachTo: document.body,
            props: {
                purchaseOrders: {
                    active_po_count: 2,
                    active_po_total: '200000.00',
                },
                goodsReceipts: {
                    draft_gr_count: 1,
                },
                salesOrders: {
                    active_so_count: 2,
                    active_so_total: '300000.00',
                },
                deliveryOrders: {
                    draft_do_count: 1,
                },
                stockBalances: {
                    low_stock_count: 1,
                    deficient_products: [
                        {
                            id: 1,
                            sku: 'SKU-TEST',
                            name: 'Barang Uji',
                            unit_code: 'PCS',
                            current_stock: '5.0000',
                            min_stock: '10.0000',
                            shortage: '5.0000',
                        },
                    ],
                },
                stockMovements: [
                    {
                        id: 101,
                        movement_date: '2026-10-31T08:00:00.000Z',
                        movement_type: 'receipt',
                        product_sku: 'SKU-TEST',
                        product_name: 'Barang Uji',
                        warehouse_code: 'WH-01',
                        warehouse_name: 'Gudang Utama',
                        quantity: '10.0000',
                        unit_cost: '20000.00',
                    },
                ],
            },
            global: { stubs: defaultStubs },
        });

        await expectNoViolations(wrapper.element);
    });
});

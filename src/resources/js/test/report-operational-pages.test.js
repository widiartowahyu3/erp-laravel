import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import axe from 'axe-core';
import ReceivableAging from '../Pages/Reports/ReceivableAging.vue';
import PayableAging from '../Pages/Reports/PayableAging.vue';
import CustomerStatement from '../Pages/Reports/CustomerStatement.vue';
import InventoryReconciliation from '../Pages/Reports/InventoryReconciliation.vue';
import SalesSummary from '../Pages/Reports/SalesSummary.vue';
import PurchasingSummary from '../Pages/Reports/PurchasingSummary.vue';
import InventorySummary from '../Pages/Reports/InventorySummary.vue';
import Pagination from '../Components/ui/Pagination.vue';

const { mockPageProps, mockRouterGet } = vi.hoisted(() => ({
    mockPageProps: { auth: { user: { id: 1 }, permissions: [], roles: [] }, flash: {}, errors: {} },
    mockRouterGet: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockPageProps }),
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
    router: { get: mockRouterGet },
}));

// Only the layout is stubbed; every report component under test is the real one.
const stubs = { AppLayout: { template: '<main><slot /></main>' } };

async function expectNoViolations(element) {
    const results = await axe.run(element, {
        rules: { 'color-contrast': { enabled: false }, region: { enabled: false } },
    });

    expect(results.violations.filter((v) => ['critical', 'serious'].includes(v.impact) || v.id === 'heading-order')).toEqual([]);
}

function mountPage(page, props) {
    return mount(page, { props, global: { stubs }, attachTo: document.body });
}

const period = { date_from: '2026-10-01', date_to: '2026-10-31', as_of: '2026-11-05', per_page: 50 };

const agingProps = (overrides = {}) => ({
    rows: [
        {
            party_id: 5,
            party_code: 'CST-A',
            party_name: 'Pelanggan A',
            not_due: '100.00',
            days_1_30: '200.00',
            days_31_60: '300.00',
            days_61_90: '400.00',
            days_over_90: '500.00',
            total: '1500.00',
            invoices: [
                { id: 11, number: 'SI-001', invoice_date: '2026-10-01', due_date: '2026-10-30', total: '250.00', paid_amount: '50.00', outstanding: '200.00', days_overdue: 6, bucket: 'days_1_30' },
            ],
        },
    ],
    summary: { as_of: '2026-11-05', not_due: '100.00', days_1_30: '200.00', days_31_60: '300.00', days_61_90: '400.00', days_over_90: '500.00', total: '1500.00' },
    ...overrides,
});

describe('aging pages', () => {
    beforeEach(() => {
        mockPageProps.auth.permissions = ['sales_invoices.view', 'purchase_invoices.view'];
    });

    it('shows every bucket with its amount and the totals', () => {
        const wrapper = mountPage(ReceivableAging, agingProps());
        const cells = wrapper.find('tbody tr').findAll('td').map((cell) => cell.text());

        expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Pelanggan', 'Belum Jatuh Tempo', '1-30 Hari', '31-60 Hari', '61-90 Hari', '> 90 Hari', 'Total']);
        expect(cells.slice(1)).toEqual(['Rp 100', 'Rp 200', 'Rp 300', 'Rp 400', 'Rp 500', 'Rp 1.500']);
        expect(wrapper.find('tfoot').text()).toContain('Rp 1.500');
    });

    it('opens the invoices of a party through a real button', async () => {
        const wrapper = mountPage(ReceivableAging, agingProps());
        const toggle = wrapper.find('button[aria-expanded]');

        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(wrapper.text()).not.toContain('SI-001');

        // A native button is what makes Enter and Space work from the keyboard.
        expect(toggle.element.tagName).toBe('BUTTON');
        await toggle.trigger('click');

        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(toggle.attributes('aria-controls')).toBe('aging-invoices-5');
        expect(wrapper.find('#aging-invoices-5').text()).toContain('SI-001');
        expect(wrapper.find('#aging-invoices-5').text()).toContain('lewat 6 hari');

        await toggle.trigger('click');

        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('#aging-invoices-5').exists()).toBe(false);
    });

    it('links each invoice to its side of the business', async () => {
        const sales = mountPage(ReceivableAging, agingProps());
        await sales.find('button[aria-expanded]').trigger('click');
        expect(sales.find('a[href="/sales/invoices/11"]').text()).toBe('SI-001');

        const purchasing = mountPage(PayableAging, agingProps());
        await purchasing.find('button[aria-expanded]').trigger('click');
        expect(purchasing.find('a[href="/purchasing/invoices/11"]').text()).toBe('SI-001');
        expect(purchasing.findAll('thead th')[0].text()).toBe('Pemasok');
    });

    it('shows the invoice number without a link to someone who may not open it', async () => {
        mockPageProps.auth.permissions = [];

        const wrapper = mountPage(ReceivableAging, agingProps());
        await wrapper.find('button[aria-expanded]').trigger('click');

        expect(wrapper.find('a[href="/sales/invoices/11"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('SI-001');
    });

    it('says so when nothing is open', () => {
        expect(mountPage(PayableAging, agingProps({ rows: [] })).text()).toContain('Tidak ada tagihan terbuka');
    });

    it('offers an export and has no accessibility violations', async () => {
        const wrapper = mountPage(ReceivableAging, agingProps());

        expect(wrapper.find('a[href="/reports/receivable-aging?export=csv"]').exists()).toBe(true);
        await wrapper.find('button[aria-expanded]').trigger('click');
        await expectNoViolations(wrapper.element);
        await expectNoViolations(mountPage(PayableAging, agingProps({ rows: [] })).element);
    });
});

const statementProps = (overrides = {}) => ({
    rows: [
        { kind: 'invoice', document_id: 11, number: 'SI-001', applied_to: null, entry_date: '2026-10-10', debit: '2000.00', credit: '0.00', balance: '2700.00' },
        { kind: 'payment', document_id: 21, number: 'PAY-001', applied_to: 'SI-001', entry_date: '2026-10-15', debit: '0.00', credit: '900.00', balance: '1800.00' },
    ],
    summary: { customer_id: 5, customer_code: 'CST-A', customer_name: 'Pelanggan A', opening: '700.00', total_debit: '2000.00', total_credit: '900.00', closing: '1800.00' },
    filters: { ...period, customer_id: 5 },
    customers: [{ id: 5, code: 'CST-A', name: 'Pelanggan A', is_active: true }, { id: 6, code: 'CST-B', name: 'Pelanggan Lama', is_active: false }],
    ...overrides,
});

describe('customer statement', () => {
    beforeEach(() => {
        mockRouterGet.mockClear();
        mockPageProps.auth.permissions = ['sales_invoices.view', 'payments.view'];
    });

    it('asks for a customer first, marks former ones and offers no export', () => {
        const wrapper = mountPage(CustomerStatement, statementProps({ rows: [], summary: null, filters: { ...period, customer_id: null } }));

        expect(wrapper.text()).toContain('Pilih pelanggan');
        expect(wrapper.find('select').text()).toContain('Pelanggan Lama (nonaktif)');
        expect(wrapper.find('a[href*="export=csv"]').exists()).toBe(false);
    });

    it('sends the chosen customer', async () => {
        const wrapper = mountPage(CustomerStatement, statementProps({ rows: [], summary: null, filters: { ...period, customer_id: null } }));
        await wrapper.find('select').setValue('6');
        await wrapper.find('form').trigger('submit');

        expect(mockRouterGet.mock.calls[0][1]).toEqual({ date_from: '2026-10-01', date_to: '2026-10-31', customer_id: '6' });
    });

    it('runs through invoices and payments with links', () => {
        const wrapper = mountPage(CustomerStatement, statementProps());
        const rows = wrapper.findAll('tbody tr');

        expect(wrapper.text()).toContain('Saldo awal');
        expect(rows[0].text()).toContain('Faktur');
        expect(rows[0].text()).toContain('Rp 2.700');
        expect(rows[1].text()).toContain('Pembayaran');
        expect(rows[1].text()).toContain('Rp 1.800');
        expect(wrapper.find('a[href="/sales/invoices/11"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/accounting/payments/21"]').exists()).toBe(true);
        expect(wrapper.find('a[href*="export=csv"]').attributes('href')).toContain('customer_id=5');
    });

    it('holds back document links without permission', () => {
        mockPageProps.auth.permissions = [];

        const wrapper = mountPage(CustomerStatement, statementProps());

        expect(wrapper.find('a[href="/sales/invoices/11"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('PAY-001');
    });

    it('has no accessibility violations', async () => {
        await expectNoViolations(mountPage(CustomerStatement, statementProps()).element);
    });
});

describe('inventory reconciliation', () => {
    const props = (difference, status) => ({
        rows: [{ warehouse_id: 1, warehouse_code: 'GD-A', warehouse_name: 'Gudang A', stock_value: '450.00' }],
        summary: { account_code: '1301', stock_value: '450.00', ledger_balance: '450.00', difference, status },
    });

    it('states a clean match and lists no causes', () => {
        const wrapper = mountPage(InventoryReconciliation, props('0.00', 'matched'));

        expect(wrapper.text()).toContain('Cocok');
        expect(wrapper.find('[data-test="difference"]').text()).toBe('Rp 0');
        expect(wrapper.find('[data-test="causes"]').exists()).toBe(false);
    });

    it('calls out a difference in words with its known causes', () => {
        const wrapper = mountPage(InventoryReconciliation, props('-200.00', 'difference'));

        expect(wrapper.text()).toContain('Ada selisih');
        expect(wrapper.find('[data-test="difference"]').text()).toBe('-Rp 200');
        expect(wrapper.find('[data-test="causes"]').text()).toContain('Pembulatan');
    });

    it('has no accessibility violations', async () => {
        await expectNoViolations(mountPage(InventoryReconciliation, props('-200.00', 'difference')).element);
    });
});

describe('document summaries', () => {
    const rows = [{ party_id: 1, party_code: 'CST-A', party_name: 'Pelanggan A', invoice_count: 3, value: '700.00' }];

    it('shows sales counts, values and top customers', () => {
        const wrapper = mountPage(SalesSummary, {
            rows,
            summary: { order_count: 2, order_value: '1000.00', delivery_count: 4, invoice_count: 3, invoice_value: '700.00' },
            filters: period,
        });

        expect(wrapper.text()).toContain('Pesanan Penjualan');
        expect(wrapper.text()).toContain('2 dokumen');
        expect(wrapper.text()).toContain('Rp 1.000');
        expect(wrapper.text()).toContain('Surat Jalan');
        expect(wrapper.text()).toContain('4 dokumen');
        expect(wrapper.text()).toContain('10 Pelanggan Teratas');
        expect(wrapper.find('tbody').text()).toContain('Pelanggan A');
    });

    it('shows purchasing with its own labels and an empty state', () => {
        const wrapper = mountPage(PurchasingSummary, {
            rows: [],
            summary: { order_count: 0, order_value: '0.00', receipt_count: 7, invoice_count: 0, invoice_value: '0.00' },
            filters: period,
        });

        expect(wrapper.text()).toContain('Penerimaan Barang');
        expect(wrapper.text()).toContain('7 dokumen');
        expect(wrapper.text()).toContain('10 Pemasok Teratas');
        expect(wrapper.text()).toContain('Belum ada faktur');
    });

    it('has no accessibility violations', async () => {
        await expectNoViolations(mountPage(SalesSummary, {
            rows,
            summary: { order_count: 2, order_value: '1000.00', delivery_count: 4, invoice_count: 3, invoice_value: '700.00' },
            filters: period,
        }).element);
    });
});

describe('inventory summary', () => {
    const props = (overrides = {}) => ({
        rows: [
            { warehouse_id: 1, warehouse_code: 'GD-A', warehouse_name: 'Gudang A', product_id: 1, sku: 'SKU-1', product_name: 'Barang', quantity: '4.0000', min_stock: '10.0000', below_minimum: true },
            { warehouse_id: 1, warehouse_code: 'GD-A', warehouse_name: 'Gudang A', product_id: 2, sku: 'SKU-2', product_name: 'Aman', quantity: '5.0000', min_stock: '5.0000', below_minimum: false },
        ],
        summary: { product_count: 2, warehouse_count: 1, below_minimum_count: 1 },
        meta: { page: 1, per_page: 50, total: 120, last_page: 3, from: 1, to: 50 },
        filters: { ...period, warehouse_id: null, below_minimum: false },
        warehouses: [{ id: 1, code: 'GD-A', name: 'Gudang A' }],
        ...overrides,
    });

    beforeEach(() => {
        mockRouterGet.mockClear();
    });

    it('flags low stock in words and shows no cost', () => {
        const wrapper = mountPage(InventorySummary, props());
        const rows = wrapper.findAll('tbody tr');

        expect(rows[0].text()).toContain('Di bawah minimum');
        expect(rows[1].text()).toContain('Aman');
        expect(wrapper.find('[data-test="counts"]').text()).toBe('2 produk di 1 gudang, 1 di bawah minimum');
        expect(wrapper.text()).not.toContain('Rp ');
    });

    it('sends the chosen warehouse and low stock choice', async () => {
        const wrapper = mountPage(InventorySummary, props());
        await wrapper.find('select').setValue('1');
        await wrapper.find('input[type="checkbox"]').setValue(true);
        await wrapper.find('form').trigger('submit');

        expect(mockRouterGet.mock.calls[0][1]).toEqual({ warehouse_id: '1', below_minimum: 1, per_page: 50 });
    });

    it('keeps the filter on the next page and in the export', async () => {
        const wrapper = mountPage(InventorySummary, props({ filters: { ...period, warehouse_id: 1, below_minimum: true } }));
        wrapper.findComponent(Pagination).vm.$emit('change-page', 2);
        await flushPromises();

        expect(mockRouterGet.mock.calls[0][1]).toEqual({ warehouse_id: 1, below_minimum: 1, per_page: 50, page: 2 });
        expect(wrapper.find('a[href*="export=csv"]').attributes('href')).toBe('/reports/inventory-summary?warehouse_id=1&below_minimum=1&export=csv');
    });

    it('has no accessibility violations', async () => {
        await expectNoViolations(mountPage(InventorySummary, props()).element);
        await expectNoViolations(mountPage(InventorySummary, props({ rows: [], meta: null })).element);
    });
});

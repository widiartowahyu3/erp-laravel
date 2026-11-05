import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import axe from 'axe-core';
import TrialBalance from '../Pages/Reports/TrialBalance.vue';
import GeneralLedger from '../Pages/Reports/GeneralLedger.vue';
import IncomeStatement from '../Pages/Reports/IncomeStatement.vue';
import BalanceSheet from '../Pages/Reports/BalanceSheet.vue';
import ExportButton from '../Components/reports/ExportButton.vue';
import ReportFilterBar from '../Components/reports/ReportFilterBar.vue';
import Pagination from '../Components/ui/Pagination.vue';

const { mockPageProps, mockRouterGet } = vi.hoisted(() => ({
    mockPageProps: { auth: { user: { id: 1 }, permissions: ['journals.view'], roles: [] }, flash: {}, errors: {} },
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

const trialBalanceProps = (overrides = {}) => ({
    rows: [
        { code: '1101', name: 'Kas', normal_balance: 'debit', opening: '1000.00', debit: '250.50', credit: '50.00', closing: '1200.50' },
        { code: '3101', name: 'Modal Pemilik', normal_balance: 'credit', opening: '1000.00', debit: '0.00', credit: '200.50', closing: '1200.50' },
    ],
    summary: { opening_debit: '1000.00', opening_credit: '1000.00', total_debit: '250.50', total_credit: '250.50', balanced: true },
    filters: period,
    ...overrides,
});

const ledgerProps = (overrides = {}) => ({
    rows: [{ journal_id: 7, journal_number: 'JU-001', journal_date: '2026-10-05', description: 'Setoran', source_label: null, source_url: null, debit: '100.00', credit: '0.00', balance: '150.00' }],
    summary: { account_id: 3, account_code: '1101', account_name: 'Kas', normal_balance: 'debit', opening: '50.00', total_debit: '100.00', total_credit: '0.00', closing: '150.00' },
    meta: { page: 1, per_page: 50, total: 120, last_page: 3, from: 1, to: 50 },
    filters: { ...period, account_id: 3 },
    accounts: [{ id: 3, code: '1101', name: 'Kas' }, { id: 4, code: '1102', name: 'Bank' }],
    ...overrides,
});

const incomeProps = () => ({
    rows: [
        { key: 'revenue', label: 'Pendapatan', accounts: [{ code: '4101', name: 'Penjualan', amount: '5000.00' }, { code: '4201', name: 'Potongan Penjualan', amount: '-300.00' }], total: '4700.00' },
        { key: 'cost_of_sales', label: 'Beban Pokok', accounts: [{ code: '5101', name: 'HPP', amount: '2050.00' }], total: '2050.00' },
        { key: 'expenses', label: 'Beban Operasional', accounts: [], total: '0.00' },
    ],
    summary: { revenue: '4700.00', cost_of_sales: '2050.00', gross_profit: '2650.00', expenses: '0.00', net_profit: '2650.00' },
    filters: period,
});

const sheetProps = (balanced = true) => ({
    rows: [
        { key: 'assets', label: 'Aset', accounts: [{ code: '1101', name: 'Kas', amount: '1000.00' }], total: '1000.00' },
        { key: 'liabilities', label: 'Liabilitas', accounts: [], total: '0.00' },
        { key: 'equity', label: 'Ekuitas', accounts: [{ code: '3101', name: 'Modal Pemilik', amount: '700.00' }], current_earnings: '300.00', total: '1000.00' },
    ],
    summary: { as_of: '2026-11-05', assets: '1000.00', liabilities: '0.00', equity: '1000.00', liabilities_and_equity: balanced ? '1000.00' : '900.00', balanced },
    filters: period,
});

describe('report filter', () => {
    beforeEach(() => {
        mockRouterGet.mockClear();
        mockPageProps.errors = {};
    });

    it('asks for the chosen period', async () => {
        const wrapper = mountPage(TrialBalance, trialBalanceProps());
        const [from, to] = wrapper.findAll('input[type="date"]');
        await from.setValue('2026-01-01');
        await to.setValue('2026-03-31');
        await wrapper.find('form').trigger('submit');

        expect(mockRouterGet).toHaveBeenCalledWith(
            '/reports/trial-balance',
            { date_from: '2026-01-01', date_to: '2026-03-31' },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    });

    it('asks for the chosen balance date', async () => {
        const wrapper = mountPage(BalanceSheet, sheetProps());
        await wrapper.find('input[type="date"]').setValue('2026-06-30');
        await wrapper.find('form').trigger('submit');

        expect(mockRouterGet.mock.calls[0][0]).toBe('/reports/balance-sheet');
        expect(mockRouterGet.mock.calls[0][1]).toEqual({ as_of: '2026-06-30' });
    });

    it('sends the chosen account and leaves blank values out', async () => {
        const wrapper = mountPage(GeneralLedger, ledgerProps({ summary: null, meta: null, rows: [], filters: { ...period, account_id: null } }));
        await wrapper.find('form').trigger('submit');
        expect(mockRouterGet.mock.calls[0][1]).toEqual({ date_from: '2026-10-01', date_to: '2026-10-31', per_page: 50 });

        await wrapper.find('select').setValue('4');
        await wrapper.find('form').trigger('submit');
        expect(mockRouterGet.mock.calls[1][1]).toEqual({ date_from: '2026-10-01', date_to: '2026-10-31', account_id: '4', per_page: 50 });
    });

    it('shows the server error next to its field', () => {
        mockPageProps.errors = { date_to: 'Rentang tanggal maksimal 5 tahun.' };

        const wrapper = mount(ReportFilterBar, { props: { url: '/x', filters: period } });

        expect(wrapper.text()).toContain('Rentang tanggal maksimal 5 tahun.');
    });
});

describe('export button', () => {
    it('links to the csv route with the active filter and skips blanks', () => {
        const wrapper = mount(ExportButton, {
            props: { url: '/reports/inventory-summary', query: { warehouse_id: 2, below_minimum: true, empty: '', off: false, none: null } },
        });

        expect(wrapper.find('a').attributes('href')).toBe('/reports/inventory-summary?warehouse_id=2&below_minimum=1&export=csv');
        expect(wrapper.text()).toContain('Ekspor CSV');
    });

    it('carries the period of the page', () => {
        const wrapper = mountPage(TrialBalance, trialBalanceProps());

        expect(wrapper.find('a[href*="export=csv"]').attributes('href')).toBe('/reports/trial-balance?date_from=2026-10-01&date_to=2026-10-31&export=csv');
    });
});

describe('trial balance', () => {
    it('formats amounts and totals on the right', () => {
        const wrapper = mountPage(TrialBalance, trialBalanceProps());
        const row = wrapper.findAll('tbody tr')[0];

        expect(row.text()).toContain('Rp 1.000');
        expect(row.text()).toContain('Rp 250,50');
        expect(row.text()).toContain('Rp 1.200,50');
        expect(wrapper.find('tfoot').text()).toContain('Total Mutasi');
        expect(row.findAll('td')[3].classes()).toContain('text-right');
    });

    it('states whether it balances in words', () => {
        expect(mountPage(TrialBalance, trialBalanceProps()).text()).toContain('Seimbang');

        const off = mountPage(TrialBalance, trialBalanceProps({ summary: { ...trialBalanceProps().summary, balanced: false } }));

        expect(off.text()).toContain('Tidak seimbang');
    });

    it('explains an empty period', () => {
        const wrapper = mountPage(TrialBalance, trialBalanceProps({ rows: [] }));

        expect(wrapper.text()).toContain('Tidak ada mutasi');
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('has no accessibility violations', async () => {
        await expectNoViolations(mountPage(TrialBalance, trialBalanceProps()).element);
        await expectNoViolations(mountPage(TrialBalance, trialBalanceProps({ rows: [] })).element);
    });
});

describe('general ledger', () => {
    beforeEach(() => {
        mockRouterGet.mockClear();
        mockPageProps.auth.permissions = ['journals.view'];
    });

    it('asks for an account first and offers no export', () => {
        const wrapper = mountPage(GeneralLedger, ledgerProps({ summary: null, meta: null, rows: [], filters: { ...period, account_id: null } }));

        expect(wrapper.text()).toContain('Pilih akun');
        expect(wrapper.find('a[href*="export=csv"]').exists()).toBe(false);
    });

    it('shows the running balance, opening and closing', () => {
        const wrapper = mountPage(GeneralLedger, ledgerProps());

        expect(wrapper.text()).toContain('Saldo awal');
        expect(wrapper.find('tbody').text()).toContain('Rp 150');
        expect(wrapper.find('tfoot').text()).toContain('Rp 150');
        expect(wrapper.find('tbody').text()).toContain('Manual');
    });

    it('links to the journal only for those who may open it', () => {
        expect(mountPage(GeneralLedger, ledgerProps()).find('a[href="/accounting/journals/7"]').exists()).toBe(true);

        mockPageProps.auth.permissions = [];

        const wrapper = mountPage(GeneralLedger, ledgerProps());

        expect(wrapper.find('a[href="/accounting/journals/7"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('JU-001');
    });

    it('links an automatic journal to its source document', () => {
        const wrapper = mountPage(GeneralLedger, ledgerProps({
            rows: [{ ...ledgerProps().rows[0], source_label: 'Faktur Penjualan', source_url: '/sales/invoices/9' }],
        }));

        expect(wrapper.find('a[href="/sales/invoices/9"]').text()).toBe('Faktur Penjualan');
    });

    it('turns pages with the same filter', async () => {
        const wrapper = mountPage(GeneralLedger, ledgerProps());
        wrapper.findComponent(Pagination).vm.$emit('change-page', 2);
        await flushPromises();

        expect(mockRouterGet.mock.calls[0][1]).toEqual({ date_from: '2026-10-01', date_to: '2026-10-31', account_id: 3, per_page: 50, page: 2 });
    });

    it('has no accessibility violations', async () => {
        await expectNoViolations(mountPage(GeneralLedger, ledgerProps()).element);
        await expectNoViolations(mountPage(GeneralLedger, ledgerProps({ summary: null, meta: null, rows: [] })).element);
    });
});

describe('income statement', () => {
    it('shows each section, a negative contra account and the result', () => {
        const wrapper = mountPage(IncomeStatement, incomeProps());

        expect(wrapper.findAll('h2').map((h) => h.text())).toEqual(['Pendapatan', 'Beban Pokok', 'Beban Operasional', 'Hasil']);
        expect(wrapper.text()).toContain('-Rp 300');
        expect(wrapper.text()).toContain('Total Pendapatan');
        expect(wrapper.find('[data-test="gross-profit"]').text()).toBe('Rp 2.650');
        expect(wrapper.find('[data-test="net-profit"]').text()).toBe('Rp 2.650');
        expect(wrapper.text()).toContain('Tidak ada mutasi.');
    });

    it('has no accessibility violations', async () => {
        await expectNoViolations(mountPage(IncomeStatement, incomeProps()).element);
    });
});

describe('balance sheet', () => {
    it('shows current earnings under equity', () => {
        const wrapper = mountPage(BalanceSheet, sheetProps());

        expect(wrapper.text()).toContain('Laba Berjalan');
        expect(wrapper.text()).toContain('Rp 300');
        expect(wrapper.text()).toContain('Total Ekuitas');
    });

    it('states whether it balances in words', () => {
        expect(mountPage(BalanceSheet, sheetProps(true)).text()).toContain('Seimbang');
        expect(mountPage(BalanceSheet, sheetProps(false)).text()).toContain('Tidak seimbang');
    });

    it('has no accessibility violations', async () => {
        await expectNoViolations(mountPage(BalanceSheet, sheetProps()).element);
    });
});

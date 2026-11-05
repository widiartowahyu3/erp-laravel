import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import axe from 'axe-core';
import SalesOrderCreate from '../Pages/Sales/SalesOrders/Create.vue';
import SalesOrderShow from '../Pages/Sales/SalesOrders/Show.vue';
import DeliveryOrderShow from '../Pages/Sales/DeliveryOrders/Show.vue';
import Dashboard from '../Pages/Dashboard.vue';

const { mockPageProps, forms } = vi.hoisted(() => ({
    mockPageProps: { auth: { user: { id: 1, name: 'Staf' }, permissions: [] }, flash: {}, errors: {} },
    forms: [],
}));

vi.mock('@/composables/useConfirm', () => ({
    useConfirm: () => ({ confirm: vi.fn() }),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockPageProps }),
    useForm: (initial) => {
        const form = reactive({ ...initial, processing: false, errors: {}, post: vi.fn(), put: vi.fn() });
        forms.push(form);

        return form;
    },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

const stubs = { AppLayout: { template: '<main><slot /></main>' } };

function formProps(customer) {
    return {
        customers: [customer],
        warehouses: [{ id: 1, code: 'WH', name: 'Gudang' }],
        paymentTerms: [],
        taxRates: [],
        products: [{ id: 5, sku: 'SKU', name: 'Beras', base_unit: { code: 'KG' }, selling_price: '1000.0000' }],
    };
}

const customer = (overrides = {}) => ({
    id: 3,
    code: 'CUST-1',
    name: 'PT Maju Jaya',
    payment_term_id: null,
    address: null,
    creditLimit: '10000.00',
    outstandingReceivable: '7500.00',
    ...overrides,
});

function mountForm(values) {
    const wrapper = mount(SalesOrderCreate, { props: formProps(values), global: { stubs } });

    return { wrapper, form: forms[0] };
}

beforeEach(() => {
    forms.length = 0;
    mockPageProps.auth.permissions = ['sales_orders.view', 'sales_orders.create', 'sales_invoices.create', 'delivery_orders.view'];
    mockPageProps.errors = {};
    mockPageProps.flash = {};
});

describe('Credit information on the sales order form', () => {
    it('shows nothing until a customer is chosen', () => {
        const { wrapper } = mountForm(customer());

        expect(wrapper.text()).not.toContain('Batas kredit');
    });

    it('shows limit, outstanding and what is left for the chosen customer', async () => {
        const { wrapper, form } = mountForm(customer());

        form.customer_id = '3';
        await wrapper.vm.$nextTick();

        const text = wrapper.text();

        expect(text).toContain('Batas kredit:');
        expect(text).toContain('10.000');
        expect(text).toContain('Piutang beredar:');
        expect(text).toContain('7.500');
        expect(text).toContain('Sisa batas:');
        expect(text).toContain('2.500');
        expect(text).not.toContain('akan melampaui batas kredit');
    });

    it('warns once the order would pass the limit, and stays quiet at exactly the limit', async () => {
        const { wrapper, form } = mountForm(customer());

        form.customer_id = '3';
        form.items = [{ product_id: '5', quantity: '1', unit_price: '2500', discount_amount: '0', tax_rate_id: '' }];
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('akan melampaui batas kredit');

        form.items[0].unit_price = '2500.01';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('akan melampaui batas kredit');
    });

    it('calls a zero limit unlimited and never warns', async () => {
        const { wrapper, form } = mountForm(customer({ creditLimit: '0.00' }));

        form.customer_id = '3';
        form.items = [{ product_id: '5', quantity: '1', unit_price: '99999999', discount_amount: '0', tax_rate_id: '' }];
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('tanpa batas');
        expect(wrapper.text()).not.toContain('Sisa batas');
        expect(wrapper.text()).not.toContain('akan melampaui batas kredit');
    });
});

describe('Buat Faktur shortcuts', () => {
    const order = {
        id: 11,
        number: 'SO/202611/00011',
        status: 'delivered',
        order_date: '2026-11-01',
        subtotal: '0.00',
        discount_amount: '0.00',
        tax_amount: '0.00',
        total: '0.00',
        customer: { id: 3, code: 'CUST-1', name: 'PT Maju Jaya' },
        warehouse: { id: 1, code: 'WH', name: 'Gudang' },
        items: [],
        delivery_orders: [],
    };
    const delivery = {
        id: 5,
        number: 'DO/202611/00005',
        status: 'posted',
        sales_order_id: 11,
        sales_order: { id: 11, number: 'SO/202611/00011' },
        delivery_date: '2026-11-01',
        customer: { id: 3, code: 'CUST-1', name: 'PT Maju Jaya' },
        warehouse: { id: 1, code: 'WH', name: 'Gudang' },
        items: [],
    };
    const href = '/sales/invoices/create?sales_order_id=11';

    it.each([
        ['sales order', SalesOrderShow, order],
        ['delivery order', DeliveryOrderShow, delivery],
    ])('appears on the %s only with something to bill and the permission', (_name, page, item) => {
        const render = (hasBillableItems) => mount(page, { props: { item, hasBillableItems }, global: { stubs } });

        expect(render(true).find(`a[href="${href}"]`).exists()).toBe(true);
        expect(render(false).find(`a[href="${href}"]`).exists()).toBe(false);

        mockPageProps.auth.permissions = ['sales_orders.view', 'delivery_orders.view'];

        expect(render(true).find(`a[href="${href}"]`).exists()).toBe(false);
    });

    it('shows the credit refusal from approving on the sales order page', () => {
        mockPageProps.errors = { customer_id: 'Batas kredit pelanggan terlampaui (batas 10000, piutang beredar 5000, pesanan ini 6000)' };

        const wrapper = mount(SalesOrderShow, { props: { item: { ...order, status: 'draft' } }, global: { stubs } });

        expect(wrapper.text()).toContain('Batas kredit pelanggan terlampaui');
    });
});

describe('Receivable cards on the dashboard', () => {
    const dashboardProps = {
        purchaseOrders: null,
        goodsReceipts: null,
        salesOrders: null,
        deliveryOrders: null,
        salesInvoices: { outstanding_total: '147645.55', overdue_count: 3 },
        stockBalances: null,
        stockMovements: null,
    };

    it('shows the amounts and links with the filter[...] shape', () => {
        const wrapper = mount(Dashboard, { props: dashboardProps, global: { stubs } });
        const text = wrapper.text();

        expect(text).toContain('Piutang Beredar');
        expect(text).toContain('147.645,55');
        expect(text).toContain('Faktur Jatuh Tempo');
        expect(text).toContain('3');
        expect(wrapper.find('a[href="/sales/invoices?filter[status]=posted"]').exists()).toBe(true);
        expect(wrapper.find('a[href="/sales/invoices?filter[overdue]=1"]').exists()).toBe(true);
    });

    it('hides the cards without the permission', () => {
        const wrapper = mount(Dashboard, { props: { ...dashboardProps, salesInvoices: null }, global: { stubs } });

        expect(wrapper.text()).not.toContain('Piutang Beredar');
    });

    it('has no accessibility violations', async () => {
        const wrapper = mount(Dashboard, { props: dashboardProps, attachTo: document.body, global: { stubs } });
        const results = await axe.run(wrapper.element, {
            rules: { 'color-contrast': { enabled: false }, region: { enabled: false } },
        });

        expect(results.violations.filter((v) => ['critical', 'serious'].includes(v.impact) || v.id === 'heading-order')).toEqual([]);
        wrapper.unmount();
    });
});

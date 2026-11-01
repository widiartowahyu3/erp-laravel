import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import axe from 'axe-core';
import DeliveryOrderIndex from '../Pages/Sales/DeliveryOrders/Index.vue';
import DeliveryOrderCreate from '../Pages/Sales/DeliveryOrders/Create.vue';
import DeliveryOrderEdit from '../Pages/Sales/DeliveryOrders/Edit.vue';
import DeliveryOrderShow from '../Pages/Sales/DeliveryOrders/Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            auth: {
                user: { id: 1, name: 'Admin', role: 'admin' },
                permissions: [
                    'sales_orders.view',
                    'sales_orders.create',
                    'sales_orders.update',
                    'delivery_orders.view',
                    'delivery_orders.create',
                    'delivery_orders.update',
                    'delivery_orders.post',
                    'delivery_orders.void',
                ],
            },
            flash: {},
            errors: {},
        },
    }),
    useForm: (initialData) => reactive({
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
    Head: { name: 'Head', template: '<div style="display:none"><slot /></div>' },
    router: {
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

const defaultStubs = {
    AppLayout: { template: '<div><slot /></div>' },
    PageHeader: {
        props: ['title', 'description'],
        template: '<div><h1>{{ title }}</h1><p>{{ description }}</p><slot /><slot name="actions" /></div>',
    },
    Breadcrumbs: { template: '<nav><slot /></nav>' },
    Can: { template: '<div><slot /></div>' },
    Card: { template: '<div><slot /><slot name="title" /><slot name="description" /></div>' },
    Button: { template: '<button :disabled="$attrs.disabled"><slot /></button>' },
    IconButton: { template: '<button :disabled="$attrs.disabled"><slot /></button>' },
    StatusBadge: { template: '<span><slot /></span>' },
    DateDisplay: { template: '<span><slot /></span>' },
    Quantity: { template: '<span><slot /></span>' },
    Alert: { template: '<div><slot /></div>' },
    DataTable: {
        props: ['columns', 'items'],
        template: `
            <table>
                <tbody>
                    <tr v-for="item in items" :key="item.id">
                        <td><slot name="cell(number)" :item="item" /></td>
                        <td><slot name="cell(so_number)" :item="item" /></td>
                        <td><slot name="cell(customer)" :item="item" /></td>
                        <td><slot name="cell(status)" :item="item" /></td>
                        <td><slot name="cell(actions)" :item="item" /></td>
                    </tr>
                </tbody>
            </table>
        `,
    },
    FilterBar: { template: '<div><slot /><slot name="filters" /></div>' },
    Pagination: { template: '<div><slot /></div>' },
    Select: {
        props: ['modelValue', 'options', 'size'],
        emits: ['update:modelValue'],
        template: '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option></select>',
    },
    TextInput: {
        props: ['modelValue', 'size'],
        emits: ['update:modelValue'],
        template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    },
    Textarea: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)"></textarea>',
    },
    FormField: { template: '<div><slot /></div>' },
};

const dummyMeta = {
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 1,
    from: 1,
    to: 1,
};

async function expectNoViolations(element) {
    const results = await axe.run(element, {
        rules: {
            'color-contrast': { enabled: false },
        },
    });
    expect(results.violations).toEqual([]);
}

describe('Delivery Order Pages (Butir 10)', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('DeliveryOrders/Index guides user to SO list and displays DO records', () => {
        const wrapper = mount(DeliveryOrderIndex, {
            props: {
                items: {
                    data: [
                        {
                            id: 1,
                            number: 'DO/202611/00001',
                            delivery_date: '2026-11-01',
                            sales_order: { id: 10, number: 'SO/202611/00010' },
                            customer: { code: 'CUST-001', name: 'PT Pembeli Sukses' },
                            warehouse: { code: 'WH-01', name: 'Gudang Pusat' },
                            status: 'draft',
                        },
                    ],
                    meta: dummyMeta,
                    query: {},
                },
                customers: [{ id: 1, code: 'CUST-001', name: 'PT Pembeli Sukses' }],
                warehouses: [{ id: 1, code: 'WH-01', name: 'Gudang Pusat' }],
                statuses: ['draft', 'posted'],
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Surat Jalan / Pengiriman (DO)');
        expect(wrapper.text()).toContain('DO/202611/00001');
        expect(wrapper.text()).toContain('SO/202611/00010');

        const soLink = wrapper.find('a[href="/sales/sales-orders?status=approved"]');
        expect(soLink.exists()).toBe(true);
    });

    it('DeliveryOrders/Create pre-fills items and allows removing line', () => {
        const salesOrder = {
            id: 10,
            number: 'SO/202611/00010',
            customer_id: 1,
            customer: { id: 1, code: 'CUST-001', name: 'PT Pembeli', address: 'Jl. Merdeka No. 1' },
            warehouse_id: 2,
            warehouse: { id: 2, code: 'WH-01', name: 'Gudang Pusat' },
        };

        const eligibleItems = [
            {
                id: 101,
                product_id: 5,
                quantity: '10.0000',
                delivered_quantity: '3.0000',
                product: { id: 5, sku: 'SKU-05', name: 'Barang Alpha' },
            },
            {
                id: 102,
                product_id: 6,
                quantity: '5.0000',
                delivered_quantity: '0.0000',
                product: { id: 6, sku: 'SKU-06', name: 'Barang Beta' },
            },
        ];

        const wrapper = mount(DeliveryOrderCreate, {
            props: { salesOrder, eligibleItems },
            global: { stubs: defaultStubs },
        });

        // Remaining quantities: item 101 => 7.0000, item 102 => 5.0000
        expect(wrapper.vm.form.items.length).toBe(2);
        expect(wrapper.vm.form.items[0].quantity).toBe('7.0000');
        expect(wrapper.vm.form.items[1].quantity).toBe('5.0000');
        expect(wrapper.vm.form.shipping_address).toBe('Jl. Merdeka No. 1');

        // Remove item 1
        wrapper.vm.removeItem(1);
        expect(wrapper.vm.form.items.length).toBe(1);
    });

    it('DeliveryOrders/Show hides unit_cost on draft and shows it when posted', () => {
        const draftOrder = {
            id: 1,
            number: 'DO/202611/00001',
            delivery_date: '2026-11-01',
            status: 'draft',
            customer: { code: 'C01', name: 'Customer A' },
            warehouse: { code: 'WH1', name: 'Warehouse A' },
            creator: { name: 'Admin' },
            items: [
                {
                    id: 1,
                    product_id: 5,
                    quantity: '4.0000',
                    unit_cost: '10000.0000',
                    product: { id: 5, sku: 'SKU-05', name: 'Barang Alpha' },
                },
            ],
        };

        const wrapperDraft = mount(DeliveryOrderShow, {
            props: { item: draftOrder },
            global: { stubs: defaultStubs },
        });

        // On draft, HPP column header should NOT exist
        expect(wrapperDraft.text()).not.toContain('HPP Satuan');
        expect(wrapperDraft.text()).not.toContain('Total HPP');

        // Now test posted order
        const postedOrder = {
            ...draftOrder,
            status: 'posted',
            poster: { name: 'Warehouse Staff' },
        };

        const wrapperPosted = mount(DeliveryOrderShow, {
            props: { item: postedOrder },
            global: { stubs: defaultStubs },
        });

        // On posted, HPP column headers MUST exist
        expect(wrapperPosted.text()).toContain('HPP Satuan');
        expect(wrapperPosted.text()).toContain('Total HPP');
        expect(wrapperPosted.text()).toContain('Rp 40.000');
    });

    it('DeliveryOrders pages pass axe-core accessibility checks', async () => {
        const doIndex = mount(DeliveryOrderIndex, {
            attachTo: document.body,
            props: {
                items: { data: [], meta: dummyMeta, query: {} },
                customers: [],
                warehouses: [],
                statuses: [],
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(doIndex.element);
        document.body.innerHTML = '';

        const doCreate = mount(DeliveryOrderCreate, {
            attachTo: document.body,
            props: {
                salesOrder: {
                    id: 1,
                    number: 'SO/001',
                    customer: { id: 1, code: 'C1', name: 'Customer 1', address: 'Jl. Test' },
                    warehouse: { id: 1, code: 'WH1', name: 'Gudang Pusat' },
                },
                eligibleItems: [
                    {
                        id: 1,
                        product_id: 1,
                        quantity: '10.0000',
                        delivered_quantity: '0.0000',
                        product: { id: 1, sku: 'SKU1', name: 'Product 1' },
                    },
                ],
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(doCreate.element);
        document.body.innerHTML = '';

        const doEdit = mount(DeliveryOrderEdit, {
            attachTo: document.body,
            props: {
                item: {
                    id: 1,
                    number: 'DO/001',
                    delivery_date: '2026-11-01',
                    shipping_address: 'Jl. Test',
                    notes: '',
                    customer: { code: 'C1', name: 'Customer 1' },
                    warehouse: { code: 'WH1', name: 'Gudang Pusat' },
                    items: [
                        {
                            sales_order_item_id: 1,
                            product_id: 1,
                            quantity: '5.0000',
                            product: { id: 1, sku: 'SKU1', name: 'Product 1' },
                            sales_order_item: { quantity: '10.0000' },
                        },
                    ],
                },
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(doEdit.element);
        document.body.innerHTML = '';

        const doShow = mount(DeliveryOrderShow, {
            attachTo: document.body,
            props: {
                item: {
                    id: 1,
                    number: 'DO/001',
                    delivery_date: '2026-11-01',
                    status: 'draft',
                    notes: '',
                    customer: { code: 'C1', name: 'Customer 1' },
                    warehouse: { code: 'WH1', name: 'Gudang Pusat' },
                    creator: { name: 'Admin' },
                    items: [],
                },
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(doShow.element);
    });
});

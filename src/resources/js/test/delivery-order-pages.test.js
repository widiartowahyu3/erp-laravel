import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import axe from 'axe-core';
import DeliveryOrderIndex from '../Pages/Sales/DeliveryOrders/Index.vue';
import DeliveryOrderCreate from '../Pages/Sales/DeliveryOrders/Create.vue';
import DeliveryOrderEdit from '../Pages/Sales/DeliveryOrders/Edit.vue';
import DeliveryOrderShow from '../Pages/Sales/DeliveryOrders/Show.vue';

const {
    defaultPermissions,
    mockPageProps,
    mockConfirm,
    mockRouterPost,
    mockRouterPut,
    mockRouterDelete,
} = vi.hoisted(() => {
    const defaultPermissions = [
        'sales_orders.view',
        'sales_orders.create',
        'sales_orders.update',
        'delivery_orders.view',
        'delivery_orders.create',
        'delivery_orders.update',
        'delivery_orders.post',
        'delivery_orders.void',
    ];
    return {
        defaultPermissions,
        mockPageProps: {
            auth: {
                user: { id: 1, name: 'Admin', role: 'admin' },
                permissions: [...defaultPermissions],
            },
            flash: {},
            errors: {},
        },
        mockConfirm: vi.fn(),
        mockRouterPost: vi.fn(),
        mockRouterPut: vi.fn(),
        mockRouterDelete: vi.fn(),
    };
});

vi.mock('@/composables/useConfirm', () => ({
    useConfirm: () => ({
        confirm: mockConfirm,
    }),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: mockPageProps,
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
        post: mockRouterPost,
        put: mockRouterPut,
        delete: mockRouterDelete,
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
    Button: {
        props: ['disabled', 'loading'],
        template: '<button :disabled="disabled || loading" :aria-busy="$attrs[\'aria-busy\']"><slot /></button>',
    },
    IconButton: {
        props: ['disabled', 'loading'],
        template: '<button :disabled="disabled || loading" :aria-busy="$attrs[\'aria-busy\']"><slot /></button>',
    },
    DateDisplay: { template: '<span><slot /></span>' },
    Quantity: { template: '<span><slot /></span>' },
    Alert: {
        props: ['variant'],
        template: '<div role="alert" :data-variant="variant"><slot /></div>',
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
            region: { enabled: false },
        },
    });
    const severeViolations = results.violations.filter((v) =>
        ['critical', 'serious'].includes(v.impact)
    );
    expect(severeViolations).toEqual([]);
}

describe('Delivery Order Pages (Butir 10)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockPageProps.auth = {
            user: { id: 1, name: 'Admin', role: 'admin' },
            permissions: [...defaultPermissions],
        };
        mockPageProps.flash = {};
        mockPageProps.errors = {};
    });

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

    it('DeliveryOrders/Index renders real DataTable rows, customer names, status badges, and no empty message for multiple items', () => {
        const wrapper = mount(DeliveryOrderIndex, {
            props: {
                items: {
                    data: [
                        {
                            id: 1,
                            number: 'DO/202611/00001',
                            delivery_date: '2026-11-01',
                            sales_order: { id: 10, number: 'SO/202611/00010' },
                            customer: { code: 'CUST-001', name: 'PT Pembeli Satu' },
                            warehouse: { code: 'WH-01', name: 'Gudang Pusat' },
                            status: 'draft',
                        },
                        {
                            id: 2,
                            number: 'DO/202611/00002',
                            delivery_date: '2026-11-02',
                            sales_order: { id: 11, number: 'SO/202611/00011' },
                            customer: { code: 'CUST-002', name: 'PT Pembeli Dua' },
                            warehouse: { code: 'WH-02', name: 'Gudang Cabang' },
                            status: 'posted',
                        },
                        {
                            id: 3,
                            number: 'DO/202611/00003',
                            delivery_date: '2026-11-03',
                            sales_order: { id: 12, number: 'SO/202611/00012' },
                            customer: { code: 'CUST-003', name: 'PT Pembeli Tiga' },
                            warehouse: { code: 'WH-01', name: 'Gudang Pusat' },
                            status: 'delivered',
                        },
                    ],
                    meta: { ...dummyMeta, total: 3, to: 3 },
                    query: {},
                },
                customers: [
                    { id: 1, code: 'CUST-001', name: 'PT Pembeli Satu' },
                    { id: 2, code: 'CUST-002', name: 'PT Pembeli Dua' },
                    { id: 3, code: 'CUST-003', name: 'PT Pembeli Tiga' },
                ],
                warehouses: [
                    { id: 1, code: 'WH-01', name: 'Gudang Pusat' },
                    { id: 2, code: 'WH-02', name: 'Gudang Cabang' },
                ],
                statuses: ['draft', 'posted', 'delivered'],
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('DO/202611/00001');
        expect(wrapper.text()).toContain('DO/202611/00002');
        expect(wrapper.text()).toContain('DO/202611/00003');
        expect(wrapper.text()).toContain('PT Pembeli Satu');
        expect(wrapper.text()).toContain('PT Pembeli Dua');
        expect(wrapper.text()).toContain('PT Pembeli Tiga');
        expect(wrapper.text()).toContain('Draf');
        expect(wrapper.text()).toContain('Diposting');
        expect(wrapper.text()).toContain('Terkirim');
        expect(wrapper.text()).not.toContain('Tidak ada data');
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
        expect(wrapper.vm.form.items[0].quantity).toBe('7');
        expect(wrapper.vm.form.items[1].quantity).toBe('5');
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

    it('DeliveryOrders/Show prevents double click on post and void actions', async () => {
        const order = {
            id: 1,
            number: 'DO/202611/00001',
            status: 'draft',
            customer: { name: 'Customer A' },
            warehouse: { name: 'Warehouse A' },
            creator: { name: 'Admin' },
            items: [],
        };

        mockConfirm.mockResolvedValue(true);
        const wrapperPost = mount(DeliveryOrderShow, {
            props: { item: order },
            global: { stubs: defaultStubs },
        });

        const postBtn = wrapperPost.findAll('button').find((b) => b.text().includes('Posting DO'));
        expect(postBtn).toBeDefined();

        await postBtn.trigger('click');
        await postBtn.trigger('click');

        expect(mockRouterPost).toHaveBeenCalledTimes(1);
        expect(mockRouterPost).toHaveBeenCalledWith(
            '/sales/delivery-orders/1/post',
            {},
            expect.objectContaining({ preserveScroll: true })
        );
        expect(postBtn.attributes('disabled')).toBeDefined();
        expect(postBtn.attributes('aria-busy')).toBe('true');

        // Test void button with confirmation and reason
        vi.clearAllMocks();
        mockConfirm.mockResolvedValue({ confirmed: true, reason: 'Barang rusak saat pengiriman' });
        const wrapperVoid = mount(DeliveryOrderShow, {
            props: { item: order },
            global: { stubs: defaultStubs },
        });

        const voidBtn = wrapperVoid.findAll('button').find((b) => b.text().includes('Batalkan DO'));
        expect(voidBtn).toBeDefined();

        await voidBtn.trigger('click');
        await voidBtn.trigger('click');

        expect(mockRouterPost).toHaveBeenCalledTimes(1);
        expect(mockRouterPost).toHaveBeenCalledWith(
            '/sales/delivery-orders/1/void',
            { reason: 'Barang rusak saat pengiriman' },
            expect.objectContaining({ preserveScroll: true })
        );
        expect(voidBtn.attributes('disabled')).toBeDefined();
        expect(voidBtn.attributes('aria-busy')).toBe('true');
    });

    it('DeliveryOrders/Show displays action buttons strictly according to permission and status', () => {
        const baseOrder = {
            id: 1,
            number: 'DO/202611/00001',
            customer: { name: 'Customer A' },
            warehouse: { name: 'Warehouse A' },
            creator: { name: 'Admin' },
            items: [],
        };

        // 1. Draft with delivery_orders.post & void: Posting DO & Batalkan DO visible
        const draftWrapper = mount(DeliveryOrderShow, {
            props: { item: { ...baseOrder, status: 'draft' } },
            global: { stubs: defaultStubs },
        });
        expect(draftWrapper.findAll('button').some((b) => b.text().includes('Posting DO'))).toBe(true);
        expect(draftWrapper.findAll('button').some((b) => b.text().includes('Batalkan DO'))).toBe(true);

        // 2. Draft without delivery_orders.post permission: Posting DO NOT visible
        mockPageProps.auth.permissions = ['delivery_orders.view', 'delivery_orders.void'];
        const noPostWrapper = mount(DeliveryOrderShow, {
            props: { item: { ...baseOrder, status: 'draft' } },
            global: { stubs: defaultStubs },
        });
        expect(noPostWrapper.findAll('button').some((b) => b.text().includes('Posting DO'))).toBe(false);

        // 3. Posted with delivery_orders.void: Posting DO NOT visible, Batalkan DO visible
        mockPageProps.auth.permissions = [...defaultPermissions];
        const postedWrapper = mount(DeliveryOrderShow, {
            props: { item: { ...baseOrder, status: 'posted' } },
            global: { stubs: defaultStubs },
        });
        expect(postedWrapper.findAll('button').some((b) => b.text().includes('Posting DO'))).toBe(false);
        expect(postedWrapper.findAll('button').some((b) => b.text().includes('Batalkan DO'))).toBe(true);

        // 4. Voided DO: neither Posting DO nor Batalkan DO visible
        const voidWrapper = mount(DeliveryOrderShow, {
            props: { item: { ...baseOrder, status: 'void' } },
            global: { stubs: defaultStubs },
        });
        expect(voidWrapper.findAll('button').some((b) => b.text().includes('Posting DO'))).toBe(false);
        expect(voidWrapper.findAll('button').some((b) => b.text().includes('Batalkan DO'))).toBe(false);

        // 5. Posted without delivery_orders.void: Batalkan DO NOT visible
        mockPageProps.auth.permissions = ['delivery_orders.view', 'delivery_orders.post'];
        const noVoidWrapper = mount(DeliveryOrderShow, {
            props: { item: { ...baseOrder, status: 'posted' } },
            global: { stubs: defaultStubs },
        });
        expect(noVoidWrapper.findAll('button').some((b) => b.text().includes('Batalkan DO'))).toBe(false);
    });

    it('DeliveryOrders/Show renders server error alerts for quantity and status conflicts', () => {
        mockPageProps.errors = {
            quantity: 'Stok barang tidak mencukupi di gudang asal',
            status: 'Status surat jalan tidak valid untuk aksi ini',
        };

        const wrapper = mount(DeliveryOrderShow, {
            props: {
                item: {
                    id: 1,
                    number: 'DO/202611/00001',
                    status: 'draft',
                    customer: { name: 'Customer A' },
                    warehouse: { name: 'Warehouse A' },
                    creator: { name: 'Admin' },
                    items: [],
                },
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Stok barang tidak mencukupi di gudang asal');
        expect(wrapper.text()).toContain('Status surat jalan tidak valid untuk aksi ini');
    });

    it('DeliveryOrders/Edit renders form with initial DO data populated', () => {
        const existingDo = {
            id: 12,
            number: 'DO/202611/00012',
            delivery_date: '2026-11-04',
            shipping_address: 'Kawasan Industri Cikarang Blok B4',
            notes: 'Harap dikirim sebelum jam 14:00',
            sales_order: { id: 5, number: 'SO/202611/00005' },
            customer: { code: 'CUST-01', name: 'PT Pembeli Cikarang' },
            warehouse: { code: 'WH-01', name: 'Gudang Utama' },
            items: [
                {
                    sales_order_item_id: 101,
                    product_id: 1,
                    quantity: '6.0000',
                    product: { id: 1, sku: 'SKU-001', name: 'Baut Hex 10mm' },
                    sales_order_item: { quantity: '10.0000' },
                },
            ],
        };

        const wrapper = mount(DeliveryOrderEdit, {
            props: { item: existingDo },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Edit DO: DO/202611/00012');
        expect(wrapper.vm.form.delivery_date).toBe('2026-11-04');
        expect(wrapper.vm.form.shipping_address).toBe('Kawasan Industri Cikarang Blok B4');
        expect(wrapper.vm.form.notes).toBe('Harap dikirim sebelum jam 14:00');
        expect(wrapper.vm.form.items[0].quantity).toBe('6');
        expect(wrapper.vm.form.items[0].sales_order_item_id).toBe('101');
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

    it('DeliveryOrders/Index and DeliveryOrders/Show render real StatusBadge with localized labels across states', () => {
        const states = [
            { status: 'draft', label: 'Draf' },
            { status: 'posted', label: 'Diposting' },
            { status: 'void', label: 'Dibatalkan' },
        ];

        for (const item of states) {
            const indexWrapper = mount(DeliveryOrderIndex, {
                props: {
                    items: {
                        data: [{
                            id: 1,
                            number: 'DO/202611/00001',
                            delivery_date: '2026-11-01',
                            customer: { code: 'CUST-001', name: 'PT Pembeli' },
                            warehouse: { code: 'WH-01', name: 'Gudang' },
                            sales_order: { number: 'SO-001' },
                            status: item.status,
                        }],
                        meta: dummyMeta,
                        query: {},
                    },
                    customers: [],
                    warehouses: [],
                    statuses: [item.status],
                },
                global: { stubs: defaultStubs },
            });
            expect(indexWrapper.text()).toContain(item.label);

            const showWrapper = mount(DeliveryOrderShow, {
                props: {
                    deliveryOrder: {
                        id: 1,
                        number: 'DO/202611/00001',
                        delivery_date: '2026-11-01',
                        status: item.status,
                        customer: { id: 1, code: 'CUST-001', name: 'PT Pembeli' },
                        warehouse: { id: 1, code: 'WH-01', name: 'Gudang' },
                        sales_order: { id: 1, number: 'SO-001' },
                        items: [],
                    },
                },
                global: { stubs: defaultStubs },
            });
            expect(showWrapper.text()).toContain(item.label);
        }
    });

    it('DeliveryOrders/Edit trims trailing zeros from quantity input', () => {
        const deliveryOrder = {
            id: 1,
            number: 'DO/202611/00001',
            delivery_date: '2026-11-01',
            status: 'draft',
            customer: { id: 1, code: 'C01', name: 'Cust 1' },
            warehouse: { id: 1, code: 'WH1', name: 'WH 1' },
            sales_order: { id: 1, number: 'SO-001' },
            items: [
                {
                    id: 1,
                    sales_order_item_id: 1,
                    product_id: 1,
                    quantity: '4.0000',
                    product: { id: 1, sku: 'P1', name: 'Product 1' },
                    sales_order_item: { id: 1, quantity: '10.0000' },
                },
            ],
        };

        const wrapper = mount(DeliveryOrderEdit, {
            props: {
                item: deliveryOrder,
            },
            global: { stubs: defaultStubs },
        });

        const qtyInput = wrapper.find('input[placeholder="1.0000"], input[placeholder="1"]');
        expect(qtyInput.element.value).toBe('4');
    });
});

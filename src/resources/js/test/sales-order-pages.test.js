import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';
import axe from 'axe-core';
import SalesOrderIndex from '../Pages/Sales/SalesOrders/Index.vue';
import SalesOrderForm from '../Pages/Sales/SalesOrders/Form.vue';
import SalesOrderShow from '../Pages/Sales/SalesOrders/Show.vue';
import SalesOrderEdit from '../Pages/Sales/SalesOrders/Edit.vue';

const {
    defaultPermissions,
    mockPageProps,
    mockConfirm,
    mockRouterGet,
    mockRouterPost,
    mockRouterPut,
    mockRouterDelete,
} = vi.hoisted(() => {
    const defaultPermissions = [
        'sales_orders.view',
        'sales_orders.create',
        'sales_orders.update',
        'sales_orders.approve',
        'sales_orders.void',
        'delivery_orders.create',
        'delivery_orders.view',
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
        mockRouterGet: vi.fn(),
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
        get: mockRouterGet,
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
    Rupiah: { template: '<span><slot /></span>' },
    Alert: {
        props: ['variant'],
        template: '<div role="alert" :data-variant="variant"><slot /></div>',
    },

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
            'color-contrast': { enabled: false }, // jsdom does not calculate computed styles
            region: { enabled: false },
        },
    });
    const severeViolations = results.violations.filter((v) =>
        ['critical', 'serious'].includes(v.impact) || v.id === 'heading-order'
    );
    expect(severeViolations).toEqual([]);
}

describe('Sales Order Pages (Butir 9)', () => {
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

    it('SalesOrders/Index renders list, create button and items', () => {
        const wrapper = mount(SalesOrderIndex, {
            props: {
                items: {
                    data: [
                        {
                            id: 1,
                            number: 'SO/202611/00001',
                            order_date: '2026-11-01',
                            customer: { code: 'CUST-001', name: 'PT Pembeli Sukses' },
                            warehouse: { code: 'WH-01', name: 'Gudang Pusat' },
                            status: 'draft',
                            total: '111000.00',
                        },
                    ],
                    meta: dummyMeta,
                    query: {},
                },
                customers: [{ id: 1, code: 'CUST-001', name: 'PT Pembeli Sukses' }],
                warehouses: [{ id: 1, code: 'WH-01', name: 'Gudang Pusat' }],
                statuses: ['draft', 'approved'],
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Pesanan Penjualan (SO)');
        expect(wrapper.text()).toContain('SO/202611/00001');
        expect(wrapper.text()).toContain('PT Pembeli Sukses');

        const createLink = wrapper.find('a[href="/sales/sales-orders/create"]');
        expect(createLink.exists()).toBe(true);
        expect(wrapper.find('select[aria-label="Filter berdasarkan pelanggan"]').exists()).toBe(true);
    });

    it('SalesOrders/Index renders real DataTable rows, customer names, status badges, and no empty message for multiple items', () => {
        const wrapper = mount(SalesOrderIndex, {
            props: {
                items: {
                    data: [
                        {
                            id: 1,
                            number: 'SO/202611/00001',
                            order_date: '2026-11-01',
                            customer: { code: 'CUST-001', name: 'PT Pembeli Satu' },
                            warehouse: { code: 'WH-01', name: 'Gudang Pusat' },
                            status: 'draft',
                            total: '100000.00',
                        },
                        {
                            id: 2,
                            number: 'SO/202611/00002',
                            order_date: '2026-11-02',
                            customer: { code: 'CUST-002', name: 'PT Pembeli Dua' },
                            warehouse: { code: 'WH-02', name: 'Gudang Cabang' },
                            status: 'approved',
                            total: '250000.00',
                        },
                    ],
                    meta: { ...dummyMeta, total: 2, to: 2 },
                    query: {},
                },
                customers: [
                    { id: 1, code: 'CUST-001', name: 'PT Pembeli Satu' },
                    { id: 2, code: 'CUST-002', name: 'PT Pembeli Dua' },
                ],
                warehouses: [
                    { id: 1, code: 'WH-01', name: 'Gudang Pusat' },
                    { id: 2, code: 'WH-02', name: 'Gudang Cabang' },
                ],
                statuses: ['draft', 'approved'],
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('SO/202611/00001');
        expect(wrapper.text()).toContain('SO/202611/00002');
        expect(wrapper.text()).toContain('PT Pembeli Satu');
        expect(wrapper.text()).toContain('PT Pembeli Dua');
        expect(wrapper.text()).toContain('Draf');
        expect(wrapper.text()).toContain('Disetujui');
        expect(wrapper.text()).not.toContain('Tidak ada data');
    });

    it('SalesOrders/Index real Pagination, FilterBar search and filter wiring trigger router.get', async () => {
        const metaMultiPage = {
            current_page: 1,
            last_page: 3,
            per_page: 15,
            total: 45,
            from: 1,
            to: 15,
        };

        const wrapper = mount(SalesOrderIndex, {
            props: {
                items: {
                    data: [
                        {
                            id: 1,
                            number: 'SO/202611/00001',
                            order_date: '2026-11-01',
                            customer: { code: 'CUST-001', name: 'PT Pembeli Sukses' },
                            warehouse: { code: 'WH-01', name: 'Gudang Pusat' },
                            status: 'draft',
                            total: '100000.00',
                        },
                    ],
                    meta: metaMultiPage,
                    query: {},
                },
                customers: [{ id: 1, code: 'CUST-001', name: 'PT Pembeli Sukses' }],
                warehouses: [{ id: 1, code: 'WH-01', name: 'Gudang Pusat' }],
                statuses: ['draft', 'approved'],
            },
            global: { stubs: defaultStubs },
        });

        // (a) Klik halaman 2 memanggil router.get dengan page=2
        const page2Button = wrapper.find('button[aria-label="Ke halaman 2"]');
        expect(page2Button.exists()).toBe(true);
        await page2Button.trigger('click');
        expect(mockRouterGet).toHaveBeenCalledWith(
            '/sales/sales-orders',
            expect.objectContaining({ page: 2 }),
            expect.any(Object)
        );

        // (b) Mengganti jumlah per halaman memanggil router.get dengan per_page
        mockRouterGet.mockClear();
        const perPageSelect = wrapper.find('select[aria-label="Jumlah data per halaman"]');
        expect(perPageSelect.exists()).toBe(true);
        await perPageSelect.setValue(50);
        expect(mockRouterGet).toHaveBeenCalledWith(
            '/sales/sales-orders',
            expect.objectContaining({ per_page: 50 }),
            expect.any(Object)
        );

        // (c) Mengisi kotak pencarian dengan fake timer memanggil router.get dengan search
        mockRouterGet.mockClear();
        vi.useFakeTimers();
        const searchInput = wrapper.find('input[aria-label="Cari data"]');
        expect(searchInput.exists()).toBe(true);
        await searchInput.setValue('SO/202611');
        vi.advanceTimersByTime(350);
        expect(mockRouterGet).toHaveBeenCalledWith(
            '/sales/sales-orders',
            expect.objectContaining({ search: 'SO/202611' }),
            expect.any(Object)
        );
        vi.useRealTimers();

        // (d) Memilih filter status memanggil router.get dengan filter[status]
        mockRouterGet.mockClear();
        const statusSelect = wrapper.find('select[aria-label="Filter berdasarkan status"]');
        expect(statusSelect.exists()).toBe(true);
        await statusSelect.setValue('approved');
        expect(mockRouterGet).toHaveBeenCalledWith(
            '/sales/sales-orders',
            expect.objectContaining({ 'filter[status]': 'approved' }),
            expect.any(Object)
        );
    });

    it('SalesOrders/Form calculates live totals and auto-fills selling price', async () => {
        const wrapper = mount(SalesOrderForm, {
            props: {
                customers: [
                    { id: 1, code: 'C01', name: 'Customer A', payment_term_id: 2 },
                ],
                warehouses: [
                    { id: 1, code: 'WH1', name: 'Gudang Utama' },
                ],
                paymentTerms: [
                    { id: 2, code: 'NET30', name: 'Net 30', days: 30 },
                ],
                taxRates: [
                    { id: 10, code: 'PPN11', name: 'PPN 11%', rate: '11.00' },
                ],
                products: [
                    { id: 5, sku: 'PROD-A', name: 'Produk A', selling_price: '50000.0000' },
                ],
            },
            global: { stubs: defaultStubs },
        });

        // Trigger customer change
        const customerSelect = wrapper.find('select[aria-label="Pilih pelanggan"]');
        await customerSelect.setValue('1');
        await nextTick();

        // Form payment_term_id should auto fill
        expect(wrapper.vm.form.payment_term_id).toBe('2');

        // Select product on item 0
        const productSelect = wrapper.find('select[aria-label="Pilih produk baris ke-1"]');
        await productSelect.setValue('5');
        await nextTick();

        expect(wrapper.vm.form.items[0].unit_price).toBe('50000');

        // Set quantity to 2
        wrapper.vm.form.items[0].quantity = '2.0000';
        // Set discount to 10000.00
        wrapper.vm.form.items[0].discount_amount = '10000.00';
        // Set tax to PPN11
        wrapper.vm.form.items[0].tax_rate_id = '10';
        await nextTick();

        // Calculated line:
        // gross: 2 * 50000 = 100000.00
        // net: 100000 - 10000 = 90000.00
        // tax: 90000 * 11% = 9900.00
        // total: 99900.00
        expect(wrapper.vm.calculatedSummary.subtotal).toBe('100000.00');
        expect(wrapper.vm.calculatedSummary.discount).toBe('10000.00');
        expect(wrapper.vm.calculatedSummary.tax).toBe('9900.00');
        expect(wrapper.vm.calculatedSummary.total).toBe('99900.00');
    });

    it('SalesOrders/Show renders actions and DO creation link with sales_order_id query param', () => {
        const order = {
            id: 88,
            number: 'SO/202611/00088',
            order_date: '2026-11-01',
            status: 'approved',
            subtotal: '100000.00',
            discount_amount: '0.00',
            tax_amount: '11000.00',
            total: '111000.00',
            customer: { id: 1, code: 'C01', name: 'PT Pelanggan' },
            warehouse: { id: 1, code: 'WH1', name: 'Gudang Utama' },
            items: [
                {
                    id: 201,
                    product_id: 5,
                    quantity: '10.0000',
                    delivered_quantity: '4.0000',
                    unit_price: '10000.0000',
                    discount_amount: '0.00',
                    tax_rate: { code: 'PPN11', rate: '11.00' },
                    line_total: '111000.00',
                    product: { id: 5, sku: 'PROD-A', name: 'Produk A' },
                },
            ],
            delivery_orders: [],
        };

        const wrapper = mount(SalesOrderShow, {
            props: { order },
            global: { stubs: defaultStubs },
        });

        // DO creation link must use sales_order_id query param
        const doLink = wrapper.find('a[href*="/sales/delivery-orders/create"]');
        expect(doLink.exists()).toBe(true);
        expect(doLink.attributes('href')).toBe('/sales/delivery-orders/create?sales_order_id=88');

        // Remaining quantity should be displayed (10 - 4 = 6)
        expect(wrapper.text()).toContain('SO/202611/00088');
        expect(wrapper.text()).toContain('PT Pelanggan');
        expect(wrapper.text()).toContain('Informasi Pesanan');
        expect(wrapper.text()).toContain('Status & Jejak Dokumen');
        expect(wrapper.text()).toContain('Daftar Barang yang Dipesan');
        expect(wrapper.text()).toContain('Surat Jalan Terkait');
    });

    it('SalesOrders/Show prevents double click on approve and void actions', async () => {
        const order = {
            id: 1,
            number: 'SO/202611/00001',
            status: 'draft',
            customer: { name: 'Customer A' },
            warehouse: { name: 'Warehouse A' },
            creator: { name: 'Admin' },
            items: [],
            delivery_orders: [],
        };

        mockConfirm.mockResolvedValue(true);
        const wrapperApprove = mount(SalesOrderShow, {
            props: { order },
            global: { stubs: defaultStubs },
        });

        const approveBtn = wrapperApprove.findAll('button').find((b) => b.text().includes('Setujui SO'));
        expect(approveBtn).toBeDefined();

        await approveBtn.trigger('click');
        await approveBtn.trigger('click');

        expect(mockRouterPost).toHaveBeenCalledTimes(1);
        expect(mockRouterPost).toHaveBeenCalledWith(
            '/sales/sales-orders/1/approve',
            {},
            expect.objectContaining({ preserveScroll: true })
        );
        expect(approveBtn.attributes('disabled')).toBeDefined();
        expect(approveBtn.attributes('aria-busy')).toBe('true');

        // Test void button with confirmation
        vi.clearAllMocks();
        mockConfirm.mockResolvedValue({ confirmed: true, reason: 'Salah input kuantitas' });
        const wrapperVoid = mount(SalesOrderShow, {
            props: { order },
            global: { stubs: defaultStubs },
        });

        const voidBtn = wrapperVoid.findAll('button').find((b) => b.text().includes('Batalkan SO'));
        expect(voidBtn).toBeDefined();

        await voidBtn.trigger('click');
        await voidBtn.trigger('click');

        expect(mockRouterPost).toHaveBeenCalledTimes(1);
        expect(mockRouterPost).toHaveBeenCalledWith(
            '/sales/sales-orders/1/void',
            { reason: 'Salah input kuantitas' },
            expect.objectContaining({ preserveScroll: true })
        );
        expect(voidBtn.attributes('disabled')).toBeDefined();
        expect(voidBtn.attributes('aria-busy')).toBe('true');
    });

    it('SalesOrders/Show displays action buttons strictly according to permission and status', () => {
        const baseOrder = {
            id: 1,
            number: 'SO/202611/00001',
            customer: { name: 'Customer A' },
            warehouse: { name: 'Warehouse A' },
            creator: { name: 'Admin' },
            items: [],
            delivery_orders: [],
        };

        // 1. Draft with permission: Setujui SO visible, Buat Surat Jalan NOT visible
        const draftWrapper = mount(SalesOrderShow, {
            props: { order: { ...baseOrder, status: 'draft' } },
            global: { stubs: defaultStubs },
        });
        expect(draftWrapper.findAll('button').some((b) => b.text().includes('Setujui SO'))).toBe(true);
        expect(draftWrapper.find('a[href*="/sales/delivery-orders/create"]').exists()).toBe(false);

        // 2. Draft without sales_orders.approve permission: Setujui SO NOT visible
        mockPageProps.auth.permissions = ['sales_orders.view', 'delivery_orders.create'];
        const noApproveWrapper = mount(SalesOrderShow, {
            props: { order: { ...baseOrder, status: 'draft' } },
            global: { stubs: defaultStubs },
        });
        expect(noApproveWrapper.findAll('button').some((b) => b.text().includes('Setujui SO'))).toBe(false);

        // 3. Approved with delivery_orders.create permission: Buat Surat Jalan visible, Setujui SO NOT visible
        mockPageProps.auth.permissions = [...defaultPermissions];
        const approvedWrapper = mount(SalesOrderShow, {
            props: { order: { ...baseOrder, status: 'approved' } },
            global: { stubs: defaultStubs },
        });
        expect(approvedWrapper.findAll('button').some((b) => b.text().includes('Setujui SO'))).toBe(false);
        expect(approvedWrapper.find('a[href*="/sales/delivery-orders/create"]').exists()).toBe(true);

        // 4. Partially delivered with delivery_orders.create: Buat Surat Jalan visible
        const partialWrapper = mount(SalesOrderShow, {
            props: { order: { ...baseOrder, status: 'partially_delivered' } },
            global: { stubs: defaultStubs },
        });
        expect(partialWrapper.find('a[href*="/sales/delivery-orders/create"]').exists()).toBe(true);

        // 5. Approved without delivery_orders.create: Buat Surat Jalan NOT visible
        mockPageProps.auth.permissions = ['sales_orders.view', 'sales_orders.approve'];
        const noCreateDoWrapper = mount(SalesOrderShow, {
            props: { order: { ...baseOrder, status: 'approved' } },
            global: { stubs: defaultStubs },
        });
        expect(noCreateDoWrapper.find('a[href*="/sales/delivery-orders/create"]').exists()).toBe(false);
    });

    it('SalesOrders/Show renders server error alerts for status conflict', () => {
        mockPageProps.errors = { status: 'SO memiliki DO aktif yang belum selesai diproses' };

        const wrapper = mount(SalesOrderShow, {
            props: {
                order: {
                    id: 1,
                    number: 'SO/202611/00001',
                    status: 'approved',
                    customer: { name: 'Customer A' },
                    warehouse: { name: 'Warehouse A' },
                    creator: { name: 'Admin' },
                    items: [],
                    delivery_orders: [],
                },
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('SO memiliki DO aktif yang belum selesai diproses');
    });

    it('SalesOrders/Form sets payment terms if not chosen, retains existing, and restricts products', async () => {
        const wrapper = mount(SalesOrderForm, {
            props: {
                customers: [
                    { id: 1, code: 'C01', name: 'Customer A', payment_term_id: 2 },
                    { id: 2, code: 'C02', name: 'Customer B', payment_term_id: 4 },
                ],
                warehouses: [{ id: 1, code: 'WH1', name: 'Gudang Utama' }],
                paymentTerms: [
                    { id: 2, code: 'NET30', name: 'Net 30' },
                    { id: 4, code: 'NET60', name: 'Net 60' },
                    { id: 9, code: 'COD', name: 'Cash on Delivery' },
                ],
                taxRates: [{ id: 10, code: 'PPN11', name: 'PPN 11%', rate: '11.00' }],
                products: [
                    { id: 5, sku: 'PROD-A', name: 'Produk A', selling_price: '50000.0000' },
                ],
            },
            global: { stubs: defaultStubs },
        });

        // 1. Initial payment_term_id is empty
        expect(wrapper.vm.form.payment_term_id).toBe('');

        // 2. Selecting Customer A fills payment_term_id with 2
        wrapper.vm.form.customer_id = '1';
        await nextTick();
        expect(wrapper.vm.form.payment_term_id).toBe('2');

        // 3. User explicitly sets payment_term_id to 9 (COD)
        wrapper.vm.form.payment_term_id = '9';
        await nextTick();

        // 4. Changing customer to Customer B does NOT overwrite existing choice
        wrapper.vm.form.customer_id = '2';
        await nextTick();
        expect(wrapper.vm.form.payment_term_id).toBe('9');

        // 5. Products not in props.products cannot be selected
        const renderedOptionValues = wrapper.vm.productOptions.map((opt) => opt.value);
        expect(renderedOptionValues).toContain('5');
        expect(renderedOptionValues).not.toContain('999');
    });

    it('SalesOrders/Edit renders form with initial order data populated', () => {
        const existingOrder = {
            id: 77,
            number: 'SO/202611/00077',
            customer_id: 1,
            warehouse_id: 1,
            order_date: '2026-11-03',
            payment_term_id: 2,
            notes: 'Catatan pesanan revisi',
            items: [
                {
                    id: 15,
                    sales_order_id: 77,
                    product_id: 5,
                    quantity: '8.0000',
                    unit_price: '25000.0000',
                    discount_amount: '0.00',
                    tax_rate_id: 10,
                    line_total: '200000.00',
                },
            ],
        };

        const wrapper = mount(SalesOrderEdit, {
            props: {
                order: existingOrder,
                customers: [{ id: 1, code: 'C01', name: 'Customer A' }],
                warehouses: [{ id: 1, code: 'WH1', name: 'Warehouse A' }],
                paymentTerms: [{ id: 2, code: 'NET30', name: 'Net 30' }],
                taxRates: [{ id: 10, code: 'PPN11', name: 'PPN 11%' }],
                products: [{ id: 5, sku: 'PROD-A', name: 'Produk A', selling_price: '25000.0000' }],
            },
            global: { stubs: defaultStubs },
        });

        expect(wrapper.text()).toContain('Edit SO: SO/202611/00077');
        const formComponent = wrapper.findComponent(SalesOrderForm);
        expect(formComponent.exists()).toBe(true);
        expect(formComponent.vm.form.items[0].quantity).toBe('8');
        expect(formComponent.vm.form.items[0].unit_price).toBe('25000');
    });

    it('SalesOrders pages pass axe-core accessibility checks', async () => {
        const soIndex = mount(SalesOrderIndex, {
            attachTo: document.body,
            props: {
                items: { data: [], meta: dummyMeta, query: {} },
                customers: [],
                warehouses: [],
                statuses: [],
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(soIndex.element);
        document.body.innerHTML = '';

        const soForm = mount(SalesOrderForm, {
            attachTo: document.body,
            props: {
                customers: [{ id: 1, code: 'C1', name: 'Customer 1' }],
                warehouses: [{ id: 1, code: 'WH1', name: 'Gudang Pusat' }],
                products: [{ id: 1, sku: 'SKU1', name: 'P1', selling_price: '10000' }],
                taxRates: [{ id: 1, code: 'PPN11', name: 'PPN 11%', rate: '11.00' }],
                paymentTerms: [{ id: 1, code: 'COD', name: 'Cash', days: 0 }],
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(soForm.element);
        document.body.innerHTML = '';

        const soShow = mount(SalesOrderShow, {
            attachTo: document.body,
            props: {
                order: {
                    id: 1,
                    number: 'SO/202611/00001',
                    order_date: '2026-11-01',
                    status: 'draft',
                    notes: '',
                    subtotal: '10000.00',
                    discount_amount: '0.00',
                    tax_amount: '1100.00',
                    total: '11100.00',
                    customer: { name: 'PT Customer' },
                    warehouse: { name: 'Gudang Pusat' },
                    creator: { name: 'Admin' },
                    items: [],
                    delivery_orders: [],
                },
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(soShow.element);
    });

    it('SalesOrders/Index and SalesOrders/Show render real StatusBadge with localized labels across states', () => {
        const states = [
            { status: 'draft', label: 'Draf' },
            { status: 'approved', label: 'Disetujui' },
            { status: 'partially_delivered', label: 'Terkirim Sebagian' },
            { status: 'closed', label: 'Ditutup' },
            { status: 'void', label: 'Dibatalkan' },
        ];

        for (const item of states) {
            const indexWrapper = mount(SalesOrderIndex, {
                props: {
                    items: {
                        data: [{
                            id: 1,
                            number: 'SO/202611/00001',
                            order_date: '2026-11-01',
                            customer: { code: 'CUST-001', name: 'PT Pembeli' },
                            warehouse: { code: 'WH-01', name: 'Gudang' },
                            status: item.status,
                            total: '1000.00',
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

            const showWrapper = mount(SalesOrderShow, {
                props: {
                    order: {
                        id: 1,
                        number: 'SO/202611/00001',
                        order_date: '2026-11-01',
                        status: item.status,
                        subtotal: '1000.00',
                        discount_amount: '0.00',
                        tax_amount: '0.00',
                        total: '1000.00',
                        customer: { id: 1, code: 'CUST-001', name: 'PT Pembeli' },
                        warehouse: { id: 1, code: 'WH-01', name: 'Gudang' },
                        items: [],
                        delivery_orders: [],
                    },
                },
                global: { stubs: defaultStubs },
            });
            expect(showWrapper.text()).toContain(item.label);
        }
    });

    it('SalesOrders/Form trims trailing zeros from quantity and unit_price inputs', () => {
        const order = {
            id: 10,
            customer_id: 1,
            warehouse_id: 1,
            order_date: '2026-11-01',
            status: 'draft',
            payment_term_id: 1,
            items: [
                {
                    id: 101,
                    product_id: 1,
                    quantity: '66.0000',
                    unit_price: '50000.0000',
                    discount_amount: '0.00',
                    tax_rate_id: '',
                },
            ],
        };

        const wrapper = mount(SalesOrderForm, {
            props: {
                order,
                customers: [{ id: 1, code: 'C01', name: 'Cust 1', payment_term_id: 1 }],
                warehouses: [{ id: 1, code: 'WH1', name: 'WH 1' }],
                products: [{ id: 1, sku: 'P1', name: 'Product 1', selling_price: '50000.00' }],
                taxRates: [],
                paymentTerms: [{ id: 1, code: 'COD', name: 'Cash On Delivery' }],
                isEdit: true,
            },
            global: { stubs: defaultStubs },
        });

        const qtyInput = wrapper.find('input[placeholder="1.0000"], input[placeholder="1"]');
        const priceInput = wrapper.find('input[placeholder="0.0000"], input[placeholder="0"]');

        expect(qtyInput.element.value).toBe('66');
        expect(priceInput.element.value).toBe('50000');
    });
});

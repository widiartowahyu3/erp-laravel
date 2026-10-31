import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h } from 'vue';
import axe from 'axe-core';
import Button from '../Components/ui/Button.vue';
import IconButton from '../Components/ui/IconButton.vue';
import TextInput from '../Components/ui/TextInput.vue';
import PasswordInput from '../Components/ui/PasswordInput.vue';
import Textarea from '../Components/ui/Textarea.vue';
import Select from '../Components/ui/Select.vue';
import Checkbox from '../Components/ui/Checkbox.vue';
import Switch from '../Components/ui/Switch.vue';
import FormField from '../Components/ui/FormField.vue';
import Alert from '../Components/ui/Alert.vue';
import Badge from '../Components/ui/Badge.vue';
import StatusBadge from '../Components/ui/StatusBadge.vue';
import DataTable from '../Components/ui/DataTable.vue';
import Pagination from '../Components/ui/Pagination.vue';
import FilterBar from '../Components/ui/FilterBar.vue';
import EmptyState from '../Components/ui/EmptyState.vue';
import ErrorState from '../Components/ui/ErrorState.vue';
import LoadingState from '../Components/ui/LoadingState.vue';
import Styleguide from '../Pages/Dev/Styleguide.vue';
import UnitForm from '../Pages/MasterData/Units/Form.vue';
import UnitIndex from '../Pages/MasterData/Units/Index.vue';
import ProductCategoryForm from '../Pages/MasterData/ProductCategories/Form.vue';
import ProductCategoryIndex from '../Pages/MasterData/ProductCategories/Index.vue';
import WarehouseForm from '../Pages/MasterData/Warehouses/Form.vue';
import WarehouseIndex from '../Pages/MasterData/Warehouses/Index.vue';
import TaxRateForm from '../Pages/MasterData/TaxRates/Form.vue';
import TaxRateIndex from '../Pages/MasterData/TaxRates/Index.vue';
import PaymentTermForm from '../Pages/MasterData/PaymentTerms/Form.vue';
import PaymentTermIndex from '../Pages/MasterData/PaymentTerms/Index.vue';
import SupplierForm from '../Pages/MasterData/Suppliers/Form.vue';
import SupplierIndex from '../Pages/MasterData/Suppliers/Index.vue';
import CustomerForm from '../Pages/MasterData/Customers/Form.vue';
import CustomerIndex from '../Pages/MasterData/Customers/Index.vue';
import ProductForm from '../Pages/MasterData/Products/Form.vue';
import ProductIndex from '../Pages/MasterData/Products/Index.vue';
import PasswordEdit from '../Pages/Account/Password.vue';
import StockAdjustmentIndex from '../Pages/Inventory/StockAdjustments/Index.vue';
import StockAdjustmentForm from '../Pages/Inventory/StockAdjustments/Form.vue';
import StockAdjustmentShow from '../Pages/Inventory/StockAdjustments/Show.vue';
import StockBalanceIndex from '../Pages/Inventory/StockBalances/Index.vue';
import StockMovementIndex from '../Pages/Inventory/StockMovements/Index.vue';
import StockCardIndex from '../Pages/Inventory/StockCard/Index.vue';
import PurchaseOrderIndex from '../Pages/Purchasing/PurchaseOrders/Index.vue';
import PurchaseOrderForm from '../Pages/Purchasing/PurchaseOrders/Form.vue';
import PurchaseOrderShow from '../Pages/Purchasing/PurchaseOrders/Show.vue';
import GoodsReceiptIndex from '../Pages/Purchasing/GoodsReceipts/Index.vue';
import GoodsReceiptForm from '../Pages/Purchasing/GoodsReceipts/Form.vue';
import GoodsReceiptShow from '../Pages/Purchasing/GoodsReceipts/Show.vue';

afterEach(() => {
    document.body.innerHTML = '';
});

async function expectNoViolations(element) {
    const results = await axe.run(element, {
        rules: {
            // Contrast is mathematically tested in theme-contrast.test.js because jsdom lacks layout/CSS rendering
            'color-contrast': { enabled: false },
            // In headless JSDOM, region / landmark rules can report on partial component snippets
            region: { enabled: false },
        },
    });

    const severeViolations = results.violations.filter((v) =>
        ['critical', 'serious'].includes(v.impact)
    );

    if (severeViolations.length > 0) {
        console.error('A11y Violations:', JSON.stringify(severeViolations, null, 2));
    }

    expect(severeViolations).toHaveLength(0);
}

describe('Automated Accessibility (axe-core)', () => {
    it('Button passes a11y audit', async () => {
        const wrapper = mount(Button, {
            attachTo: document.body,
            slots: { default: 'Kirim Dokumen' },
        });
        await expectNoViolations(wrapper.element);
    });

    it('IconButton with ariaLabel passes a11y audit', async () => {
        const wrapper = mount(IconButton, {
            attachTo: document.body,
            props: { ariaLabel: 'Buka pengaturan akun' },
            slots: { default: h('span', '⚙') },
        });
        await expectNoViolations(wrapper.element);
    });

    it('FormField with TextInput passes a11y audit', async () => {
        const wrapper = mount(FormField, {
            attachTo: document.body,
            props: { label: 'Alamat Email', hint: 'Gunakan email kantor', required: true },
            slots: {
                default: (slotProps) =>
                    h(TextInput, {
                        id: slotProps.id,
                        invalid: slotProps.invalid,
                        describedBy: slotProps.describedBy,
                        type: 'email',
                        required: true,
                    }),
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('FormField with PasswordInput passes a11y audit', async () => {
        const wrapper = mount(FormField, {
            attachTo: document.body,
            props: { label: 'Kata Sandi Baru', required: true },
            slots: {
                default: (slotProps) =>
                    h(PasswordInput, {
                        id: slotProps.id,
                        invalid: slotProps.invalid,
                        describedBy: slotProps.describedBy,
                        required: true,
                    }),
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('FormField with Select passes a11y audit', async () => {
        const wrapper = mount(FormField, {
            attachTo: document.body,
            props: { label: 'Pilih Gudang', required: true },
            slots: {
                default: (slotProps) =>
                    h(Select, {
                        id: slotProps.id,
                        invalid: slotProps.invalid,
                        describedBy: slotProps.describedBy,
                        options: [
                            { value: 'g1', label: 'Gudang Pusat' },
                            { value: 'g2', label: 'Gudang Cabang' },
                        ],
                    }),
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('FormField with Textarea passes a11y audit', async () => {
        const wrapper = mount(FormField, {
            attachTo: document.body,
            props: { label: 'Catatan Pengiriman' },
            slots: {
                default: (slotProps) =>
                    h(Textarea, {
                        id: slotProps.id,
                        invalid: slotProps.invalid,
                        describedBy: slotProps.describedBy,
                    }),
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('Checkbox with label passes a11y audit', async () => {
        const wrapper = mount(Checkbox, {
            attachTo: document.body,
            props: {
                id: 'test-chk',
                label: 'Setujui Syarat & Ketentuan',
                description: 'Saya menyatakan telah membaca aturan operasional',
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('Switch with label passes a11y audit', async () => {
        const wrapper = mount(Switch, {
            attachTo: document.body,
            props: {
                id: 'test-switch',
                label: 'Otomatisasi Posting Jurnal',
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('Alert variants pass a11y audit', async () => {
        for (const variant of ['info', 'success', 'warning', 'danger']) {
            document.body.innerHTML = '';
            const wrapper = mount(Alert, {
                attachTo: document.body,
                props: { variant, title: `Judul ${variant}` },
                slots: { default: 'Pesan informasi sistem' },
            });
            await expectNoViolations(wrapper.element);
        }
    });

    it('StatusBadge for document states passes a11y audit', async () => {
        const statuses = ['draft', 'approved', 'posted', 'void', 'paid', 'active'];
        for (const status of statuses) {
            document.body.innerHTML = '';
            const wrapper = mount(StatusBadge, {
                attachTo: document.body,
                props: { status },
            });
            await expectNoViolations(wrapper.element);
        }
    });

    it('DataTable with rows passes a11y audit', async () => {
        const wrapper = mount(DataTable, {
            attachTo: document.body,
            props: {
                columns: [
                    { key: 'code', label: 'Kode Produk', sortable: true },
                    { key: 'name', label: 'Nama Produk' },
                ],
                items: [
                    { id: 1, code: 'SKU-001', name: 'Barang A' },
                    { id: 2, code: 'SKU-002', name: 'Barang B' },
                ],
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('Pagination component passes a11y audit', async () => {
        const wrapper = mount(Pagination, {
            attachTo: document.body,
            props: {
                meta: {
                    current_page: 2,
                    per_page: 10,
                    total: 50,
                    last_page: 5,
                    from: 11,
                    to: 20,
                },
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('FilterBar component passes a11y audit', async () => {
        const wrapper = mount(FilterBar, {
            attachTo: document.body,
            props: {
                modelValue: 'kata kunci',
                hasActiveFilters: true,
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('State views pass a11y audit', async () => {
        const empty = mount(EmptyState, { attachTo: document.body, props: { title: 'Kosong', description: 'Data kosong' } });
        await expectNoViolations(empty.element);
        document.body.innerHTML = '';

        const error = mount(ErrorState, { attachTo: document.body, props: { title: 'Galat', message: 'Terjadi kesalahan' } });
        await expectNoViolations(error.element);
        document.body.innerHTML = '';

        const loading = mount(LoadingState, { attachTo: document.body, props: { message: 'Memuat...' } });
        await expectNoViolations(loading.element);
    });

    it('Full Styleguide page passes axe-core audit with 0 critical or serious violations', async () => {
        const wrapper = mount(Styleguide, {
            attachTo: document.body,
        });
        await expectNoViolations(wrapper.element);
    });

    const defaultStubs = {
        Head: true,
        Link: { template: '<a><slot /></a>' },
        AppLayout: { template: '<div><slot /></div>' },
        Can: { template: '<div><slot /></div>' },
    };

    const dummyMeta = {
        page: 1,
        current_page: 1,
        per_page: 25,
        total: 2,
        last_page: 1,
        from: 1,
        to: 2,
    };

    it('Master Data Unit Form (create & edit) passes axe-core audit', async () => {
        // Create mode
        const wrapperCreate = mount(UnitForm, {
            attachTo: document.body,
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperCreate.element);
        document.body.innerHTML = '';

        // Edit mode
        const wrapperEdit = mount(UnitForm, {
            attachTo: document.body,
            props: { unit: { id: 1, code: 'PCS', name: 'Pieces' }, isEdit: true },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperEdit.element);
    });

    it('Master Data Unit Index page passes axe-core audit', async () => {
        const wrapper = mount(UnitIndex, {
            attachTo: document.body,
            props: {
                items: {
                    data: [
                        { id: 1, code: 'PCS', name: 'Pieces', created_at: '2026-10-29T00:00:00Z' },
                        { id: 2, code: 'BOX', name: 'Box', created_at: '2026-10-29T00:00:00Z' },
                    ],
                    meta: dummyMeta,
                    query: { search: '', sort: 'code', direction: 'asc' },
                },
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(wrapper.element);
    });

    it('Master Data ProductCategory Form (create & edit) and Index pass axe-core audit', async () => {
        const parents = [{ id: 1, code: 'PAR1', name: 'Parent One' }];
        const wrapperCreate = mount(ProductCategoryForm, {
            attachTo: document.body,
            props: { parentCategories: parents },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperCreate.element);
        document.body.innerHTML = '';

        const wrapperEdit = mount(ProductCategoryForm, {
            attachTo: document.body,
            props: {
                category: { id: 2, code: 'SUB1', name: 'Sub One', parent_id: 1 },
                parentCategories: parents,
                isEdit: true,
            },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperEdit.element);
        document.body.innerHTML = '';

        const wrapperIndex = mount(ProductCategoryIndex, {
            attachTo: document.body,
            props: {
                items: {
                    data: [{ id: 1, code: 'CAT1', name: 'Cat One', parent: null, created_at: '2026-10-29T00:00:00Z' }],
                    meta: dummyMeta,
                    query: {},
                },
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(wrapperIndex.element);
    });

    it('Master Data Warehouse Form (create & edit) and Index pass axe-core audit', async () => {
        const wrapperCreate = mount(WarehouseForm, {
            attachTo: document.body,
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperCreate.element);
        document.body.innerHTML = '';

        const wrapperEdit = mount(WarehouseForm, {
            attachTo: document.body,
            props: {
                warehouse: { id: 1, code: 'WH1', name: 'Gudang Pusat', address: 'Jl. Utama', is_active: true },
                isEdit: true,
            },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperEdit.element);
        document.body.innerHTML = '';

        const wrapperIndex = mount(WarehouseIndex, {
            attachTo: document.body,
            props: {
                items: {
                    data: [{ id: 1, code: 'WH1', name: 'Gudang Pusat', address: 'Jl. Utama', is_active: true, created_at: '2026-10-29T00:00:00Z' }],
                    meta: dummyMeta,
                    query: {},
                },
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(wrapperIndex.element);
    });

    it('Master Data TaxRate Form (create & edit) and Index pass axe-core audit', async () => {
        const wrapperCreate = mount(TaxRateForm, {
            attachTo: document.body,
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperCreate.element);
        document.body.innerHTML = '';

        const wrapperEdit = mount(TaxRateForm, {
            attachTo: document.body,
            props: {
                taxRate: { id: 1, code: 'PPN11', name: 'PPN 11%', rate: '11.00', is_active: true },
                isEdit: true,
            },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperEdit.element);
        document.body.innerHTML = '';

        const wrapperIndex = mount(TaxRateIndex, {
            attachTo: document.body,
            props: {
                items: {
                    data: [{ id: 1, code: 'PPN11', name: 'PPN 11%', rate: '11.00', is_active: true, created_at: '2026-10-29T00:00:00Z' }],
                    meta: dummyMeta,
                    query: {},
                },
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(wrapperIndex.element);
    });

    it('Master Data PaymentTerm Form (create & edit) and Index pass axe-core audit', async () => {
        const wrapperCreate = mount(PaymentTermForm, {
            attachTo: document.body,
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperCreate.element);
        document.body.innerHTML = '';

        const wrapperEdit = mount(PaymentTermForm, {
            attachTo: document.body,
            props: {
                paymentTerm: { id: 1, code: 'NET30', name: 'Net 30', days: 30 },
                isEdit: true,
            },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperEdit.element);
        document.body.innerHTML = '';

        const wrapperIndex = mount(PaymentTermIndex, {
            attachTo: document.body,
            props: {
                items: {
                    data: [{ id: 1, code: 'NET30', name: 'Net 30', days: 30, created_at: '2026-10-29T00:00:00Z' }],
                    meta: dummyMeta,
                    query: {},
                },
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(wrapperIndex.element);
    });

    it('Master Data Supplier Form (create & edit) and Index pass axe-core audit', async () => {
        const terms = [{ id: 1, code: 'NET30', name: 'Net 30', days: 30 }];
        const wrapperCreate = mount(SupplierForm, {
            attachTo: document.body,
            props: { paymentTerms: terms },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperCreate.element);
        document.body.innerHTML = '';

        const wrapperEdit = mount(SupplierForm, {
            attachTo: document.body,
            props: {
                supplier: { id: 1, code: 'SUP1', name: 'PT Supplier', contact_name: 'Budi', phone: '08123', email: 'sup@example.com', address: 'Jl. Tes', tax_id: '123', payment_term_id: 1, is_active: true },
                paymentTerms: terms,
                isEdit: true,
            },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperEdit.element);
        document.body.innerHTML = '';

        const wrapperIndex = mount(SupplierIndex, {
            attachTo: document.body,
            props: {
                items: {
                    data: [{ id: 1, code: 'SUP1', name: 'PT Supplier', contact_name: 'Budi', phone: '08123', email: 'sup@example.com', is_active: true, created_at: '2026-10-29T00:00:00Z' }],
                    meta: dummyMeta,
                    query: {},
                },
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(wrapperIndex.element);
    });

    it('Master Data Customer Form (create & edit) and Index pass axe-core audit', async () => {
        const terms = [{ id: 1, code: 'NET30', name: 'Net 30', days: 30 }];
        const wrapperCreate = mount(CustomerForm, {
            attachTo: document.body,
            props: { paymentTerms: terms },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperCreate.element);
        document.body.innerHTML = '';

        const wrapperEdit = mount(CustomerForm, {
            attachTo: document.body,
            props: {
                customer: { id: 1, code: 'CUST1', name: 'PT Pelanggan', contact_name: 'Ani', phone: '08124', email: 'cust@example.com', address: 'Jl. Tes', tax_id: '456', credit_limit: '1000000.00', payment_term_id: 1, is_active: true },
                paymentTerms: terms,
                isEdit: true,
            },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperEdit.element);
        document.body.innerHTML = '';

        const wrapperIndex = mount(CustomerIndex, {
            attachTo: document.body,
            props: {
                items: {
                    data: [{ id: 1, code: 'CUST1', name: 'PT Pelanggan', contact_name: 'Ani', phone: '08124', email: 'cust@example.com', credit_limit: '1000000.00', is_active: true, created_at: '2026-10-29T00:00:00Z' }],
                    meta: dummyMeta,
                    query: {},
                },
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(wrapperIndex.element);
    });

    it('Master Data Product Form (create & edit) and Index pass axe-core audit', async () => {
        const cats = [{ id: 1, code: 'C1', name: 'Kategori 1' }];
        const units = [{ id: 1, code: 'PCS', name: 'Pieces' }];

        const wrapperCreate = mount(ProductForm, {
            attachTo: document.body,
            props: { categories: cats, units },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperCreate.element);
        document.body.innerHTML = '';

        const wrapperEdit = mount(ProductForm, {
            attachTo: document.body,
            props: {
                product: { id: 1, sku: 'SKU1', name: 'Produk Satu', barcode: '123', description: 'Desc', category_id: 1, base_unit_id: 1, selling_price: '50000.00', min_stock: '10.0000', track_stock: true, is_active: true },
                categories: cats,
                units,
                isEdit: true,
            },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        await expectNoViolations(wrapperEdit.element);
        document.body.innerHTML = '';

        const wrapperIndex = mount(ProductIndex, {
            attachTo: document.body,
            props: {
                items: {
                    data: [{ id: 1, sku: 'SKU1', name: 'Produk Satu', barcode: '123', category: { name: 'Kategori 1' }, baseUnit: { code: 'PCS' }, selling_price: '50000.00', min_stock: '10.0000', track_stock: true, is_active: true, created_at: '2026-10-29T00:00:00Z' }],
                    meta: dummyMeta,
                    query: {},
                },
                categories: cats,
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(wrapperIndex.element);
    });

    it('Account Password page passes axe-core audit', async () => {
        const wrapper = mount(PasswordEdit, {
            attachTo: document.body,
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(wrapper.element);
    });

    it('Inventory & Purchasing pages pass axe-core audit', async () => {
        // Stock Adjustments Index, Form, Show
        const adjIndex = mount(StockAdjustmentIndex, {
            attachTo: document.body,
            props: { items: { data: [], meta: dummyMeta, query: {} }, warehouses: [], statuses: [] },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(adjIndex.element);
        document.body.innerHTML = '';

        const adjForm = mount(StockAdjustmentForm, {
            attachTo: document.body,
            props: { warehouses: [{ id: 1, code: 'WH1', name: 'Gudang Pusat' }], products: [{ id: 1, sku: 'SKU1', name: 'P1', base_unit: { code: 'PCS' } }] },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(adjForm.element);
        document.body.innerHTML = '';

        const adjShow = mount(StockAdjustmentShow, {
            attachTo: document.body,
            props: {
                item: { id: 1, number: 'ADJ/202610/0001', date: '2026-10-31', status: 'draft', notes: '', warehouse: { name: 'Gudang Pusat' }, creator: { name: 'Admin' }, items: [] },
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(adjShow.element);
        document.body.innerHTML = '';

        // Stock Balances, Movements, Card
        const balIndex = mount(StockBalanceIndex, {
            attachTo: document.body,
            props: { items: { data: [], meta: dummyMeta, query: {} }, warehouses: [] },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(balIndex.element);
        document.body.innerHTML = '';

        const movIndex = mount(StockMovementIndex, {
            attachTo: document.body,
            props: { items: { data: [], meta: dummyMeta, query: {} }, warehouses: [], products: [] },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(movIndex.element);
        document.body.innerHTML = '';

        const cardIndex = mount(StockCardIndex, {
            attachTo: document.body,
            props: { card: null, warehouses: [], products: [] },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(cardIndex.element);
        document.body.innerHTML = '';

        // Purchase Orders Index, Form, Show
        const poIndex = mount(PurchaseOrderIndex, {
            attachTo: document.body,
            props: { items: { data: [], meta: dummyMeta, query: {} }, suppliers: [], warehouses: [], statuses: [] },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(poIndex.element);
        document.body.innerHTML = '';

        const poForm = mount(PurchaseOrderForm, {
            attachTo: document.body,
            props: {
                suppliers: [{ id: 1, code: 'S1', name: 'Supplier 1' }],
                warehouses: [{ id: 1, code: 'WH1', name: 'Gudang Pusat' }],
                products: [{ id: 1, sku: 'SKU1', name: 'P1', base_unit: { code: 'PCS' } }],
                taxRates: [{ id: 1, code: 'PPN11', name: 'PPN 11%', rate: '11.00' }],
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(poForm.element);
        document.body.innerHTML = '';

        const poShow = mount(PurchaseOrderShow, {
            attachTo: document.body,
            props: {
                order: { id: 1, number: 'PO/202610/0001', order_date: '2026-10-31', expected_date: '2026-11-05', status: 'draft', notes: '', subtotal: '10000.00', tax_total: '1100.00', grand_total: '11100.00', supplier: { name: 'PT Supplier' }, warehouse: { name: 'Gudang Pusat' }, creator: { name: 'Admin' }, items: [] },
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(poShow.element);
        document.body.innerHTML = '';

        // Goods Receipts Index, Form, Show
        const grIndex = mount(GoodsReceiptIndex, {
            attachTo: document.body,
            props: { items: { data: [], meta: dummyMeta, query: {} }, suppliers: [], warehouses: [], statuses: [] },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(grIndex.element);
        document.body.innerHTML = '';

        const grForm = mount(GoodsReceiptForm, {
            attachTo: document.body,
            props: {
                purchaseOrders: [{ id: 1, number: 'PO/001', supplier: { name: 'S1' }, warehouse: { name: 'WH1' }, items: [] }],
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(grForm.element);
        document.body.innerHTML = '';

        const grShow = mount(GoodsReceiptShow, {
            attachTo: document.body,
            props: {
                receipt: { id: 1, number: 'GR/202610/0001', receipt_date: '2026-10-31', status: 'draft', notes: '', supplier: { name: 'PT Supplier' }, warehouse: { name: 'Gudang Pusat' }, purchaseOrder: { id: 1, number: 'PO/202610/0001' }, creator: { name: 'Admin' }, items: [] },
            },
            global: { stubs: defaultStubs },
        });
        await expectNoViolations(grShow.element);
    });
});

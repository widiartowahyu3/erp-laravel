import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import { setMockPageProps, resetMockPageProps } from '@/test-setup';
import FlashRegion from '../Components/ui/FlashRegion.vue';
import Toast from '../Components/ui/Toast.vue';

import UnitsIndex from '@/Pages/MasterData/Units/Index.vue';
import ProductCategoriesIndex from '@/Pages/MasterData/ProductCategories/Index.vue';
import ProductsIndex from '@/Pages/MasterData/Products/Index.vue';
import WarehousesIndex from '@/Pages/MasterData/Warehouses/Index.vue';
import SuppliersIndex from '@/Pages/MasterData/Suppliers/Index.vue';
import CustomersIndex from '@/Pages/MasterData/Customers/Index.vue';
import TaxRatesIndex from '@/Pages/MasterData/TaxRates/Index.vue';
import PaymentTermsIndex from '@/Pages/MasterData/PaymentTerms/Index.vue';

const mockConfirm = vi.fn();
vi.mock('@/composables/useConfirm', () => ({
    useConfirm: () => ({
        confirm: mockConfirm,
    }),
}));

const masterDataModules = [
    { name: 'Units', component: UnitsIndex, deleteUrl: '/master/units/1', extraProps: {} },
    { name: 'ProductCategories', component: ProductCategoriesIndex, deleteUrl: '/master/product-categories/1', extraProps: {} },
    { name: 'Products', component: ProductsIndex, deleteUrl: '/master/products/1', extraProps: { categories: [] } },
    { name: 'Warehouses', component: WarehousesIndex, deleteUrl: '/master/warehouses/1', extraProps: {} },
    { name: 'Suppliers', component: SuppliersIndex, deleteUrl: '/master/suppliers/1', extraProps: {} },
    { name: 'Customers', component: CustomersIndex, deleteUrl: '/master/customers/1', extraProps: {} },
    { name: 'TaxRates', component: TaxRatesIndex, deleteUrl: '/master/tax-rates/1', extraProps: {} },
    { name: 'PaymentTerms', component: PaymentTermsIndex, deleteUrl: '/master/payment-terms/1', extraProps: {} },
];

describe('Master Data Delete Conflict & Flash Toast Handling', () => {
    beforeEach(() => {
        resetMockPageProps();
        vi.clearAllMocks();
    });

    it('FlashRegion displays error toast when flash.error is received from server', async () => {
        setMockPageProps({
            flash: {
                error: 'Tidak dapat menghapus data: Satuan ukuran masih direferensikan oleh produk.',
            },
        });

        const wrapper = mount(FlashRegion, {
            global: {
                stubs: {
                    Toast: false,
                    TransitionGroup: false,
                },
            },
        });

        await nextTick();

        const toast = wrapper.findComponent(Toast);
        expect(toast.exists()).toBe(true);
        expect(toast.attributes('role')).toBe('alert');
        expect(toast.text()).toContain('Galat');
        expect(toast.text()).toContain('Satuan ukuran masih direferensikan oleh produk');
    });

    it.each(masterDataModules)(
        '$name Index: rapid double-click on delete calls router.delete once and button shows busy/disabled',
        async ({ component, deleteUrl, extraProps }) => {
            mockConfirm.mockResolvedValue(true);
            setMockPageProps({
                auth: {
                    user: { id: 1, name: 'Admin' },
                    permissions: [
                        'units.view',
                        'units.delete',
                        'product_categories.view',
                        'product_categories.delete',
                        'products.view',
                        'products.delete',
                        'warehouses.view',
                        'warehouses.delete',
                        'suppliers.view',
                        'suppliers.delete',
                        'customers.view',
                        'customers.delete',
                        'tax_rates.view',
                        'tax_rates.delete',
                        'payment_terms.view',
                        'payment_terms.delete',
                    ],
                    roles: ['super_admin'],
                },
            });

            const wrapper = mount(component, {
                props: {
                    items: {
                        data: [{ id: 1, code: 'CODE1', name: 'Item 1', is_active: true }],
                        meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 },
                        links: [],
                    },
                    ...extraProps,
                },
                global: {
                    stubs: {
                        AppLayout: { template: '<div><slot /></div>' },
                        Head: true,
                        Link: { template: '<a><slot /></a>' },
                    },
                },
            });

            await nextTick();

            const deleteBtn = wrapper.findAll('button').find((b) => {
                const aria = b.attributes('aria-label') || '';
                return aria.toLowerCase().includes('hapus');
            });

            expect(deleteBtn).toBeDefined();

            // Trigger rapid double-click
            await deleteBtn.trigger('click');
            await deleteBtn.trigger('click');

            expect(router.delete).toHaveBeenCalledTimes(1);
            expect(router.delete).toHaveBeenCalledWith(
                deleteUrl,
                expect.objectContaining({ preserveScroll: true })
            );

            // While busy, button should be disabled and aria-busy
            expect(deleteBtn.attributes('disabled')).toBeDefined();
            expect(deleteBtn.attributes('aria-busy')).toBe('true');
        }
    );
});

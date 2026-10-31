import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import ProductsIndex from '../Pages/MasterData/Products/Index.vue';
import CustomersIndex from '../Pages/MasterData/Customers/Index.vue';
import Select from '../Components/ui/Select.vue';

describe('Master Data Filter Component Integration', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    const defaultMeta = {
        current_page: 1,
        from: 0,
        last_page: 1,
        path: 'http://localhost/items',
        per_page: 25,
        to: 0,
        total: 0,
    };

    it('triggers router.get with ?filter[category_id]=... and ?filter[is_active]=... when filters change on Products/Index', async () => {
        const wrapper = mount(ProductsIndex, {
            props: {
                items: {
                    data: [],
                    meta: defaultMeta,
                    query: {},
                },
                categories: [
                    { id: 10, code: 'CAT1', name: 'Kategori 1' },
                    { id: 20, code: 'CAT2', name: 'Kategori 2' },
                ],
            },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                    Head: true,
                    PageHeader: true,
                    Can: { template: '<div><slot /></div>' },
                    DataTable: true,
                    Pagination: true,
                    Button: true,
                    IconButton: true,
                },
            },
        });

        const selects = wrapper.findAllComponents(Select);
        expect(selects.length).toBe(2);

        // Select Category filter
        await selects[0].setValue('10');
        expect(router.get).toHaveBeenCalledWith(
            '/master/products',
            expect.objectContaining({ 'filter[category_id]': '10' }),
            expect.any(Object)
        );

        // Select Status filter
        await selects[1].setValue('1');
        expect(router.get).toHaveBeenCalledWith(
            '/master/products',
            expect.objectContaining({ 'filter[category_id]': '10', 'filter[is_active]': '1' }),
            expect.any(Object)
        );
    });

    it('triggers router.get with ?filter[is_active]=0 on Customers/Index', async () => {
        const wrapper = mount(CustomersIndex, {
            props: {
                items: {
                    data: [],
                    meta: defaultMeta,
                    query: {},
                },
            },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                    Head: true,
                    PageHeader: true,
                    Can: { template: '<div><slot /></div>' },
                    DataTable: true,
                    Pagination: true,
                    Button: true,
                    IconButton: true,
                },
            },
        });

        const select = wrapper.findComponent(Select);
        expect(select.exists()).toBe(true);

        await select.setValue('0');
        expect(router.get).toHaveBeenCalledWith(
            '/master/customers',
            expect.objectContaining({ 'filter[is_active]': '0' }),
            expect.any(Object)
        );
    });
});

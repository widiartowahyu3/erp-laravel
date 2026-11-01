import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { reactive } from 'vue';
import ProductForm from '../Pages/MasterData/Products/Form.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            auth: { user: { id: 1, name: 'Admin' }, permissions: ['products.update'] },
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
}));

const stubs = {
    Card: { template: '<div><slot /><slot name="title" /><slot name="description" /></div>' },
    Button: { template: '<button><slot /></button>' },
    FormField: {
        props: ['id', 'label', 'error'],
        template: '<div><label :for="id">{{ label }}</label><slot :id="id" :hasError="Boolean(error)" /><span v-if="error">{{ error }}</span></div>',
    },
    TextInput: {
        props: ['modelValue', 'id', 'placeholder'],
        emits: ['update:modelValue'],
        template: '<input :id="id" :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    },
    Select: {
        props: ['modelValue', 'options'],
        emits: ['update:modelValue'],
        template: '<select :value="modelValue"><option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option></select>',
    },
    Textarea: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)"></textarea>',
    },
};

describe('Product Form (Butir 2)', () => {
    it('trims trailing zeros from decimal form inputs in edit mode', () => {
        const product = {
            id: 1,
            sku: 'PROD-001',
            name: 'Produk Uji',
            category_id: 1,
            base_unit_id: 1,
            selling_price: '5000.00',
            min_stock: '5000.0000',
            track_stock: true,
            is_active: true,
        };

        const wrapper = mount(ProductForm, {
            props: {
                product,
                categories: [{ id: 1, code: 'CAT', name: 'Kategori' }],
                units: [{ id: 1, code: 'PCS', name: 'Pcs' }],
                isEdit: true,
            },
            global: { stubs },
        });

        const priceInput = wrapper.find('#product-price');
        const minStockInput = wrapper.find('#product-min-stock');

        expect(priceInput.element.value).toBe('5000');
        expect(minStockInput.element.value).toBe('5000');
    });
});

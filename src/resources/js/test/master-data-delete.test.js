import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, ref } from 'vue';
import FlashRegion from '../Components/ui/FlashRegion.vue';
import Toast from '../Components/ui/Toast.vue';
import * as inertia from '@inertiajs/vue3';

describe('Master Data Delete Conflict & Flash Toast Handling', () => {
    it('FlashRegion displays error toast when flash.error is received from server', async () => {
        const pageData = ref({
            props: {
                flash: {
                    error: 'Tidak dapat menghapus data: Satuan ukuran masih direferensikan oleh produk.',
                },
            },
        });

        vi.spyOn(inertia, 'usePage').mockImplementation(() => pageData.value);

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
});

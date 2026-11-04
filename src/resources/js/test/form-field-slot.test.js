import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import FormField from '../Components/ui/FormField.vue';
import TextInput from '../Components/ui/TextInput.vue';

// Master data, accounting and stock adjustment forms read hasError from this slot.
describe('FormField slot', () => {
    it.each([
        ['hasError', '<template #default="{ id, hasError }"><TextInput :id="id" :invalid="hasError" /></template>'],
        ['invalid', '<template #default="{ id, invalid }"><TextInput :id="id" :invalid="invalid" /></template>'],
    ])('marks the input invalid through %s', (_name, slot) => {
        const wrapper = mount({
            components: { FormField, TextInput },
            template: `<FormField id="f" label="Kode" error="Wajib diisi.">${slot}</FormField>`,
        });

        expect(wrapper.find('input').attributes('aria-invalid')).toBe('true');
    });
});

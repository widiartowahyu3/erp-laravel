import { ref, onMounted, nextTick, watch } from 'vue';

/**
 * Composable to automatically focus the first interactive form field,
 * or the first field with a validation error, without using the autofocus HTML attribute.
 *
 * @param {import('vue').Ref|Function|Object} [errorsSource] Optional reactive source of form errors
 * @returns {{ formRef: import('vue').Ref<HTMLElement|null>, focusField: () => void }}
 */
export function useFocusFirstField(errorsSource = null) {
    const formRef = ref(null);

    const focusField = () => {
        nextTick(() => {
            if (!formRef.value) return;

            // 1. Look for first field marked as invalid
            const errorElement = formRef.value.querySelector(
                'input[aria-invalid="true"]:not([disabled]), select[aria-invalid="true"]:not([disabled]), textarea[aria-invalid="true"]:not([disabled]), [aria-invalid="true"]'
            );

            if (errorElement && typeof errorElement.focus === 'function') {
                errorElement.focus();
                return;
            }

            // 2. Otherwise focus first interactive focusable control
            const firstControl = formRef.value.querySelector(
                'input:not([type="hidden"]):not([disabled]):not([readonly]), select:not([disabled]):not([readonly]), textarea:not([disabled]):not([readonly])'
            );

            if (firstControl && typeof firstControl.focus === 'function') {
                firstControl.focus();
            }
        });
    };

    onMounted(() => {
        focusField();
    });

    if (errorsSource) {
        watch(
            () => (typeof errorsSource === 'function' ? errorsSource() : errorsSource),
            (newErrors) => {
                if (newErrors && Object.keys(newErrors).length > 0) {
                    focusField();
                }
            },
            { deep: true }
        );
    }

    return {
        formRef,
        focusField,
    };
}

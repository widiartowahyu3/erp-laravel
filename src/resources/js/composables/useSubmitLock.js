import { ref } from 'vue';

export function useSubmitLock({ throttleMs = 1000 } = {}) {
    const busy = ref(false);
    let lastAcceptedTime = 0;

    function submit(send, options = {}) {
        if (busy.value) {
            return false;
        }

        const now = Date.now();
        if (now - lastAcceptedTime < throttleMs) {
            return false;
        }

        busy.value = true;
        lastAcceptedTime = now;

        let isDone = false;
        const finalize = () => {
            if (!isDone) {
                isDone = true;
                busy.value = false;
            }
        };

        const wrappedOptions = {
            ...options,
            onFinish: () => {
                finalize();
                options.onFinish?.();
            },
            onError: (errors) => {
                if (!options.onFinish) {
                    finalize();
                }
                options.onError?.(errors);
            },
            onCancel: () => {
                if (!options.onFinish) {
                    finalize();
                }
                options.onCancel?.();
            },
        };

        try {
            send(wrappedOptions);
            return true;
        } catch (error) {
            busy.value = false;
            throw error;
        }
    }

    return {
        busy,
        submit,
    };
}

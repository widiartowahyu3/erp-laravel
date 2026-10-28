import { ref, computed } from 'vue';

const theme = ref('light');

function getInitialTheme() {
    try {
        if (typeof window !== 'undefined') {
            const stored = localStorage.getItem('theme');
            if (stored === 'light' || stored === 'dark') {
                return stored;
            }
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                return 'dark';
            }
        }
    } catch {
        // Fallback on storage errors
    }
    return 'light';
}

theme.value = getInitialTheme();

export function useTheme() {
    const isDark = computed(() => theme.value === 'dark');

    function applyTheme(newTheme) {
        theme.value = newTheme;
        if (typeof document !== 'undefined') {
            document.documentElement.setAttribute('data-theme', newTheme);
            if (newTheme === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        }
        try {
            if (typeof localStorage !== 'undefined') {
                localStorage.setItem('theme', newTheme);
            }
        } catch {
            // Ignore storage access errors
        }
    }

    function toggleTheme() {
        applyTheme(theme.value === 'dark' ? 'light' : 'dark');
    }

    function setTheme(newTheme) {
        if (newTheme === 'light' || newTheme === 'dark') {
            applyTheme(newTheme);
        }
    }

    return {
        theme,
        isDark,
        toggleTheme,
        setTheme,
    };
}

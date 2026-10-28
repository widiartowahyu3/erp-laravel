import { vi } from 'vitest';
import { ref, h } from 'vue';

// Mock Inertia page props and URL
const mockPageProps = ref({
    auth: {
        user: { id: 1, name: 'Admin', email: 'admin@example.com' },
        permissions: [],
        roles: ['super_admin'],
    },
    flash: {},
    errors: {},
});
const mockUrl = ref('/');

export function setMockPageProps(props) {
    mockPageProps.value = { ...mockPageProps.value, ...props };
}

export function resetMockPageProps() {
    mockPageProps.value = {
        auth: {
            user: { id: 1, name: 'Admin', email: 'admin@example.com' },
            permissions: [],
            roles: ['super_admin'],
        },
        flash: {},
        errors: {},
    };
    mockUrl.value = '/';
}

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const actual = await importOriginal();
    return {
        ...actual,
        router: {
            get: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
            delete: vi.fn(),
            reload: vi.fn(),
            visit: vi.fn(),
            cancel: vi.fn(),
            on: vi.fn(() => () => {}),
        },
        usePage: () => ({
            get props() {
                return mockPageProps.value;
            },
            get url() {
                return mockUrl.value;
            },
        }),
        Head: {
            name: 'Head',
            render() {
                return null;
            },
        },
        Link: {
            name: 'Link',
            props: ['href', 'as', 'method'],
            render() {
                return h(this.as === 'button' ? 'button' : 'a', { href: this.href }, this.$slots.default ? this.$slots.default() : []);
            },
        },
    };
});

// Mock window.matchMedia
Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })),
});

// Mock ResizeObserver
global.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

// Mock IntersectionObserver
global.IntersectionObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

// Mock scrollIntoView
if (typeof Element !== 'undefined' && !Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = vi.fn();
}

import { describe, it, expect } from 'vitest';

describe('Test Environment Setup', () => {
    it('initializes jsdom environment with matchMedia and Inertia mocks', () => {
        expect(window.matchMedia).toBeDefined();
        expect(typeof window.matchMedia).toBe('function');
        const m = window.matchMedia('(prefers-color-scheme: dark)');
        expect(m.matches).toBe(false);
    });
});

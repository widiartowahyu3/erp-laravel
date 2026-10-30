import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import AppLayout from '../AppLayout.vue';
import { setMockPageProps, resetMockPageProps } from '@/test-setup';

describe('AppLayout Topbar User Hierarchy', () => {
    beforeEach(() => {
        resetMockPageProps();
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Budi Santoso', role: 'Super Admin', email: 'budi@example.com' },
                roles: ['Super Admin'],
                permissions: ['*'],
            },
        });
    });

    it('renders user name with larger bold styling and role with smaller muted styling', () => {
        const wrapper = mount(AppLayout, {
            slots: {
                default: '<p>Content</p>',
            },
        });

        const nameEl = wrapper.find('[data-testid="topbar-user-name"]');
        const roleEl = wrapper.find('[data-testid="topbar-user-role"]');

        expect(nameEl.exists()).toBe(true);
        expect(roleEl.exists()).toBe(true);

        expect(nameEl.text()).toBe('Budi Santoso');
        expect(roleEl.text()).toBe('Super Admin');

        // Name is larger and bold
        expect(nameEl.classes()).toContain('text-sm');
        expect(nameEl.classes()).toContain('font-bold');

        // Role is smaller and muted
        expect(roleEl.classes()).toContain('text-xs');
        expect(roleEl.classes()).toContain('text-slate-500');
    });
});

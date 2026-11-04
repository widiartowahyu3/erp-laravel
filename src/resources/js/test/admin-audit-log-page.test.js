import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import axe from 'axe-core';
import AuditLogIndex from '../Pages/Admin/AuditLogs/Index.vue';
import { navigationItems } from '../config/navigation';

const { mockPageProps, mockRouterGet, mockRouterPost, mockRouterDelete } = vi.hoisted(() => ({
    mockPageProps: { auth: { user: { id: 1, name: 'Admin' }, permissions: ['audit_logs.view'] }, flash: {}, errors: {} },
    mockRouterGet: vi.fn(),
    mockRouterPost: vi.fn(),
    mockRouterDelete: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockPageProps }),
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
    router: { get: mockRouterGet, post: mockRouterPost, delete: mockRouterDelete },
}));

const stubs = { AppLayout: { template: '<main><slot /></main>' }, teleport: true };
const meta = { page: 1, last_page: 2, per_page: 25, total: 30, from: 1, to: 25 };

const payload = {
    items: {
        data: [
            {
                id: 11,
                event: 'role.permissions_changed',
                auditable_type: 'App\\Models\\Role',
                auditable_id: 5,
                old_values: null,
                new_values: { added: ['<img src=x onerror=alert(1)>'], removed: [] },
                ip_address: '10.0.0.1',
                created_at: '2026-11-04 10:00:00',
                user: { id: 1, name: 'Akar' },
            },
            {
                id: 12,
                event: 'product.updated',
                auditable_type: 'App\\Models\\Product',
                auditable_id: 9,
                old_values: { name: 'Lama' },
                new_values: { name: 'Baru' },
                ip_address: null,
                created_at: '2026-11-03 09:00:00',
                user: null,
            },
        ],
        meta,
        query: { filter: {} },
    },
    users: [{ id: 1, name: 'Akar' }],
};

async function expectNoAxeViolations(wrapper) {
    const results = await axe.run(wrapper.element, {
        rules: { 'color-contrast': { enabled: false }, region: { enabled: false } },
    });
    const severe = results.violations.filter((v) => ['critical', 'serious'].includes(v.impact) || v.id === 'heading-order');
    expect(severe).toEqual([]);
}

describe('Admin/AuditLogs/Index', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders entries, translating known events and leaving unknown ones as stored', () => {
        const wrapper = mount(AuditLogIndex, { props: payload, global: { stubs } });

        expect(wrapper.text()).toContain('Izin peran diubah');
        expect(wrapper.text()).toContain('role.permissions_changed');
        expect(wrapper.text()).toContain('Peran #5');
        expect(wrapper.text()).toContain('product.updated');
        expect(wrapper.text()).toContain('App\\Models\\Product #9');
        expect(wrapper.text()).toContain('Akar');
        expect(wrapper.text()).toContain('Sistem atau tamu');
    });

    it('opens an entry and shows its values as escaped text', async () => {
        const wrapper = mount(AuditLogIndex, { props: payload, global: { stubs } });

        expect(wrapper.find('[data-testid="new-values"]').exists()).toBe(false);

        await wrapper.find('button[aria-label="Lihat detail catatan 11"]').trigger('click');
        await nextTick();

        const shown = wrapper.find('[data-testid="new-values"]');
        expect(shown.text()).toContain('<img src=x onerror=alert(1)>');
        expect(shown.element.querySelector('img')).toBeNull();
        expect(wrapper.find('[data-testid="old-values"]').text()).toBe('-');
    });

    it('sends date and user filters to the server', async () => {
        const wrapper = mount(AuditLogIndex, { props: payload, global: { stubs } });

        await wrapper.find('input[aria-label="Dari tanggal"]').setValue('2026-11-01');
        expect(mockRouterGet.mock.calls.at(-1)[0]).toBe('/admin/audit-logs');
        expect(mockRouterGet.mock.calls.at(-1)[1]['filter[date_from]']).toBe('2026-11-01');

        await wrapper.find('input[aria-label="Sampai tanggal"]').setValue('2026-11-04');
        expect(mockRouterGet.mock.calls.at(-1)[1]['filter[date_to]']).toBe('2026-11-04');

        await wrapper.find('select[aria-label="Filter berdasarkan pengguna"]').setValue('1');
        expect(mockRouterGet.mock.calls.at(-1)[1]['filter[user_id]']).toBe('1');
    });

    it('waits for typing to stop before filtering by event and type', async () => {
        vi.useFakeTimers();
        const wrapper = mount(AuditLogIndex, { props: payload, global: { stubs } });

        await wrapper.find('input[aria-label="Filter berdasarkan awalan event"]').setValue('rol');
        await wrapper.find('input[aria-label="Filter berdasarkan awalan event"]').setValue('role.');
        expect(mockRouterGet).not.toHaveBeenCalled();

        vi.advanceTimersByTime(300);
        expect(mockRouterGet).toHaveBeenCalledTimes(1);
        expect(mockRouterGet.mock.calls[0][1]['filter[event]']).toBe('role.');

        await wrapper.find('input[aria-label="Filter berdasarkan tipe objek"]').setValue('Role');
        vi.advanceTimersByTime(300);
        expect(mockRouterGet.mock.calls.at(-1)[1]['filter[auditable_type]']).toBe('Role');
    });

    it('resets every filter', async () => {
        const wrapper = mount(AuditLogIndex, { props: payload, global: { stubs } });

        expect(wrapper.findAll('button').find((b) => b.text().includes('Reset'))).toBeUndefined();

        await wrapper.find('select[aria-label="Filter berdasarkan pengguna"]').setValue('1');
        await wrapper.findAll('button').find((b) => b.text().includes('Reset')).trigger('click');

        expect(mockRouterGet.mock.calls.at(-1)[1]['filter[user_id]']).toBeUndefined();
    });

    it('changes page through the server', async () => {
        const wrapper = mount(AuditLogIndex, { props: payload, global: { stubs } });

        await wrapper.findAll('button').find((b) => b.attributes('aria-label')?.includes('berikutnya') || b.text().trim() === '2').trigger('click');

        expect(mockRouterGet.mock.calls.at(-1)[1].page).toBe(2);
    });

    it('offers no way to change, delete or export entries', async () => {
        const wrapper = mount(AuditLogIndex, { props: payload, global: { stubs } });

        await wrapper.find('button[aria-label="Lihat detail catatan 11"]').trigger('click');
        await nextTick();

        expect(wrapper.text()).not.toMatch(/Ekspor|Hapus Log|Ubah/);
        expect(mockRouterPost).not.toHaveBeenCalled();
        expect(mockRouterDelete).not.toHaveBeenCalled();
    });

    it('has no axe violations, including with an entry open and when empty', async () => {
        const wrapper = mount(AuditLogIndex, { props: payload, global: { stubs }, attachTo: document.body });
        await expectNoAxeViolations(wrapper);

        await wrapper.find('button[aria-label="Lihat detail catatan 11"]').trigger('click');
        await nextTick();
        await expectNoAxeViolations(wrapper);

        await expectNoAxeViolations(mount(AuditLogIndex, {
            props: { ...payload, items: { ...payload.items, data: [] } },
            global: { stubs },
            attachTo: document.body,
        }));
    });
});

describe('administration navigation', () => {
    it.each([
        ['Pengguna', '/admin/users', 'users.view'],
        ['Peran & Hak Akses', '/admin/roles', 'roles.view'],
        ['Log Audit', '/admin/audit-logs', 'audit_logs.view'],
    ])('%s is available and guarded', (name, href, permission) => {
        const items = navigationItems.filter((item) => item.name === name);

        expect(items).toHaveLength(1);
        expect(items[0]).toMatchObject({ href, permission, available: true });
    });

    it('system settings stay unavailable', () => {
        expect(navigationItems.find((item) => item.name === 'Pengaturan Sistem').available).toBe(false);
    });
});

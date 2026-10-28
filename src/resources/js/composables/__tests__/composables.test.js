import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { usePermission } from '../usePermission';
import { useConfirm } from '../useConfirm';
import { useToast } from '../useToast';
import { useTheme } from '../useTheme';
import { useFormatters } from '../useFormatters';
import { useListQuery } from '../useListQuery';
import Can from '@/Components/ui/Can.vue';
import { router } from '@inertiajs/vue3';
import { setMockPageProps, resetMockPageProps } from '@/test-setup';

describe('Frontend Composables', () => {
    beforeEach(() => {
        resetMockPageProps();
        vi.clearAllMocks();
    });

    describe('usePermission & <Can>', () => {
        it('evaluates permissions correctly with can, canAny, and canAll', () => {
            setMockPageProps({
                auth: {
                    user: { id: 1, name: 'Tester' },
                    permissions: ['users.view', 'users.create'],
                },
            });

            const { can, canAny, canAll } = usePermission();

            expect(can('users.view')).toBe(true);
            expect(can('users.delete')).toBe(false);

            expect(canAny(['users.delete', 'users.create'])).toBe(true);
            expect(canAny(['users.delete', 'users.export'])).toBe(false);

            expect(canAll(['users.view', 'users.create'])).toBe(true);
            expect(canAll(['users.view', 'users.delete'])).toBe(false);
        });

        it('<Can> renders default slot when permitted, fallback when denied', () => {
            setMockPageProps({
                auth: {
                    user: { id: 1, name: 'Tester' },
                    permissions: ['products.create'],
                },
            });

            const wrapperAllowed = mount(Can, {
                props: { permission: 'products.create' },
                slots: {
                    default: '<button id="create-btn">Buat Produk</button>',
                    fallback: '<span>Tidak ada akses</span>',
                },
            });
            expect(wrapperAllowed.find('#create-btn').exists()).toBe(true);

            const wrapperDenied = mount(Can, {
                props: { permission: 'products.delete' },
                slots: {
                    default: '<button id="delete-btn">Hapus Produk</button>',
                    fallback: '<span id="fallback">Ditolak</span>',
                },
            });
            expect(wrapperDenied.find('#delete-btn').exists()).toBe(false);
            expect(wrapperDenied.find('#fallback').exists()).toBe(true);
        });
    });

    describe('useConfirm', () => {
        it('resolves promise with boolean on normal confirm', async () => {
            const { confirm, onConfirm, onCancel, state } = useConfirm();

            const promise = confirm({ title: 'Konfirmasi', message: 'Lanjutkan?' });
            expect(state.value.show).toBe(true);

            onConfirm();
            const result = await promise;
            expect(result).toBe(true);
            expect(state.value.show).toBe(false);
        });

        it('resolves promise with reason object when requiresReason is true', async () => {
            const { confirm, onConfirm, state } = useConfirm();

            const promise = confirm({ title: 'Void PO', requiresReason: true });
            expect(state.value.requiresReason).toBe(true);

            onConfirm('Barang rusak');
            const result = await promise;
            expect(result).toEqual({ confirmed: true, reason: 'Barang rusak' });
        });
    });

    describe('useToast', () => {
        it('adds and removes toasts with helper methods', () => {
            const { toasts, success, error, removeToast, clearAll } = useToast();
            clearAll();

            const id1 = success('Berhasil disimpan');
            expect(toasts.value).toHaveLength(1);
            expect(toasts.value[0].variant).toBe('success');
            expect(toasts.value[0].message).toBe('Berhasil disimpan');

            error('Terjadi error');
            expect(toasts.value).toHaveLength(2);

            removeToast(id1);
            expect(toasts.value).toHaveLength(1);
            expect(toasts.value[0].variant).toBe('error');
        });
    });

    describe('useTheme', () => {
        it('toggles theme and updates localStorage', () => {
            const { theme, toggleTheme, setTheme, isDark } = useTheme();

            setTheme('light');
            expect(theme.value).toBe('light');
            expect(isDark.value).toBe(false);
            expect(localStorage.getItem('theme')).toBe('light');

            toggleTheme();
            expect(theme.value).toBe('dark');
            expect(isDark.value).toBe(true);
            expect(localStorage.getItem('theme')).toBe('dark');
        });
    });

    describe('useFormatters', () => {
        const { formatRupiah, formatKuantitas, formatTanggal, formatPersen } = useFormatters();

        it('formatRupiah formats correctly with IDR and cents', () => {
            expect(formatRupiah('1234567.50')).toBe('Rp 1.234.567,50');
            expect(formatRupiah('5000000')).toBe('Rp 5.000.000');
            expect(formatRupiah('invalid')).toBe('-');
        });

        it('formatKuantitas strips trailing zeros dynamically', () => {
            expect(formatKuantitas('10.5000', 'kg')).toBe('10,5 kg');
            expect(formatKuantitas('100.0000')).toBe('100');
            expect(formatKuantitas(null)).toBe('-');
        });

        it('formatTanggal formats date strings', () => {
            expect(formatTanggal('2026-10-28')).toContain('28');
            expect(formatTanggal('2026-10-28')).toContain('2026');
            expect(formatTanggal(null)).toBe('-');
        });

        it('formatPersen formats percentages', () => {
            expect(formatPersen('11')).toBe('11%');
            expect(formatPersen('11.50')).toBe('11,5%');
            expect(formatPersen('invalid')).toBe('-');
        });
    });

    describe('useListQuery', () => {
        it('updates search with debounce and navigates via router.get', async () => {
            vi.useFakeTimers();
            const { setSearch, query } = useListQuery('/users', {
                defaults: { search: '', sort: 'name' },
            });

            setSearch('budi');
            expect(query.value.search).toBe('budi');
            expect(router.get).not.toHaveBeenCalled();

            vi.advanceTimersByTime(300);
            expect(router.get).toHaveBeenCalledTimes(1);
            expect(router.get).toHaveBeenCalledWith(
                '/users',
                expect.objectContaining({ search: 'budi', sort: 'name' }),
                expect.objectContaining({ preserveState: true, preserveScroll: true, replace: true })
            );

            vi.useRealTimers();
        });

        it('prevents duplicate navigation when query parameters have not changed', () => {
            const { setSort } = useListQuery('/items');
            vi.clearAllMocks();

            setSort('code', 'asc');
            expect(router.get).toHaveBeenCalledTimes(1);

            // Trigger again with identical parameters
            setSort('code', 'asc');
            expect(router.get).toHaveBeenCalledTimes(1); // not called again
        });

        it('resets page to 1 on sort, perPage, and filter change', () => {
            const { setPage, setSort, setFilter, setPerPage, currentPage } = useListQuery('/items');

            setPage(4);
            expect(currentPage.value).toBe(4);

            setSort('name');
            expect(currentPage.value).toBe(1);

            setPage(3);
            setPerPage(50);
            expect(currentPage.value).toBe(1);

            setPage(2);
            setFilter('status', 'active');
            expect(currentPage.value).toBe(1);
        });
    });
});

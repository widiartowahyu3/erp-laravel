import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, h } from 'vue';
import Modal from '../Modal.vue';
import ConfirmDialog from '../ConfirmDialog.vue';
import Toast from '../Toast.vue';
import FlashRegion from '../FlashRegion.vue';
import Breadcrumbs from '../Breadcrumbs.vue';
import PageHeader from '../PageHeader.vue';
import Dropdown from '../Dropdown.vue';
import DataTable from '../DataTable.vue';
import Pagination from '../Pagination.vue';
import FilterBar from '../FilterBar.vue';
import Rupiah from '../Rupiah.vue';
import Quantity from '../Quantity.vue';
import DateDisplay from '../DateDisplay.vue';
import PercentDisplay from '../PercentDisplay.vue';

describe('Composite UI Components', () => {
    describe('Modal', () => {
        it('renders dialog into body and locks body scroll when show is true', async () => {
            const wrapper = mount(Modal, {
                props: { show: true, title: 'Judul Modal' },
                slots: { default: '<p>Isi modal</p>' },
            });

            const dialog = document.body.querySelector('[role="dialog"]');
            expect(dialog).not.toBeNull();
            expect(dialog.textContent).toContain('Judul Modal');
            expect(document.body.classList.contains('overflow-hidden')).toBe(true);

            await wrapper.setProps({ show: false });
            expect(document.body.classList.contains('overflow-hidden')).toBe(false);
        });

        it('closes on Escape key press when closeable is true', async () => {
            const wrapper = mount(Modal, {
                props: { show: true, closeable: true, title: 'Modal Uji' },
            });

            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
            expect(wrapper.emitted('close')).toBeTruthy();
        });
    });

    describe('ConfirmDialog', () => {
        it('emits confirm event when confirm button is clicked', async () => {
            const wrapper = mount(ConfirmDialog, {
                props: {
                    show: true,
                    title: 'Hapus Data',
                    message: 'Yakin ingin menghapus?',
                    confirmText: 'Ya, Hapus',
                },
            });

            const buttons = document.body.querySelectorAll('button');
            const confirmBtn = Array.from(buttons).find((b) => b.textContent.includes('Ya, Hapus'));
            expect(confirmBtn).toBeDefined();

            confirmBtn.click();
            expect(wrapper.emitted('confirm')).toBeTruthy();
        });

        it('requires reason text when requiresReason is true', async () => {
            const wrapper = mount(ConfirmDialog, {
                props: {
                    show: true,
                    requiresReason: true,
                    confirmText: 'Void Transaksi',
                },
            });

            const buttons = document.body.querySelectorAll('button');
            const confirmBtn = Array.from(buttons).find((b) => b.textContent.includes('Void Transaksi'));
            expect(confirmBtn.hasAttribute('disabled')).toBe(true);

            const textarea = document.body.querySelector('textarea');
            textarea.value = 'Salah input harga';
            textarea.dispatchEvent(new Event('input'));
            await nextTick();

            expect(confirmBtn.hasAttribute('disabled')).toBe(false);
            confirmBtn.click();
            expect(wrapper.emitted('confirm')[0]).toEqual(['Salah input harga']);
        });
    });

    describe('Toast', () => {
        it('renders toast message with correct alert/status role', () => {
            const wrapper = mount(Toast, {
                props: { id: 1, variant: 'error', title: 'Gagal', message: 'Ada kesalahan server' },
            });
            expect(wrapper.attributes('role')).toBe('alert');
            expect(wrapper.text()).toContain('Gagal');
            expect(wrapper.text()).toContain('Ada kesalahan server');
        });
    });

    describe('Breadcrumbs & PageHeader', () => {
        it('renders breadcrumb items with Home and aria-current on active item', () => {
            const items = [
                { label: 'Master Data', href: '/master' },
                { label: 'Produk' },
            ];
            const wrapper = mount(Breadcrumbs, { props: { items } });
            expect(wrapper.find('nav').attributes('aria-label')).toBe('Breadcrumb');
            expect(wrapper.text()).toContain('Beranda');
            expect(wrapper.text()).toContain('Master Data');
            const current = wrapper.find('[aria-current="page"]');
            expect(current.text()).toBe('Produk');
        });

        it('renders PageHeader with title and actions slot', () => {
            const wrapper = mount(PageHeader, {
                props: { title: 'Daftar Pelanggan', description: 'Kelola data pelanggan' },
                slots: { actions: '<button id="btn-tambah">Tambah</button>' },
            });
            expect(wrapper.find('h1').text()).toBe('Daftar Pelanggan');
            expect(wrapper.text()).toContain('Kelola data pelanggan');
            expect(wrapper.find('#btn-tambah').exists()).toBe(true);
        });
    });

    describe('Dropdown', () => {
        it('toggles menu on trigger click and closes on escape', async () => {
            const wrapper = mount(Dropdown, {
                slots: {
                    trigger: ({ toggle, open }) =>
                        h('button', { id: 'trigger-btn', onClick: toggle, 'aria-expanded': open }, 'Opsi'),
                    default: () => h('button', { id: 'item-1' }, 'Edit'),
                },
            });

            expect(wrapper.find('[role="menu"]').exists()).toBe(false);
            await wrapper.find('#trigger-btn').trigger('click');
            expect(wrapper.find('[role="menu"]').exists()).toBe(true);

            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
            await nextTick();
            expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        });
    });

    describe('DataTable', () => {
        const columns = [
            { key: 'code', label: 'Kode', sortable: true },
            { key: 'name', label: 'Nama' },
            { key: 'total', label: 'Total', type: 'rupiah', align: 'right' },
            { key: 'status', label: 'Status', type: 'status' },
        ];
        const data = [
            { id: 1, code: 'CUST-001', name: 'PT Maju', total: '1500000', status: 'active' },
            { id: 2, code: 'CUST-002', name: 'CV Makmur', total: '250000.50', status: 'draft' },
        ];

        it('renders columns and data rows with auto-formatting', () => {
            const wrapper = mount(DataTable, { props: { columns, data } });
            expect(wrapper.text()).toContain('CUST-001');
            expect(wrapper.text()).toContain('PT Maju');
            expect(wrapper.text()).toContain('Rp 1.500.000');
            expect(wrapper.text()).toContain('Rp 250.000,50');
            expect(wrapper.text()).toContain('Aktif');
            expect(wrapper.text()).toContain('Draft');
        });

        it('emits sort event when sortable column header is clicked', async () => {
            const wrapper = mount(DataTable, {
                props: { columns, data, sortKey: 'code', sortDirection: 'asc' },
            });
            const th = wrapper.findAll('th')[0];
            await th.trigger('click');
            expect(wrapper.emitted('sort')).toBeTruthy();
            expect(wrapper.emitted('sort')[0]).toEqual([{ key: 'code', direction: 'desc' }]);
        });

        it('displays EmptyState when data array is empty', () => {
            const wrapper = mount(DataTable, { props: { columns, data: [] } });
            expect(wrapper.text()).toContain('Tidak ada data');
        });

        it('displays LoadingState when loading prop is true', () => {
            const wrapper = mount(DataTable, { props: { columns, data: [], loading: true } });
            expect(wrapper.text()).toContain('Memuat data...');
        });
    });

    describe('Pagination', () => {
        const meta = {
            current_page: 2,
            last_page: 5,
            per_page: 10,
            total: 48,
            from: 11,
            to: 20,
        };

        it('displays Indonesian summary and emits change-page', async () => {
            const wrapper = mount(Pagination, { props: { meta } });
            expect(wrapper.text()).toContain('Menampilkan 11 sampai 20 dari 48 data');

            const page3Btn = wrapper.findAll('button').find((b) => b.text() === '3');
            expect(page3Btn).toBeDefined();
            await page3Btn.trigger('click');

            expect(wrapper.emitted('change-page')).toBeTruthy();
            expect(wrapper.emitted('change-page')[0]).toEqual([3]);
        });
    });

    describe('FilterBar', () => {
        it('emits debounced search input', async () => {
            vi.useFakeTimers();
            const wrapper = mount(FilterBar, { props: { debounce: 300 } });
            const input = wrapper.find('input');

            await input.setValue('kucing');
            expect(wrapper.emitted('update:search')).toBeTruthy();
            expect(wrapper.emitted('search')).toBeUndefined();

            vi.advanceTimersByTime(300);
            expect(wrapper.emitted('search')).toBeTruthy();
            expect(wrapper.emitted('search')[0]).toEqual(['kucing']);
            vi.useRealTimers();
        });
    });

    describe('Value Display Formatters', () => {
        describe('Rupiah', () => {
            it('formats currency strings in id-ID locale without floating point truncation', () => {
                const wrapper1 = mount(Rupiah, { props: { value: '1234567.50' } });
                expect(wrapper1.text()).toBe('Rp 1.234.567,50');

                const wrapper2 = mount(Rupiah, { props: { value: '5000000' } });
                expect(wrapper2.text()).toBe('Rp 5.000.000');

                const wrapper3 = mount(Rupiah, { props: { value: '999999999999999999.50' } });
                expect(wrapper3.text()).toBe('Rp 999.999.999.999.999.999,50');

                const wrapperInvalid = mount(Rupiah, { props: { value: 'abc' } });
                expect(wrapperInvalid.text()).toBe('-');
            });
        });

        describe('Quantity', () => {
            it('formats quantity strings with dynamic trailing zeros elimination', () => {
                const wrapper1 = mount(Quantity, { props: { value: '10.5000', unit: 'kg' } });
                expect(wrapper1.text()).toBe('10,5 kg');

                const wrapper2 = mount(Quantity, { props: { value: '1250000', unit: 'pcs' } });
                expect(wrapper2.text()).toBe('1.250.000 pcs');

                const wrapperInvalid = mount(Quantity, { props: { value: null } });
                expect(wrapperInvalid.text()).toBe('-');
            });
        });

        describe('DateDisplay', () => {
            it('formats date values into Indonesian locale strings', () => {
                const wrapper = mount(DateDisplay, { props: { value: '2026-10-28', format: 'medium' } });
                expect(wrapper.text()).toContain('28');
                expect(wrapper.text()).toContain('2026');

                const wrapperInvalid = mount(DateDisplay, { props: { value: 'invalid-date' } });
                expect(wrapperInvalid.text()).toBe('-');
            });
        });

        describe('PercentDisplay', () => {
            it('formats percentage values with percent sign', () => {
                const wrapper1 = mount(PercentDisplay, { props: { value: '11' } });
                expect(wrapper1.text()).toBe('11%');

                const wrapper2 = mount(PercentDisplay, { props: { value: '11.50' } });
                expect(wrapper2.text()).toBe('11,5%');

                const wrapperInvalid = mount(PercentDisplay, { props: { value: 'xyz' } });
                expect(wrapperInvalid.text()).toBe('-');
            });
        });
    });
});

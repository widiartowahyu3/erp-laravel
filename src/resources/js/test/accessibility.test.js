import { describe, it, expect, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { h } from 'vue';
import axe from 'axe-core';
import Button from '../Components/ui/Button.vue';
import IconButton from '../Components/ui/IconButton.vue';
import TextInput from '../Components/ui/TextInput.vue';
import PasswordInput from '../Components/ui/PasswordInput.vue';
import Textarea from '../Components/ui/Textarea.vue';
import Select from '../Components/ui/Select.vue';
import Checkbox from '../Components/ui/Checkbox.vue';
import Switch from '../Components/ui/Switch.vue';
import FormField from '../Components/ui/FormField.vue';
import Alert from '../Components/ui/Alert.vue';
import Badge from '../Components/ui/Badge.vue';
import StatusBadge from '../Components/ui/StatusBadge.vue';
import DataTable from '../Components/ui/DataTable.vue';
import Pagination from '../Components/ui/Pagination.vue';
import FilterBar from '../Components/ui/FilterBar.vue';
import EmptyState from '../Components/ui/EmptyState.vue';
import ErrorState from '../Components/ui/ErrorState.vue';
import LoadingState from '../Components/ui/LoadingState.vue';
import Styleguide from '../Pages/Dev/Styleguide.vue';

afterEach(() => {
    document.body.innerHTML = '';
});

async function expectNoViolations(element) {
    const results = await axe.run(element, {
        rules: {
            // Contrast is mathematically tested in theme-contrast.test.js because jsdom lacks layout/CSS rendering
            'color-contrast': { enabled: false },
            // In headless JSDOM, region / landmark rules can report on partial component snippets
            region: { enabled: false },
        },
    });

    const severeViolations = results.violations.filter((v) =>
        ['critical', 'serious'].includes(v.impact)
    );

    if (severeViolations.length > 0) {
        console.error('A11y Violations:', JSON.stringify(severeViolations, null, 2));
    }

    expect(severeViolations).toHaveLength(0);
}

describe('Automated Accessibility (axe-core)', () => {
    it('Button passes a11y audit', async () => {
        const wrapper = mount(Button, {
            attachTo: document.body,
            slots: { default: 'Kirim Dokumen' },
        });
        await expectNoViolations(wrapper.element);
    });

    it('IconButton with ariaLabel passes a11y audit', async () => {
        const wrapper = mount(IconButton, {
            attachTo: document.body,
            props: { ariaLabel: 'Buka pengaturan akun' },
            slots: { default: h('span', '⚙') },
        });
        await expectNoViolations(wrapper.element);
    });

    it('FormField with TextInput passes a11y audit', async () => {
        const wrapper = mount(FormField, {
            attachTo: document.body,
            props: { label: 'Alamat Email', hint: 'Gunakan email kantor', required: true },
            slots: {
                default: (slotProps) =>
                    h(TextInput, {
                        id: slotProps.id,
                        invalid: slotProps.invalid,
                        describedBy: slotProps.describedBy,
                        type: 'email',
                        required: true,
                    }),
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('FormField with PasswordInput passes a11y audit', async () => {
        const wrapper = mount(FormField, {
            attachTo: document.body,
            props: { label: 'Kata Sandi Baru', required: true },
            slots: {
                default: (slotProps) =>
                    h(PasswordInput, {
                        id: slotProps.id,
                        invalid: slotProps.invalid,
                        describedBy: slotProps.describedBy,
                        required: true,
                    }),
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('FormField with Select passes a11y audit', async () => {
        const wrapper = mount(FormField, {
            attachTo: document.body,
            props: { label: 'Pilih Gudang', required: true },
            slots: {
                default: (slotProps) =>
                    h(Select, {
                        id: slotProps.id,
                        invalid: slotProps.invalid,
                        describedBy: slotProps.describedBy,
                        options: [
                            { value: 'g1', label: 'Gudang Pusat' },
                            { value: 'g2', label: 'Gudang Cabang' },
                        ],
                    }),
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('FormField with Textarea passes a11y audit', async () => {
        const wrapper = mount(FormField, {
            attachTo: document.body,
            props: { label: 'Catatan Pengiriman' },
            slots: {
                default: (slotProps) =>
                    h(Textarea, {
                        id: slotProps.id,
                        invalid: slotProps.invalid,
                        describedBy: slotProps.describedBy,
                    }),
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('Checkbox with label passes a11y audit', async () => {
        const wrapper = mount(Checkbox, {
            attachTo: document.body,
            props: {
                id: 'test-chk',
                label: 'Setujui Syarat & Ketentuan',
                description: 'Saya menyatakan telah membaca aturan operasional',
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('Switch with label passes a11y audit', async () => {
        const wrapper = mount(Switch, {
            attachTo: document.body,
            props: {
                id: 'test-switch',
                label: 'Otomatisasi Posting Jurnal',
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('Alert variants pass a11y audit', async () => {
        for (const variant of ['info', 'success', 'warning', 'danger']) {
            document.body.innerHTML = '';
            const wrapper = mount(Alert, {
                attachTo: document.body,
                props: { variant, title: `Judul ${variant}` },
                slots: { default: 'Pesan informasi sistem' },
            });
            await expectNoViolations(wrapper.element);
        }
    });

    it('StatusBadge for document states passes a11y audit', async () => {
        const statuses = ['draft', 'approved', 'posted', 'void', 'paid', 'active'];
        for (const status of statuses) {
            document.body.innerHTML = '';
            const wrapper = mount(StatusBadge, {
                attachTo: document.body,
                props: { status },
            });
            await expectNoViolations(wrapper.element);
        }
    });

    it('DataTable with rows passes a11y audit', async () => {
        const wrapper = mount(DataTable, {
            attachTo: document.body,
            props: {
                columns: [
                    { key: 'code', label: 'Kode Produk', sortable: true },
                    { key: 'name', label: 'Nama Produk' },
                ],
                items: [
                    { id: 1, code: 'SKU-001', name: 'Barang A' },
                    { id: 2, code: 'SKU-002', name: 'Barang B' },
                ],
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('Pagination component passes a11y audit', async () => {
        const wrapper = mount(Pagination, {
            attachTo: document.body,
            props: {
                meta: {
                    current_page: 2,
                    per_page: 10,
                    total: 50,
                    last_page: 5,
                    from: 11,
                    to: 20,
                },
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('FilterBar component passes a11y audit', async () => {
        const wrapper = mount(FilterBar, {
            attachTo: document.body,
            props: {
                modelValue: 'kata kunci',
                hasActiveFilters: true,
            },
        });
        await expectNoViolations(wrapper.element);
    });

    it('State views pass a11y audit', async () => {
        const empty = mount(EmptyState, { attachTo: document.body, props: { title: 'Kosong', description: 'Data kosong' } });
        await expectNoViolations(empty.element);
        document.body.innerHTML = '';

        const error = mount(ErrorState, { attachTo: document.body, props: { title: 'Galat', message: 'Terjadi kesalahan' } });
        await expectNoViolations(error.element);
        document.body.innerHTML = '';

        const loading = mount(LoadingState, { attachTo: document.body, props: { message: 'Memuat...' } });
        await expectNoViolations(loading.element);
    });

    it('Full Styleguide page passes axe-core audit with 0 critical or serious violations', async () => {
        const wrapper = mount(Styleguide, {
            attachTo: document.body,
        });
        await expectNoViolations(wrapper.element);
    });
});

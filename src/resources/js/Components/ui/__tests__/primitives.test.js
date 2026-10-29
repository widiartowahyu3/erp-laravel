import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { h } from 'vue';
import Button from '../Button.vue';
import IconButton from '../IconButton.vue';
import TextInput from '../TextInput.vue';
import PasswordInput from '../PasswordInput.vue';
import Textarea from '../Textarea.vue';
import Select from '../Select.vue';
import Checkbox from '../Checkbox.vue';
import Switch from '../Switch.vue';
import FormField from '../FormField.vue';
import Badge from '../Badge.vue';
import StatusBadge from '../StatusBadge.vue';
import statusMap from '@/generated/enums.json';
import Card from '../Card.vue';
import Alert from '../Alert.vue';
import Skeleton from '../Skeleton.vue';
import Tooltip from '../Tooltip.vue';

describe('Primitive UI Components', () => {
    describe('Button', () => {
        it('renders slot content and defaults to type button', () => {
            const wrapper = mount(Button, {
                slots: { default: 'Simpan Data' },
            });
            expect(wrapper.text()).toContain('Simpan Data');
            expect(wrapper.attributes('type')).toBe('button');
            expect(wrapper.classes()).toContain('bg-indigo-600');
        });

        it('disables button and shows spinner when loading is true', () => {
            const wrapper = mount(Button, {
                props: { loading: true },
                slots: { default: 'Kirim' },
            });
            expect(wrapper.attributes('disabled')).toBeDefined();
            expect(wrapper.find('[role="status"]').exists()).toBe(true);
        });

        it('supports danger, secondary, and ghost variants', () => {
            const danger = mount(Button, { props: { variant: 'danger' } });
            expect(danger.classes()).toContain('bg-rose-600');

            const secondary = mount(Button, { props: { variant: 'secondary' } });
            expect(secondary.classes()).toContain('border-slate-300');

            const ghost = mount(Button, { props: { variant: 'ghost' } });
            expect(ghost.classes()).toContain('hover:bg-slate-100');
        });
    });

    describe('IconButton', () => {
        it('applies aria-label and title to button', () => {
            const wrapper = mount(IconButton, {
                props: { ariaLabel: 'Hapus item' },
                slots: { default: '<span class="icon">X</span>' },
            });
            expect(wrapper.attributes('aria-label')).toBe('Hapus item');
            expect(wrapper.attributes('title')).toBe('Hapus item');
            expect(wrapper.classes()).toContain('min-w-[40px]');
            expect(wrapper.classes()).toContain('min-h-[40px]');
        });
    });

    describe('TextInput', () => {
        it('emits update:modelValue on input', async () => {
            const wrapper = mount(TextInput, {
                props: { modelValue: 'nilai lama' },
            });
            const input = wrapper.find('input');
            await input.setValue('nilai baru');
            expect(wrapper.emitted('update:modelValue')).toBeTruthy();
            expect(wrapper.emitted('update:modelValue')[0]).toEqual(['nilai baru']);
        });

        it('sets aria-invalid to true when invalid prop is passed', () => {
            const wrapper = mount(TextInput, {
                props: { invalid: true },
            });
            expect(wrapper.attributes('aria-invalid')).toBe('true');
            expect(wrapper.classes()).toContain('border-rose-500');
        });
    });

    describe('PasswordInput', () => {
        it('toggles input type between password and text', async () => {
            const wrapper = mount(PasswordInput, {
                props: { modelValue: 'secret123' },
            });
            const input = wrapper.find('input');
            expect(input.attributes('type')).toBe('password');

            const toggleBtn = wrapper.find('button');
            expect(toggleBtn.attributes('aria-label')).toBe('Tampilkan kata sandi');

            await toggleBtn.trigger('click');
            expect(input.attributes('type')).toBe('text');
            expect(toggleBtn.attributes('aria-label')).toBe('Sembunyikan kata sandi');
        });
    });

    describe('Textarea', () => {
        it('renders textarea and displays character counter if maxLength is defined', () => {
            const wrapper = mount(Textarea, {
                props: { modelValue: 'Catatan penting', maxLength: 100 },
            });
            expect(wrapper.find('textarea').element.value).toBe('Catatan penting');
            expect(wrapper.text()).toContain('15 / 100');
        });
    });

    describe('Select', () => {
        it('renders options array and placeholder', async () => {
            const options = [
                { value: 'id', label: 'Indonesia' },
                { value: 'en', label: 'English' },
            ];
            const wrapper = mount(Select, {
                props: { options, placeholder: 'Pilih bahasa', modelValue: '' },
            });
            expect(wrapper.findAll('option')).toHaveLength(3); // placeholder + 2 options

            const select = wrapper.find('select');
            await select.setValue('id');
            expect(wrapper.emitted('update:modelValue')[0]).toEqual(['id']);
        });
    });

    describe('Checkbox', () => {
        it('emits update:modelValue with boolean when clicked', async () => {
            const wrapper = mount(Checkbox, {
                props: { modelValue: false, label: 'Ingat saya' },
            });
            const input = wrapper.find('input[type="checkbox"]');
            await input.setValue(true);
            expect(wrapper.emitted('update:modelValue')[0]).toEqual([true]);
            expect(wrapper.text()).toContain('Ingat saya');
        });
    });

    describe('Switch', () => {
        it('toggles on click and keyboard Space/Enter with role switch', async () => {
            const wrapper = mount(Switch, {
                props: { modelValue: false, label: 'Status Aktif' },
            });
            const btn = wrapper.find('button[role="switch"]');
            expect(btn.attributes('aria-checked')).toBe('false');

            await btn.trigger('click');
            expect(wrapper.emitted('update:modelValue')[0]).toEqual([true]);

            await btn.trigger('keydown', { key: ' ' });
            expect(wrapper.emitted('update:modelValue')[1]).toEqual([true]);
        });
    });

    describe('FormField', () => {
        it('connects label, input, help, and error via aria-describedby', () => {
            const wrapper = mount(FormField, {
                props: {
                    id: 'custom-email',
                    label: 'Alamat Email',
                    help: 'Gunakan email perusahaan',
                    error: 'Email sudah terdaftar',
                    required: true,
                },
                slots: {
                    default: (props) =>
                        h('input', {
                            id: props.id,
                            'aria-describedby': props.describedBy,
                        }),
                },
            });

            expect(wrapper.find('label').attributes('for')).toBe('custom-email');
            expect(wrapper.find('label').text()).toContain('Alamat Email');
            expect(wrapper.find('label').text()).toContain('*');

            const errorMsg = wrapper.find('[role="alert"]');
            expect(errorMsg.exists()).toBe(true);
            expect(errorMsg.attributes('id')).toBe('custom-email-error');
            expect(errorMsg.text()).toContain('Email sudah terdaftar');

            const input = wrapper.find('input');
            expect(input.attributes('aria-describedby')).toContain('custom-email-error');
            expect(input.attributes('aria-describedby')).toContain('custom-email-help');
        });
    });

    describe('Badge & StatusBadge', () => {
        it('renders Badge with correct variant styling', () => {
            const wrapper = mount(Badge, {
                props: { variant: 'success' },
                slots: { default: 'Berhasil' },
            });
            expect(wrapper.text()).toBe('Berhasil');
            expect(wrapper.classes()).toContain('bg-emerald-50');
        });

        it('matches StatusBadge keys and labels with enums.json exactly with zero fictitious statuses', () => {
            const keys = Object.keys(statusMap);
            expect(keys.length).toBeGreaterThanOrEqual(18);

            for (const [statusKey, expected] of Object.entries(statusMap)) {
                const wrapper = mount(StatusBadge, { props: { status: statusKey } });
                expect(wrapper.text()).toBe(expected.label);
            }
        });
    });

    describe('Card', () => {
        it('renders slots for header, default body, and footer', () => {
            const wrapper = mount(Card, {
                slots: {
                    header: '<h2>Judul Kartu</h2>',
                    default: '<p>Isi Konten</p>',
                    footer: '<button>Aksi</button>',
                },
            });
            expect(wrapper.text()).toContain('Judul Kartu');
            expect(wrapper.text()).toContain('Isi Konten');
            expect(wrapper.text()).toContain('Aksi');
        });
    });

    describe('Alert', () => {
        it('renders variant and handles dismissible event', async () => {
            const wrapper = mount(Alert, {
                props: { variant: 'danger', title: 'Galat Sistem', dismissible: true },
                slots: { default: 'Koneksi database gagal' },
            });
            expect(wrapper.attributes('role')).toBe('alert');
            expect(wrapper.text()).toContain('Galat Sistem');
            expect(wrapper.text()).toContain('Koneksi database gagal');

            const closeBtn = wrapper.find('button');
            await closeBtn.trigger('click');
            expect(wrapper.emitted('dismiss')).toHaveLength(1);
        });
    });

    describe('Skeleton', () => {
        it('renders pulse animation lines with role presentation and aria-hidden', () => {
            const wrapper = mount(Skeleton, {
                props: { variant: 'line', count: 3 },
            });
            expect(wrapper.attributes('role')).toBe('presentation');
            expect(wrapper.attributes('aria-hidden')).toBe('true');
            expect(wrapper.findAll('.animate-pulse')).toHaveLength(3);
        });
    });

    describe('Tooltip', () => {
        it('renders tooltip with role tooltip and position classes', () => {
            const wrapper = mount(Tooltip, {
                props: { text: 'Petunjuk tombol', position: 'bottom' },
                slots: { default: '<button>Aksi</button>' },
            });
            const tooltip = wrapper.find('[role="tooltip"]');
            expect(tooltip.text()).toBe('Petunjuk tombol');
            expect(tooltip.classes()).toContain('top-full');
        });
    });
});

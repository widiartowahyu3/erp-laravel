<script setup>
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import {
    Plus,
    Trash2,
    Edit,
    Search,
    Download,
    Eye,
    Check,
    Bell,
    Settings,
    FileSpreadsheet,
    HelpCircle,
} from 'lucide-vue-next';
import AppLayout from '../../Layouts/AppLayout.vue';
import PageHeader from '../../Components/ui/PageHeader.vue';
import Card from '../../Components/ui/Card.vue';
import Button from '../../Components/ui/Button.vue';
import IconButton from '../../Components/ui/IconButton.vue';
import TextInput from '../../Components/ui/TextInput.vue';
import PasswordInput from '../../Components/ui/PasswordInput.vue';
import Textarea from '../../Components/ui/Textarea.vue';
import Select from '../../Components/ui/Select.vue';
import Checkbox from '../../Components/ui/Checkbox.vue';
import Switch from '../../Components/ui/Switch.vue';
import FormField from '../../Components/ui/FormField.vue';
import Badge from '../../Components/ui/Badge.vue';
import StatusBadge from '../../Components/ui/StatusBadge.vue';
import Alert from '../../Components/ui/Alert.vue';
import Spinner from '../../Components/ui/Spinner.vue';
import Skeleton from '../../Components/ui/Skeleton.vue';
import Tooltip from '../../Components/ui/Tooltip.vue';
import Modal from '../../Components/ui/Modal.vue';
import DataTable from '../../Components/ui/DataTable.vue';
import Pagination from '../../Components/ui/Pagination.vue';
import FilterBar from '../../Components/ui/FilterBar.vue';
import Rupiah from '../../Components/ui/Rupiah.vue';
import Quantity from '../../Components/ui/Quantity.vue';
import DateDisplay from '../../Components/ui/DateDisplay.vue';
import PercentDisplay from '../../Components/ui/PercentDisplay.vue';
import EmptyState from '../../Components/ui/EmptyState.vue';
import ErrorState from '../../Components/ui/ErrorState.vue';
import LoadingState from '../../Components/ui/LoadingState.vue';
import { useToast } from '@/composables/useToast';
import { useConfirm } from '@/composables/useConfirm';

const { addToast } = useToast();
const { confirm } = useConfirm();

// State for Form controls
const textValue = ref('Contoh Nilai Input');
const passwordValue = ref('Rahasia123!@#');
const textareaValue = ref('Catatan pesanan pengiriman ke cabang Surabaya...');
const selectValue = ref('option-2');
const selectOptions = [
    { value: 'option-1', label: 'Opsi Pertama (Gudang Utama)' },
    { value: 'option-2', label: 'Opsi Kedua (Gudang Cabang Bandung)' },
    { value: 'option-3', label: 'Opsi Ketiga (Gudang Transit)' },
];
const checkboxValue = ref(true);
const switchValue = ref(false);

// State for Modal & Confirm
const isExampleModalOpen = ref(false);
const confirmResult = ref('');

async function testSimpleConfirm() {
    const ok = await confirm({
        title: 'Konfirmasi Persetujuan',
        message: 'Apakah Anda yakin ingin menyetujui dokumen pesanan penjualan ini?',
        confirmText: 'Ya, Setujui',
        variant: 'primary',
    });
    confirmResult.value = `Persetujuan: ${ok ? 'Disetujui' : 'Dibatalkan'}`;
}

async function testDangerousConfirm() {
    const result = await confirm({
        title: 'Batalkan (Void) Dokumen Faktur',
        message: 'Tindakan ini tidak dapat diurungkan dan akan membalikkan seluruh entri jurnal.',
        confirmText: 'Ya, Batalkan Dokumen',
        variant: 'danger',
        requiresReason: true,
        reasonLabel: 'Alasan Pembatalan Dokumen',
        reasonPlaceholder: 'Tuliskan alasan pembatalan...',
    });
    if (result && result.confirmed) {
        confirmResult.value = `Void Dokumen: Dikonfirmasi dengan alasan "${result.reason}"`;
    } else {
        confirmResult.value = 'Void Dokumen: Dibatalkan oleh pengguna';
    }
}

// State for DataTable
const searchQuery = ref('');
const sortField = ref('tanggal');
const sortDirection = ref('desc');
const currentPage = ref(1);
const perPage = ref(5);

const sampleTableData = ref([
    {
        id: 1,
        no_dokumen: 'SO/2026/03/0001',
        tanggal: '2026-03-01 10:30:00',
        pelanggan: 'PT Maju Bersama Makmur',
        kuantitas: '150.0000',
        total: '15450000.0000',
        diskon: '5.0000',
        status: 'approved',
    },
    {
        id: 2,
        no_dokumen: 'SO/2026/03/0002',
        tanggal: '2026-03-02 11:15:00',
        pelanggan: 'CV Sumber Rejeki Abadi',
        kuantitas: '50.0000',
        total: '4800000.0000',
        diskon: '0.0000',
        status: 'partially_shipped',
    },
    {
        id: 3,
        no_dokumen: 'SO/2026/03/0003',
        tanggal: '2026-03-03 14:00:00',
        pelanggan: 'Toko Elektronik Sentosa',
        kuantitas: '10.0000',
        total: '12500000.0000',
        diskon: '2.5000',
        status: 'draft',
    },
    {
        id: 4,
        no_dokumen: 'SO/2026/03/0004',
        tanggal: '2026-03-04 16:45:00',
        pelanggan: 'UD Berkah Nusantara',
        kuantitas: '500.0000',
        total: '35000000.0000',
        diskon: '10.0000',
        status: 'paid',
    },
    {
        id: 5,
        no_dokumen: 'SO/2026/03/0005',
        tanggal: '2026-03-05 09:20:00',
        pelanggan: 'PT Global Niaga Sejahtera',
        kuantitas: '75.0000',
        total: '8900000.0000',
        diskon: '0.0000',
        status: 'void',
    },
]);

const tableColumns = [
    { key: 'no_dokumen', label: 'No. Dokumen', sortable: true },
    { key: 'tanggal', label: 'Tanggal', sortable: true },
    { key: 'pelanggan', label: 'Pelanggan', sortable: true },
    { key: 'kuantitas', label: 'Kuantitas', align: 'right' },
    { key: 'diskon', label: 'Diskon', align: 'right' },
    { key: 'total', label: 'Total Nilai', align: 'right', sortable: true },
    { key: 'status', label: 'Status Dokumen', align: 'center' },
];

const filteredTableData = computed(() => {
    let result = [...sampleTableData.value];
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        result = result.filter(
            (row) =>
                row.no_dokumen.toLowerCase().includes(query) ||
                row.pelanggan.toLowerCase().includes(query)
        );
    }
    result.sort((a, b) => {
        const valA = a[sortField.value];
        const valB = b[sortField.value];
        if (valA < valB) return sortDirection.value === 'asc' ? -1 : 1;
        if (valA > valB) return sortDirection.value === 'asc' ? 1 : -1;
        return 0;
    });
    return result;
});

function handleSort(key) {
    if (sortField.value === key) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortField.value = key;
        sortDirection.value = 'asc';
    }
}

const paginationMeta = computed(() => ({
    current_page: currentPage.value,
    per_page: perPage.value,
    total: filteredTableData.value.length,
    last_page: 1,
    from: filteredTableData.value.length > 0 ? 1 : 0,
    to: filteredTableData.value.length,
}));

// All 15 document status enums from Phase 4
const allStatuses = [
    'draft',
    'confirmed',
    'approved',
    'rejected',
    'void',
    'processing',
    'partially_shipped',
    'shipped',
    'delivered',
    'partially_received',
    'received',
    'unpaid',
    'partially_paid',
    'paid',
    'overdue',
];
</script>

<template>
    <AppLayout title="Styleguide & Design System">
        <Head title="Styleguide UI & Design System" />

        <div class="space-y-10 pb-16">
            <!-- Header -->
            <PageHeader
                title="Design System & Styleguide"
                description="Katalog terpadu token desain, komponen primitif, komponen komposit, dan pedoman aksesibilitas ERP Distribusi."
            >
                <template #actions>
                    <Badge variant="info" size="md">
                        Lingkungan Lokal (DEV)
                    </Badge>
                </template>
            </PageHeader>

            <!-- 1. Color Tokens -->
            <section class="space-y-4">
                <div class="border-b border-slate-200 dark:border-slate-800 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">
                        1. Palet Token Warna &amp; Permukaan
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Token semantik berbasis CSS variables dengan rasio kontras WCAG AA (&ge; 4.5:1 untuk teks normal).
                    </p>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                    <div class="p-3 rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-xs space-y-1.5">
                        <div class="h-10 rounded-md bg-indigo-600 dark:bg-indigo-500 flex items-center justify-center text-white text-xs font-bold">
                            Primary
                        </div>
                        <div class="text-xs font-semibold text-slate-900 dark:text-white">Primary 600</div>
                        <div class="text-2xs text-slate-500 dark:text-slate-400">#4f46e5</div>
                    </div>

                    <div class="p-3 rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-xs space-y-1.5">
                        <div class="h-10 rounded-md bg-emerald-600 dark:bg-emerald-500 flex items-center justify-center text-white text-xs font-bold">
                            Success
                        </div>
                        <div class="text-xs font-semibold text-slate-900 dark:text-white">Success 600</div>
                        <div class="text-2xs text-slate-500 dark:text-slate-400">#059669</div>
                    </div>

                    <div class="p-3 rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-xs space-y-1.5">
                        <div class="h-10 rounded-md bg-amber-500 flex items-center justify-center text-white text-xs font-bold">
                            Warning
                        </div>
                        <div class="text-xs font-semibold text-slate-900 dark:text-white">Warning 500</div>
                        <div class="text-2xs text-slate-500 dark:text-slate-400">#d97706</div>
                    </div>

                    <div class="p-3 rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-xs space-y-1.5">
                        <div class="h-10 rounded-md bg-rose-600 dark:bg-rose-500 flex items-center justify-center text-white text-xs font-bold">
                            Danger
                        </div>
                        <div class="text-xs font-semibold text-slate-900 dark:text-white">Danger 600</div>
                        <div class="text-2xs text-slate-500 dark:text-slate-400">#e11d48</div>
                    </div>

                    <div class="p-3 rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-xs space-y-1.5">
                        <div class="h-10 rounded-md bg-sky-600 dark:bg-sky-500 flex items-center justify-center text-white text-xs font-bold">
                            Info
                        </div>
                        <div class="text-xs font-semibold text-slate-900 dark:text-white">Info 600</div>
                        <div class="text-2xs text-slate-500 dark:text-slate-400">#0284c7</div>
                    </div>

                    <div class="p-3 rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-xs space-y-1.5">
                        <div class="h-10 rounded-md bg-slate-900 dark:bg-slate-100 flex items-center justify-center text-white dark:text-slate-900 text-xs font-bold">
                            Surface
                        </div>
                        <div class="text-xs font-semibold text-slate-900 dark:text-white">Text Primary</div>
                        <div class="text-2xs text-slate-500 dark:text-slate-400">Slate 900 / 100</div>
                    </div>
                </div>
            </section>

            <!-- 2. Typography -->
            <section class="space-y-4">
                <div class="border-b border-slate-200 dark:border-slate-800 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">
                        2. Tipografi &amp; Angka Tabular
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Font sans-serif Inter (self-hosted) dengan fitur font-feature-settings tnum untuk perataan desimal.
                    </p>
                </div>

                <Card padding="md">
                    <div class="space-y-4">
                        <div class="space-y-1">
                            <h1 class="text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                                Heading 1 (text-3xl font-extrabold)
                            </h1>
                            <h2 class="text-2xl font-bold text-slate-900 dark:text-white tracking-tight">
                                Heading 2 (text-2xl font-bold)
                            </h2>
                            <h3 class="text-xl font-bold text-slate-900 dark:text-white tracking-tight">
                                Heading 3 (text-xl font-bold)
                            </h3>
                            <h4 class="text-lg font-semibold text-slate-900 dark:text-white">
                                Heading 4 (text-lg font-semibold)
                            </h4>
                        </div>
                        <p class="text-sm text-slate-700 dark:text-slate-300 leading-relaxed">
                            Teks paragraf standar: Sistem ERP Distribusi dan Trading dirancang dengan prinsip strict float safety, audit jejak penuh, dan arsitektur monolith Laravel yang modular.
                        </p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Teks sekunder/muted (text-xs text-slate-500): Digunakan untuk label pelengkap, petunjuk input, dan informasi waktu.
                        </p>
                    </div>
                </Card>
            </section>

            <!-- 3. Buttons -->
            <section class="space-y-4">
                <div class="border-b border-slate-200 dark:border-slate-800 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">
                        3. Tombol (Buttons &amp; IconButtons)
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Varian: primary, secondary, danger, ghost. Ukuran: sm, md, lg. Status: normal, loading, disabled.
                    </p>
                </div>

                <Card padding="md">
                    <div class="space-y-6">
                        <!-- Variants -->
                        <div class="space-y-2">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Varian Tombol</h3>
                            <div class="flex flex-wrap items-center gap-3">
                                <Button variant="primary">
                                    <template #icon><Plus class="w-4 h-4" aria-hidden="true" /></template>
                                    Primary Action
                                </Button>
                                <Button variant="secondary">Secondary Action</Button>
                                <Button variant="danger">
                                    <template #icon><Trash2 class="w-4 h-4" aria-hidden="true" /></template>
                                    Hapus / Danger
                                </Button>
                                <Button variant="ghost">Ghost Action</Button>
                            </div>
                        </div>

                        <!-- Sizes -->
                        <div class="space-y-2">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Ukuran Tombol</h3>
                            <div class="flex flex-wrap items-center gap-3">
                                <Button size="sm" variant="primary">Small (sm)</Button>
                                <Button size="md" variant="primary">Medium (md)</Button>
                                <Button size="lg" variant="primary">Large (lg)</Button>
                            </div>
                        </div>

                        <!-- States -->
                        <div class="space-y-2">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Status Tombol</h3>
                            <div class="flex flex-wrap items-center gap-3">
                                <Button variant="primary" :loading="true">Sedang Memproses...</Button>
                                <Button variant="primary" :disabled="true">Disabled State</Button>
                                <Button variant="secondary" :disabled="true">Secondary Disabled</Button>
                                <Button variant="danger" :loading="true">Menghapus...</Button>
                            </div>
                        </div>

                        <!-- Icon Buttons -->
                        <div class="space-y-2">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Icon Button (Mandatory aria-label)</h3>
                            <div class="flex flex-wrap items-center gap-3">
                                <IconButton aria-label="Cari data" variant="primary">
                                    <Search class="w-4 h-4" aria-hidden="true" />
                                </IconButton>
                                <IconButton aria-label="Ubah data" variant="secondary">
                                    <Edit class="w-4 h-4" aria-hidden="true" />
                                </IconButton>
                                <IconButton aria-label="Hapus baris" variant="danger">
                                    <Trash2 class="w-4 h-4" aria-hidden="true" />
                                </IconButton>
                                <IconButton aria-label="Unduh dokumen" variant="ghost">
                                    <Download class="w-4 h-4" aria-hidden="true" />
                                </IconButton>
                            </div>
                        </div>
                    </div>
                </Card>
            </section>

            <!-- 4. Form Controls -->
            <section class="space-y-4">
                <div class="border-b border-slate-200 dark:border-slate-800 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">
                        4. Form Controls &amp; FormField
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Input, PasswordInput, Textarea, Select, Checkbox, Switch dengan integrasi FormField (label, error, hint).
                    </p>
                </div>

                <Card padding="md">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- TextInput Normal -->
                        <FormField label="Nama Lengkap" hint="Nama sesuai KTP/identitas resmi" required>
                            <template #default="{ id, invalid, describedBy }">
                                <TextInput
                                    :id="id"
                                    v-model="textValue"
                                    placeholder="Masukkan nama..."
                                    :invalid="invalid"
                                    :described-by="describedBy"
                                    required
                                />
                            </template>
                        </FormField>

                        <!-- TextInput with Error -->
                        <FormField label="Alamat Email" error="Format email tidak valid atau sudah terdaftar" required>
                            <template #default="{ id, invalid, describedBy }">
                                <TextInput
                                    :id="id"
                                    model-value="email-salah@"
                                    placeholder="nama@perusahaan.com"
                                    type="email"
                                    :invalid="invalid"
                                    :described-by="describedBy"
                                    required
                                />
                            </template>
                        </FormField>

                        <!-- PasswordInput -->
                        <FormField label="Kata Sandi Akun" hint="Minimal 12 karakter" required>
                            <template #default="{ id, invalid, describedBy }">
                                <PasswordInput
                                    :id="id"
                                    v-model="passwordValue"
                                    :invalid="invalid"
                                    :described-by="describedBy"
                                    required
                                />
                            </template>
                        </FormField>

                        <!-- Select -->
                        <FormField label="Gudang Penyimpanan" hint="Pilih lokasi fisik persediaan barang" required>
                            <template #default="{ id, invalid, describedBy }">
                                <Select
                                    :id="id"
                                    v-model="selectValue"
                                    :options="selectOptions"
                                    :invalid="invalid"
                                    :described-by="describedBy"
                                    required
                                />
                            </template>
                        </FormField>

                        <!-- Textarea -->
                        <div class="md:col-span-2">
                            <FormField label="Catatan Tambahan (Auto-Resize CSS field-sizing)" hint="Tinggi otomatis memanjang mengikuti teks tanpa script inline style">
                                <template #default="{ id, invalid, describedBy }">
                                    <Textarea
                                        :id="id"
                                        v-model="textareaValue"
                                        :invalid="invalid"
                                        :described-by="describedBy"
                                        :rows="3"
                                    />
                                </template>
                            </FormField>
                        </div>

                        <!-- Checkbox & Switch -->
                        <div class="space-y-4">
                            <Checkbox
                                id="demo-checkbox-1"
                                v-model="checkboxValue"
                                label="Aktifkan notifikasi email untuk status dokumen"
                                description="Kirim ringkasan otomatis setiap ada perubahan status SO/PO"
                            />
                            <Checkbox
                                id="demo-checkbox-disabled"
                                :model-value="false"
                                :disabled="true"
                                label="Fitur Ekspor Terkunci (Perlu Izin Superadmin)"
                            />
                        </div>

                        <div class="space-y-4">
                            <Switch
                                id="demo-switch-1"
                                v-model="switchValue"
                                label="Mode Verifikasi Ganda (Two-Person Rule)"
                                description="Dokumen bernilai di atas Rp 100.000.000 memerlukan persetujuan dua otorisator"
                            />
                            <Switch
                                id="demo-switch-disabled"
                                :model-value="true"
                                :disabled="true"
                                label="Jurnal Otomatis (Terkunci oleh Kebijakan Akuntansi)"
                            />
                        </div>
                    </div>
                </Card>
            </section>

            <!-- 5. Feedback, Alerts, Badges & Toasts -->
            <section class="space-y-4">
                <div class="border-b border-slate-200 dark:border-slate-800 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">
                        5. Komponen Feedback, Alert, StatusBadge &amp; Toast
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Alert, Badge, StatusBadge (15 status dokumen bisnis), Spinner, Skeleton, Tooltip, dan Toast interaktif.
                    </p>
                </div>

                <div class="space-y-4">
                    <!-- Alerts -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <Alert variant="info" title="Informasi Sistem">
                            Pencadangan basis data otomatis dijadwalkan setiap pukul 02:00 WIB.
                        </Alert>
                        <Alert variant="success" title="Transaksi Berhasil">
                            Faktur penjualan SI/2026/03/0012 telah berhasil dibukukan ke jurnal umum.
                        </Alert>
                        <Alert variant="warning" title="Peringatan Saldo Minimum">
                            Stok produk SKU-8902 di Gudang Surabaya berada di bawah batas titik pemesanan ulang (ROP).
                        </Alert>
                        <Alert variant="danger" title="Otorisasi Ditolak">
                            Dokumen tidak dapat disetujui karena kredit limit pelanggan telah terlampaui.
                        </Alert>
                    </div>

                    <!-- StatusBadges for all 15 Document Statuses -->
                    <Card padding="md">
                        <div class="space-y-3">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">
                                15 Status Dokumen Bisnis (StatusBadge)
                            </h3>
                            <div class="flex flex-wrap items-center gap-2">
                                <StatusBadge
                                    v-for="status in allStatuses"
                                    :key="status"
                                    :status="status"
                                />
                            </div>
                        </div>
                    </Card>

                    <!-- Spinners, Skeletons & Tooltips -->
                    <Card padding="md">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="space-y-2">
                                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Spinner Loader</h3>
                                <div class="flex items-center gap-4">
                                    <Spinner size="sm" variant="primary" />
                                    <Spinner size="md" variant="primary" />
                                    <Spinner size="lg" variant="primary" />
                                </div>
                            </div>

                            <div class="space-y-2">
                                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Skeleton Placeholder</h3>
                                <div class="space-y-2">
                                    <Skeleton class="h-4 w-3/4" />
                                    <Skeleton class="h-4 w-full" />
                                    <Skeleton class="h-4 w-1/2" />
                                </div>
                            </div>

                            <div class="space-y-2">
                                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Tooltip Aksesibel</h3>
                                <div class="flex items-center gap-3">
                                    <Tooltip text="Bantuan mengenai aturan penomoran dokumen">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-800 text-xs font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-600"
                                        >
                                            <HelpCircle class="w-4 h-4 text-slate-400" aria-hidden="true" />
                                            <span>Arahkan kursor</span>
                                        </button>
                                    </Tooltip>
                                </div>
                            </div>
                        </div>
                    </Card>

                    <!-- Interactive Toast Triggers -->
                    <Card padding="md">
                        <div class="space-y-3">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">
                                Uji Toast Notifikasi (useToast)
                            </h3>
                            <div class="flex flex-wrap items-center gap-3">
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    @click="addToast({ variant: 'success', title: 'Berhasil', message: 'Data pelanggan berhasil disimpan.' })"
                                >
                                    Picu Toast Sukses
                                </Button>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    @click="addToast({ variant: 'error', title: 'Galat', message: 'Koneksi ke basis data terputus sementara.' })"
                                >
                                    Picu Toast Galat
                                </Button>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    @click="addToast({ variant: 'warning', title: 'Peringatan', message: 'Sesi Anda akan berakhir dalam 5 menit.' })"
                                >
                                    Picu Toast Peringatan
                                </Button>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    @click="addToast({ variant: 'info', title: 'Informasi', message: 'Pembaruan data modul selesai disinkronisasi.' })"
                                >
                                    Picu Toast Info
                                </Button>
                            </div>
                        </div>
                    </Card>
                </div>
            </section>

            <!-- 6. Modals & Confirmation Dialogs -->
            <section class="space-y-4">
                <div class="border-b border-slate-200 dark:border-slate-800 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">
                        6. Dialog Modal &amp; Konfirmasi (useConfirm)
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Focus trap lengkap, penguncian scroll body, penutupan dengan tombol Escape / backdrop, dan pengembalian fokus.
                    </p>
                </div>

                <Card padding="md">
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <Button variant="secondary" @click="isExampleModalOpen = true">
                                Buka Modal Contoh
                            </Button>
                            <Button variant="primary" @click="testSimpleConfirm">
                                Uji Dialog Konfirmasi Primary
                            </Button>
                            <Button variant="danger" @click="testDangerousConfirm">
                                Uji Dialog Konfirmasi Bahaya (Dengan Alasan)
                            </Button>
                        </div>

                        <div v-if="confirmResult" class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono text-slate-700 dark:text-slate-300">
                            Hasil Dialog: {{ confirmResult }}
                        </div>
                    </div>
                </Card>

                <!-- Example Modal Component -->
                <Modal
                    :show="isExampleModalOpen"
                    title="Informasi Modul Operasional"
                    max-width="lg"
                    @close="isExampleModalOpen = false"
                >
                    <div class="space-y-3 text-sm text-slate-600 dark:text-slate-300">
                        <p>
                            Ini adalah contoh tampilan dialog modal standar pada sistem ERP Distribusi. Komponen ini memiliki focus trap aktif, menutup secara otomatis saat tombol Escape ditekan, dan mengembalikan fokus ke tombol pemicu saat ditutup.
                        </p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Semua dialog modal dirancang mematuhi standar WAI-ARIA Modal Dialog Pattern.
                        </p>
                    </div>

                    <template #footer>
                        <Button variant="secondary" @click="isExampleModalOpen = false">
                            Tutup
                        </Button>
                        <Button variant="primary" @click="isExampleModalOpen = false">
                            Selesai
                        </Button>
                    </template>
                </Modal>
            </section>

            <!-- 7. Formatters (Strict Float-Safe) -->
            <section class="space-y-4">
                <div class="border-b border-slate-200 dark:border-slate-800 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">
                        7. Komponen Formatter Nilai (Aman Float)
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Diformat dengan pemisahan string BigInt dan Intl.NumberFormat('id-ID') tanpa parseFloat() atau Number().
                    </p>
                </div>

                <Card padding="md">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 space-y-1">
                            <div class="text-2xs font-bold uppercase tracking-wider text-slate-400">Mata Uang Rupiah</div>
                            <div class="text-base font-bold text-slate-900 dark:text-white">
                                <Rupiah value="125500000.0000" />
                            </div>
                            <div class="text-2xs text-slate-500">Input: "125500000.0000"</div>
                        </div>

                        <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 space-y-1">
                            <div class="text-2xs font-bold uppercase tracking-wider text-slate-400">Kuantitas 4 Desimal</div>
                            <div class="text-base font-bold text-slate-900 dark:text-white">
                                <Quantity value="1250.7500" :decimals="4" />
                            </div>
                            <div class="text-2xs text-slate-500">Input: "1250.7500"</div>
                        </div>

                        <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 space-y-1">
                            <div class="text-2xs font-bold uppercase tracking-wider text-slate-400">Persentase</div>
                            <div class="text-base font-bold text-slate-900 dark:text-white">
                                <PercentDisplay value="11.0000" :decimals="2" />
                            </div>
                            <div class="text-2xs text-slate-500">Input: "11.0000" (PPN 11%)</div>
                        </div>

                        <div class="p-3 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/60 space-y-1">
                            <div class="text-2xs font-bold uppercase tracking-wider text-slate-400">Tanggal &amp; Waktu</div>
                            <div class="text-base font-bold text-slate-900 dark:text-white">
                                <DateDisplay value="2026-03-28 14:30:00" :with-time="true" />
                            </div>
                            <div class="text-2xs text-slate-500">WIB (id-ID)</div>
                        </div>
                    </div>
                </Card>
            </section>

            <!-- 8. DataTable, FilterBar & Pagination -->
            <section class="space-y-4">
                <div class="border-b border-slate-200 dark:border-slate-800 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">
                        8. DataTable, FilterBar &amp; Pagination
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Tabel responsif dengan header sticky, overflow internal, sorting interaktif, dan format mata uang aman.
                    </p>
                </div>

                <div class="space-y-3">
                    <FilterBar
                        v-model="searchQuery"
                        placeholder="Cari no dokumen atau nama pelanggan..."
                        :has-active-filters="Boolean(searchQuery)"
                        @reset="searchQuery = ''"
                    />

                    <Card padding="none">
                        <DataTable
                            :columns="tableColumns"
                            :items="filteredTableData"
                            :sort-field="sortField"
                            :sort-direction="sortDirection"
                            @sort="handleSort"
                        >
                            <template #cell(tanggal)="{ value }">
                                <DateDisplay :value="value" :with-time="true" />
                            </template>

                            <template #cell(kuantitas)="{ value }">
                                <Quantity :value="value" :decimals="2" />
                            </template>

                            <template #cell(diskon)="{ value }">
                                <PercentDisplay :value="value" :decimals="1" />
                            </template>

                            <template #cell(total)="{ value }">
                                <Rupiah :value="value" />
                            </template>

                            <template #cell(status)="{ value }">
                                <StatusBadge :status="value" />
                            </template>
                        </DataTable>

                        <div class="p-4 border-t border-slate-200 dark:border-slate-800">
                            <Pagination
                                :meta="paginationMeta"
                                @page-change="(p) => currentPage = p"
                                @per-page-change="(pp) => perPage = pp"
                            />
                        </div>
                    </Card>
                </div>
            </section>

            <!-- 9. State Views (Empty, Error, Loading) -->
            <section class="space-y-4">
                <div class="border-b border-slate-200 dark:border-slate-800 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">
                        9. State Views (Empty, Error &amp; Loading)
                    </h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Komponen standar untuk menangani kondisi data kosong, galat sistem, dan proses pemuatan data.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Card padding="none">
                        <EmptyState
                            title="Tidak Ada Pesanan"
                            description="Belum ada pesanan penjualan yang tercatat pada filter tanggal ini."
                        >
                            <template #actions>
                                <Button size="sm" variant="primary">
                                    <template #icon><Plus class="w-4 h-4" aria-hidden="true" /></template>
                                    Buat Pesanan Baru
                                </Button>
                            </template>
                        </EmptyState>
                    </Card>

                    <Card padding="none">
                        <ErrorState
                            title="Gagal Memuat Data"
                            message="Koneksi server mengalami batas waktu (timeout). Silakan periksa jaringan Anda."
                            retry-text="Coba Muat Ulang"
                            @retry="() => {}"
                        />
                    </Card>

                    <Card padding="none">
                        <LoadingState
                            message="Memuat data laporan neraca saldo..."
                            size="md"
                        />
                    </Card>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import axe from 'axe-core';
import PurchaseOrderPrint from '../Pages/Purchasing/PurchaseOrders/Print.vue';
import GoodsReceiptPrint from '../Pages/Purchasing/GoodsReceipts/Print.vue';
import PurchaseInvoicePrint from '../Pages/Purchasing/Invoices/Print.vue';
import SalesOrderPrint from '../Pages/Sales/SalesOrders/Print.vue';
import DeliveryOrderPrint from '../Pages/Sales/DeliveryOrders/Print.vue';
import StockAdjustmentPrint from '../Pages/Inventory/StockAdjustments/Print.vue';
import JournalPrint from '../Pages/Accounting/Journals/Print.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { id: 1 }, permissions: [] }, flash: {}, errors: {} } }),
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    Head: { name: 'Head', template: '<div hidden><slot /></div>' },
}));

async function expectNoViolations(element) {
    const results = await axe.run(element, {
        rules: { 'color-contrast': { enabled: false }, region: { enabled: false } },
    });

    expect(results.violations.filter((v) => ['critical', 'serious'].includes(v.impact) || v.id === 'heading-order')).toEqual([]);
}

const pricedLine = { lineNo: 1, sku: 'SKU-1', name: 'Barang A', unit: 'PCS', quantity: '4.0000', unitPrice: '777.0000', discountAmount: '8.00', taxAmount: '0.00', lineTotal: '3100.00' };
const quantityLine = { lineNo: 1, sku: 'SKU-1', name: 'Barang A', unit: 'PCS', quantity: '4.0000' };
const totals = { subtotal: '3108.00', discountAmount: '8.00', taxAmount: '0.00', total: '3100.00' };

const documents = {
    'purchase order': {
        page: PurchaseOrderPrint,
        back: '/purchasing/purchase-orders/1',
        title: 'Pesanan Pembelian',
        item: { id: 1, number: 'PO-1', status: 'approved', orderDate: '2026-11-01', expectedDate: null, notes: 'Kirim pagi', supplier: { name: 'Pemasok A', address: 'Jl. A' }, warehouse: { name: 'Gudang A' }, items: [pricedLine], ...totals },
        contains: ['Pemasok A', 'Jl. A', 'Gudang A', 'Barang A', 'Rp 3.100', 'Kirim pagi'],
    },
    'goods receipt': {
        page: GoodsReceiptPrint,
        back: '/purchasing/goods-receipts/1',
        title: 'Penerimaan Barang',
        item: { id: 1, number: 'GR-1', status: 'posted', receiptDate: '2026-11-01', notes: null, purchaseOrder: { id: 2, number: 'PO-2' }, supplier: { name: 'Pemasok A', address: 'Jl. A' }, warehouse: { name: 'Gudang A' }, items: [quantityLine] },
        contains: ['Pemasok A', 'PO-2', 'SKU-1', 'Barang A', 'Penerima'],
        noMoney: true,
    },
    'purchase invoice': {
        page: PurchaseInvoicePrint,
        back: '/purchasing/invoices/1',
        title: 'Faktur Pembelian',
        item: { id: 1, number: 'PI-1', status: 'posted', supplierInvoiceNumber: 'INV-77', invoiceDate: '2026-11-03', dueDate: '2026-12-03', notes: null, purchaseOrder: { id: 2, number: 'PO-2' }, supplier: { name: 'Pemasok A', address: 'Jl. A' }, paidAmount: '100.00', outstanding: '3000.00', items: [{ ...pricedLine, name: undefined, description: 'Barang dari faktur' }], ...totals },
        contains: ['INV-77', 'PO-2', 'Barang dari faktur', 'Sisa Tagihan', 'Rp 3.000'],
    },
    'sales order': {
        page: SalesOrderPrint,
        back: '/sales/sales-orders/1',
        title: 'Pesanan Penjualan',
        item: { id: 1, number: 'SO-1', status: 'approved', orderDate: '2026-11-01', notes: null, customer: { name: 'Pelanggan A', address: 'Jl. B' }, warehouse: { name: 'Gudang A' }, items: [pricedLine], ...totals },
        contains: ['Pelanggan A', 'Jl. B', 'Barang A', 'Rp 3.100'],
    },
    'delivery order': {
        page: DeliveryOrderPrint,
        back: '/sales/delivery-orders/1',
        title: 'Surat Jalan',
        item: { id: 1, number: 'DO-1', status: 'posted', deliveryDate: '2026-11-01', shippingAddress: 'Gudang Pelanggan, Jl. C', notes: null, salesOrder: { id: 2, number: 'SO-2' }, customer: { name: 'Pelanggan A', address: 'Jl. B' }, warehouse: { name: 'Gudang A' }, items: [quantityLine] },
        contains: ['Pelanggan A', 'Gudang Pelanggan, Jl. C', 'SO-2', 'Barang A', 'Pengemudi', 'Penerima'],
        noMoney: true,
    },
    'stock adjustment': {
        page: StockAdjustmentPrint,
        back: '/inventory/stock-adjustments/1',
        title: 'Penyesuaian Stok',
        item: { id: 1, number: 'SA-1', status: 'posted', adjustmentDate: '2026-11-02', reason: 'Stock opname', notes: null, warehouse: { name: 'Gudang A' }, items: [{ lineNo: 1, sku: 'SKU-1', name: 'Barang A', unit: 'PCS', systemQuantity: '10.0000', countedQuantity: '8.0000', differenceQuantity: '-2.0000' }] },
        contains: ['Stock opname', 'Gudang A', 'Stok Sistem', 'Hasil Hitung', '-2'],
        noMoney: true,
    },
    journal: {
        page: JournalPrint,
        back: '/accounting/journals/1',
        title: 'Jurnal',
        item: { id: 1, number: 'JV-1', status: 'posted', journalDate: '2026-11-03', description: 'Setoran kas', sourceLabel: null, reversalOfNumber: 'JV-0', totalDebit: '100.00', totalCredit: '100.00', lines: [{ lineNo: 1, code: '1101', name: 'Kas', description: null, debit: '100.00', credit: '0.00' }, { lineNo: 2, code: '3101', name: 'Modal', description: 'Awal', debit: '0.00', credit: '100.00' }] },
        contains: ['Setoran kas', 'Manual', 'JV-0', '1101', 'Modal', 'Rp 100'],
    },
};

const mountDocument = (name, overrides = {}) => mount(documents[name].page, {
    props: { item: { ...documents[name].item, ...overrides } },
    attachTo: document.body,
});

describe.each(Object.keys(documents))('%s print view', (name) => {
    const doc = documents[name];

    it('shows its title, number and content', () => {
        const wrapper = mountDocument(name);

        expect(wrapper.find('h1').text()).toBe(doc.title);
        expect(wrapper.text()).toContain(doc.item.number);

        for (const text of doc.contains) {
            expect(wrapper.text()).toContain(text);
        }
    });

    it('stamps a draft and a voided document, and leaves a valid one clean', () => {
        expect(mountDocument(name).find('[data-test="stamp"]').exists()).toBe(false);
        expect(mountDocument(name, { status: 'draft' }).find('[data-test="stamp"]').text()).toBe('Draf');
        expect(mountDocument(name, { status: 'void' }).find('[data-test="stamp"]').text()).toBe('Dibatalkan');
    });

    it('prints through the handler and goes back to its document', async () => {
        const print = vi.spyOn(window, 'print').mockImplementation(() => {});
        const wrapper = mountDocument(name);

        await wrapper.find('[data-test="print"]').trigger('click');

        expect(print).toHaveBeenCalledTimes(1);
        expect(wrapper.find(`a[href="${doc.back}"]`).exists()).toBe(true);
        print.mockRestore();
    });

    it('keeps its controls out of the printed page', () => {
        expect(mountDocument(name).find('[data-test="print"]').element.parentElement.className).toContain('print:hidden');
    });

    it('has no accessibility violations', async () => {
        await expectNoViolations(mountDocument(name).element);
    });
});

describe('warehouse documents', () => {
    it('sizes the signature columns with classes, because the CSP has no room for inline styles', () => {
        const signatures = mountDocument('delivery order').find('section[aria-label="Tanda tangan"]');

        expect(signatures.classes()).toContain('grid-cols-3');
        expect(signatures.attributes('style')).toBeUndefined();
    });

    it.each(['goods receipt', 'delivery order', 'stock adjustment'])('%s shows no money', (name) => {
        const wrapper = mountDocument(name);

        expect(wrapper.text()).not.toContain('Rp');
        expect(wrapper.text()).not.toContain('Harga');
        expect(wrapper.text()).not.toContain('Total');
    });
});

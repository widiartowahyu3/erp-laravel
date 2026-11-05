import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AllocationEditor from '../Components/accounting/AllocationEditor.vue';

const invoice = (id, outstanding, overrides = {}) => ({
    id,
    number: `INV/${id}`,
    dueDate: '2026-11-10',
    total: outstanding,
    outstanding,
    ...overrides,
});

const invoices = [invoice(1, '100.25'), invoice(2, '200.50'), invoice(3, '300.00')];

function mountEditor(props = {}) {
    return mount(AllocationEditor, {
        props: {
            invoices,
            amount: '',
            partyChosen: true,
            choosePartyMessage: 'Pilih pihak dulu.',
            noInvoicesMessage: 'Tidak ada faktur.',
            ...props,
        },
    });
}

const input = (wrapper, id) => wrapper.find(`input[aria-label="Alokasi untuk faktur INV/${id}"]`);
const autoButton = (wrapper) => wrapper.findAll('button').find((b) => b.text().trim() === 'Alokasikan otomatis');
const lastState = (wrapper) => wrapper.emitted('change').at(-1)[0];

describe('AllocationEditor', () => {
    it('fills oldest first down to the cent and stops when the amount runs out', async () => {
        const wrapper = mountEditor({ amount: '250.75' });

        await autoButton(wrapper).trigger('click');

        expect(input(wrapper, 1).element.value).toBe('100.25');
        expect(input(wrapper, 2).element.value).toBe('150.50');
        expect(input(wrapper, 3).element.value).toBe('');
        expect(lastState(wrapper).lines).toEqual([
            { invoiceId: 1, amount: '100.25' },
            { invoiceId: 2, amount: '150.50' },
        ]);
        expect(lastState(wrapper).fullyAllocated).toBe(true);
        expect(wrapper.find('[data-testid="allocated"]').text()).toContain('250,75');
    });

    it('leaves the excess unallocated when the amount exceeds everything owed', async () => {
        const wrapper = mountEditor({ amount: '1000.00' });

        await autoButton(wrapper).trigger('click');

        expect(lastState(wrapper).fullyAllocated).toBe(false);
        expect(lastState(wrapper).exceedsAmount).toBe(false);
        expect(wrapper.find('[data-testid="unallocated"]').text()).toContain('399,25');
    });

    it('shows a negative remainder and reports it without crashing when allocations exceed the amount', async () => {
        const wrapper = mountEditor({ amount: '50.00' });

        await input(wrapper, 1).setValue('60.00');

        expect(lastState(wrapper).exceedsAmount).toBe(true);
        expect(lastState(wrapper).fullyAllocated).toBe(false);
        expect(wrapper.find('[data-testid="unallocated"]').text()).toContain('10');
    });

    it('flags a row above what the invoice owes and text that is not money', async () => {
        const wrapper = mountEditor({ amount: '500.00' });

        await input(wrapper, 1).setValue('100.26');
        expect(wrapper.text()).toContain('Melebihi sisa tagihan');
        expect(lastState(wrapper).hasRowProblems).toBe(true);

        await input(wrapper, 1).setValue('1,5');
        expect(wrapper.text()).toContain('Isi dengan angka');
        expect(lastState(wrapper).hasRowProblems).toBe(true);

        await input(wrapper, 1).setValue('100.25');
        expect(lastState(wrapper).hasRowProblems).toBe(false);
    });

    it('starts from saved amounts and leaves out blank and zero rows', async () => {
        const wrapper = mountEditor({ amount: '100.00', saved: { 2: '100.00' } });

        expect(input(wrapper, 2).element.value).toBe('100.00');
        expect(lastState(wrapper).lines).toEqual([{ invoiceId: 2, amount: '100.00' }]);

        await input(wrapper, 1).setValue('0.00');

        expect(lastState(wrapper).lines).toEqual([{ invoiceId: 2, amount: '100.00' }]);
    });

    it('rebuilds its rows when the invoices change', async () => {
        const wrapper = mountEditor({ amount: '10.00' });

        await input(wrapper, 1).setValue('10.00');
        await wrapper.setProps({ invoices: [invoice(9, '50.00')] });

        expect(wrapper.find('input[aria-label="Alokasi untuk faktur INV/9"]').element.value).toBe('');
        expect(lastState(wrapper).lines).toEqual([]);
    });

    it('is never fully allocated at zero, or without a valid amount', async () => {
        expect(lastState(mountEditor({ amount: '0.00' })).fullyAllocated).toBe(false);
        expect(lastState(mountEditor({ amount: '12,5' })).amountIsValid).toBe(false);
        expect(autoButton(mountEditor({ amount: '12,5' })).attributes('disabled')).toBeDefined();
    });

    it('shows the right message before a party is chosen and when nothing is owed', () => {
        expect(mountEditor({ partyChosen: false, invoices: [] }).text()).toContain('Pilih pihak dulu.');
        expect(mountEditor({ invoices: [] }).text()).toContain('Tidak ada faktur.');
    });

    it('shows server errors on their rows and the supplier invoice number when there is one', () => {
        const wrapper = mountEditor({
            invoices: [invoice(1, '100.00', { supplierInvoiceNumber: 'INV-555' })],
            rowErrors: { 1: 'Alokasi ditolak server.' },
            error: 'Melebihi sisa pada faktur.',
        });

        expect(wrapper.find('tbody').text()).toContain('INV-555');
        expect(wrapper.find('tbody').text()).toContain('Alokasi ditolak server.');
        expect(wrapper.text()).toContain('Melebihi sisa pada faktur.');
    });
});

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import RelatedJournalCard from '../Components/accounting/RelatedJournalCard.vue';

const { mockPageProps } = vi.hoisted(() => ({
    mockPageProps: { auth: { user: { id: 1 }, permissions: ['journals.view'] }, flash: {}, errors: {} },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockPageProps }),
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

describe('RelatedJournalCard', () => {
    beforeEach(() => {
        mockPageProps.auth.permissions = ['journals.view'];
    });

    it('links to the journal and shows its status', () => {
        const wrapper = mount(RelatedJournalCard, { props: { journal: { id: 7, number: 'JV/202611/00007', status: 'posted' } } });

        expect(wrapper.find('h2').text()).toBe('Jurnal Terkait');
        expect(wrapper.find('a[href="/accounting/journals/7"]').text()).toBe('JV/202611/00007');
        expect(wrapper.text()).toContain('Diposting');
    });

    it('states when no journal exists', () => {
        const wrapper = mount(RelatedJournalCard, { props: { journal: null } });

        expect(wrapper.text()).toContain('Belum ada jurnal');
        expect(wrapper.find('a').exists()).toBe(false);
    });

    it('stays hidden from viewers who may not see journals', () => {
        mockPageProps.auth.permissions = [];
        const wrapper = mount(RelatedJournalCard, { props: { journal: { id: 7, number: 'JV/202611/00007', status: 'posted' } } });

        expect(wrapper.text()).not.toContain('Jurnal Terkait');
    });
});

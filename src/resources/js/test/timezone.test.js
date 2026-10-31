import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { formatTanggal } from '../composables/useFormatters';
import DateDisplay from '../Components/ui/DateDisplay.vue';

describe('Timezone handling across locales and timezones', () => {
    const originalTZ = process.env.TZ;

    const timezones = ['America/Los_Angeles', 'Asia/Jakarta'];

    timezones.forEach((tz) => {
        describe(`in timezone ${tz}`, () => {
            beforeAll(() => {
                process.env.TZ = tz;
            });

            afterAll(() => {
                process.env.TZ = originalTZ;
            });

            it('formats YYYY-MM-DD pure date as 30 Oktober 2026 without shifting day', () => {
                const formatted = formatTanggal('2026-10-30');
                expect(formatted).toBe('30 Oktober 2026');

                const wrapper = mount(DateDisplay, {
                    props: {
                        value: '2026-10-30',
                        format: 'medium',
                    },
                });
                expect(wrapper.text()).toBe('30 Oktober 2026');
            });

            it('formats ISO datetime in Asia/Jakarta timezone with honest WIB label', () => {
                // 2026-10-30T03:00:00.000000Z is 10:00:00 in WIB (UTC+7)
                const formatted = formatTanggal('2026-10-30T03:00:00.000000Z', 'datetime');
                expect(formatted).toContain('10:00 WIB');

                const wrapper = mount(DateDisplay, {
                    props: {
                        value: '2026-10-30T03:00:00.000000Z',
                        format: 'datetime',
                    },
                });
                expect(wrapper.text()).toContain('10:00 WIB');
            });
        });
    });
});

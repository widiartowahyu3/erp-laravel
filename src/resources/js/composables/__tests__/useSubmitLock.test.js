import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { useSubmitLock } from '../useSubmitLock';

describe('useSubmitLock', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('ignores second submission call while busy (only 1 send call executed)', () => {
        const { busy, submit } = useSubmitLock();
        const sendFn = vi.fn();

        expect(busy.value).toBe(false);

        // First submit call
        const accepted1 = submit(sendFn);
        expect(accepted1).toBe(true);
        expect(busy.value).toBe(true);
        expect(sendFn).toHaveBeenCalledTimes(1);

        // Second submit call while still busy
        const accepted2 = submit(sendFn);
        expect(accepted2).toBe(false);
        expect(sendFn).toHaveBeenCalledTimes(1);
    });

    it('ignores consecutive submissions within throttleMs interval', () => {
        const { busy, submit } = useSubmitLock({ throttleMs: 1000 });
        const sendFn = vi.fn(({ onFinish }) => {
            onFinish?.();
        });

        // 1st call at t = 0
        const accepted1 = submit(sendFn);
        expect(accepted1).toBe(true);
        expect(busy.value).toBe(false); // finished synchronously in mock

        // 2nd call at t = 500ms (< throttleMs)
        vi.advanceTimersByTime(500);
        const accepted2 = submit(sendFn);
        expect(accepted2).toBe(false);
        expect(sendFn).toHaveBeenCalledTimes(1);

        // 3rd call at t = 1001ms (> throttleMs)
        vi.advanceTimersByTime(501);
        const accepted3 = submit(sendFn);
        expect(accepted3).toBe(true);
        expect(sendFn).toHaveBeenCalledTimes(2);
    });

    it('resets busy state properly on onFinish, onError, or onCancel', () => {
        const { busy, submit } = useSubmitLock();

        // 1. Success with onFinish
        let finishCb;
        submit((options) => {
            finishCb = options.onFinish;
        });
        expect(busy.value).toBe(true);
        finishCb();
        expect(busy.value).toBe(false);

        // Advance timers past throttleMs
        vi.advanceTimersByTime(1100);

        // 2. Error with onError & onFinish
        let errorCb;
        submit((options) => {
            errorCb = options.onError;
            finishCb = options.onFinish;
        });
        expect(busy.value).toBe(true);
        errorCb?.({ error: 'fail' });
        finishCb();
        expect(busy.value).toBe(false);

        // Advance timers past throttleMs
        vi.advanceTimersByTime(1100);

        // 3. Cancel with onCancel & onFinish
        let cancelCb;
        submit((options) => {
            cancelCb = options.onCancel;
            finishCb = options.onFinish;
        });
        expect(busy.value).toBe(true);
        cancelCb?.();
        finishCb();
        expect(busy.value).toBe(false);
    });

    it('resets busy to false and rethrows if send throws synchronously', () => {
        const { busy, submit } = useSubmitLock();
        const brokenSend = vi.fn(() => {
            throw new Error('Sync error');
        });

        expect(() => submit(brokenSend)).toThrow('Sync error');
        expect(busy.value).toBe(false);
    });
});

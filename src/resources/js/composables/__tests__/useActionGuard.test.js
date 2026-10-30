import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useActionGuard } from '../useActionGuard';
import { setMockPageProps, resetMockPageProps } from '@/test-setup';

describe('useActionGuard', () => {
    beforeEach(() => {
        resetMockPageProps();
        vi.clearAllMocks();
    });

    it('denies all actions when user has no permissions', () => {
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Guest' },
                permissions: [],
            },
        });

        const { canEditAdjustment, canPostAdjustment, canVoidAdjustment } = useActionGuard();

        const draft = { status: 'draft' };
        expect(canEditAdjustment(draft)).toBe(false);
        expect(canPostAdjustment(draft)).toBe(false);
        expect(canVoidAdjustment(draft)).toBe(false);
    });

    it('denies actions when user has only view permission', () => {
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Viewer' },
                permissions: ['stock_adjustments.view'],
            },
        });

        const { canEditAdjustment, canPostAdjustment, canVoidAdjustment } = useActionGuard();

        expect(canEditAdjustment('draft')).toBe(false);
        expect(canPostAdjustment('draft')).toBe(false);
        expect(canVoidAdjustment('draft')).toBe(false);
        expect(canVoidAdjustment('posted')).toBe(false);
    });

    it('allows edit, post, and void on draft only if specific permissions are held', () => {
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Operator' },
                permissions: ['stock_adjustments.update'],
            },
        });

        const guard = useActionGuard();
        const draft = { status: 'draft' };

        expect(guard.canEditAdjustment(draft)).toBe(true);
        expect(guard.canPostAdjustment(draft)).toBe(false);
        expect(guard.canVoidAdjustment(draft)).toBe(false);
    });

    it('allows post on draft when post permission is held', () => {
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Supervisor' },
                permissions: ['stock_adjustments.post'],
            },
        });

        const guard = useActionGuard();
        expect(guard.canPostAdjustment({ status: 'draft' })).toBe(true);
        expect(guard.canEditAdjustment({ status: 'draft' })).toBe(false);
        expect(guard.canVoidAdjustment({ status: 'draft' })).toBe(false);
    });

    it('allows void on posted adjustment, but disallows edit and post regardless of permissions', () => {
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Admin' },
                permissions: [
                    'stock_adjustments.view',
                    'stock_adjustments.create',
                    'stock_adjustments.update',
                    'stock_adjustments.post',
                    'stock_adjustments.void',
                ],
            },
        });

        const guard = useActionGuard();
        const posted = { status: 'posted' };

        expect(guard.canEditAdjustment(posted)).toBe(false);
        expect(guard.canPostAdjustment(posted)).toBe(false);
        expect(guard.canVoidAdjustment(posted)).toBe(true);
    });

    it('disallows all actions on void status even for full-admin', () => {
        setMockPageProps({
            auth: {
                user: { id: 1, name: 'Admin' },
                permissions: [
                    'stock_adjustments.view',
                    'stock_adjustments.create',
                    'stock_adjustments.update',
                    'stock_adjustments.post',
                    'stock_adjustments.void',
                ],
            },
        });

        const guard = useActionGuard();
        const voidDoc = { status: 'void' };

        expect(guard.canEditAdjustment(voidDoc)).toBe(false);
        expect(guard.canPostAdjustment(voidDoc)).toBe(false);
        expect(guard.canVoidAdjustment(voidDoc)).toBe(false);
    });

    it('handles null, undefined, or invalid status safely', () => {
        const guard = useActionGuard(() => true);

        expect(guard.canEditAdjustment(null)).toBe(false);
        expect(guard.canPostAdjustment(undefined)).toBe(false);
        expect(guard.canVoidAdjustment({ status: 'unknown_status' })).toBe(false);
        expect(guard.canPerform('', 'draft')).toBe(false);
    });
});

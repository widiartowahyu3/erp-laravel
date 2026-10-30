import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useActionGuard } from '../useActionGuard';
import { setMockPageProps, resetMockPageProps } from '@/test-setup';

describe('useActionGuard', () => {
    beforeEach(() => {
        resetMockPageProps();
        vi.clearAllMocks();
    });

    describe('stock adjustments guard', () => {
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
    });

    describe('purchase orders guard', () => {
        it('guards actions across purchase order states according to permissions', () => {
            setMockPageProps({
                auth: {
                    user: { id: 2, name: 'Purchaser' },
                    permissions: [
                        'purchase_orders.view',
                        'purchase_orders.create',
                        'purchase_orders.update',
                        'purchase_orders.approve',
                        'purchase_orders.void',
                        'goods_receipts.create',
                    ],
                },
            });

            const guard = useActionGuard();

            // Draft
            expect(guard.canEditPurchaseOrder('draft')).toBe(true);
            expect(guard.canApprovePurchaseOrder('draft')).toBe(true);
            expect(guard.canVoidPurchaseOrder('draft')).toBe(true);
            expect(guard.canClosePurchaseOrder('draft')).toBe(false);
            expect(guard.canCreateReceiptFromPO('draft')).toBe(false);

            // Approved
            expect(guard.canEditPurchaseOrder('approved')).toBe(false);
            expect(guard.canApprovePurchaseOrder('approved')).toBe(false);
            expect(guard.canVoidPurchaseOrder('approved')).toBe(true);
            expect(guard.canClosePurchaseOrder('approved')).toBe(false);
            expect(guard.canCreateReceiptFromPO('approved')).toBe(true);

            // Partially received
            expect(guard.canEditPurchaseOrder('partially_received')).toBe(false);
            expect(guard.canClosePurchaseOrder('partially_received')).toBe(true);
            expect(guard.canVoidPurchaseOrder('partially_received')).toBe(true);
            expect(guard.canCreateReceiptFromPO('partially_received')).toBe(true);

            // Received
            expect(guard.canEditPurchaseOrder('received')).toBe(false);
            expect(guard.canClosePurchaseOrder('received')).toBe(true);
            expect(guard.canVoidPurchaseOrder('received')).toBe(false);
            expect(guard.canCreateReceiptFromPO('received')).toBe(false);

            // Closed & Void
            expect(guard.canEditPurchaseOrder('closed')).toBe(false);
            expect(guard.canClosePurchaseOrder('closed')).toBe(false);
            expect(guard.canVoidPurchaseOrder('closed')).toBe(false);

            expect(guard.canEditPurchaseOrder('void')).toBe(false);
            expect(guard.canApprovePurchaseOrder('void')).toBe(false);
            expect(guard.canVoidPurchaseOrder('void')).toBe(false);
        });

        it('denies approval or void if respective permissions are missing', () => {
            setMockPageProps({
                auth: {
                    user: { id: 3, name: 'Staff' },
                    permissions: ['purchase_orders.view', 'purchase_orders.update'],
                },
            });

            const guard = useActionGuard();
            expect(guard.canEditPurchaseOrder('draft')).toBe(true);
            expect(guard.canApprovePurchaseOrder('draft')).toBe(false);
            expect(guard.canVoidPurchaseOrder('draft')).toBe(false);
        });
    });

    describe('goods receipts guard', () => {
        it('guards actions across goods receipt states according to permissions', () => {
            setMockPageProps({
                auth: {
                    user: { id: 4, name: 'Warehouseman' },
                    permissions: [
                        'goods_receipts.view',
                        'goods_receipts.create',
                        'goods_receipts.update',
                        'goods_receipts.post',
                        'goods_receipts.void',
                    ],
                },
            });

            const guard = useActionGuard();

            // Draft
            expect(guard.canEditGoodsReceipt('draft')).toBe(true);
            expect(guard.canPostGoodsReceipt('draft')).toBe(true);
            expect(guard.canVoidGoodsReceipt('draft')).toBe(true);

            // Posted
            expect(guard.canEditGoodsReceipt('posted')).toBe(false);
            expect(guard.canPostGoodsReceipt('posted')).toBe(false);
            expect(guard.canVoidGoodsReceipt('posted')).toBe(true);

            // Void
            expect(guard.canEditGoodsReceipt('void')).toBe(false);
            expect(guard.canPostGoodsReceipt('void')).toBe(false);
            expect(guard.canVoidGoodsReceipt('void')).toBe(false);
        });
    });

    it('handles null, undefined, or invalid status safely', () => {
        const guard = useActionGuard(() => true);

        expect(guard.canEditAdjustment(null)).toBe(false);
        expect(guard.canPostAdjustment(undefined)).toBe(false);
        expect(guard.canVoidAdjustment({ status: 'unknown_status' })).toBe(false);
        expect(guard.canPerform('', 'draft', {}, {})).toBe(false);
    });
});

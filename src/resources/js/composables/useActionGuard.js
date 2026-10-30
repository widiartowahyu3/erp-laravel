import { usePermission } from '@/composables/usePermission';
import { stockAdjustmentStatusConfig, stockAdjustmentPermissions } from '@/config/inventory';
import {
    purchaseOrderStatusConfig,
    purchaseOrderPermissions,
    goodsReceiptStatusConfig,
    goodsReceiptPermissions,
} from '@/config/purchasing';

export function useActionGuard(customCan = null) {
    const permissionHook = customCan ? { can: customCan } : usePermission();

    function canPerform(action, statusOrDoc, config, permissions) {
        if (!action || !statusOrDoc) return false;

        const status = typeof statusOrDoc === 'object' && statusOrDoc !== null
            ? statusOrDoc.status
            : statusOrDoc;

        if (!status) return false;

        const normalizedStatus = String(status).toLowerCase();
        const statusEntry = config[normalizedStatus];
        if (!statusEntry || !Array.isArray(statusEntry.allowedActions)) {
            return false;
        }

        if (!statusEntry.allowedActions.includes(action)) {
            return false;
        }

        const requiredPermission = permissions[action];
        if (!requiredPermission) {
            return true;
        }

        return permissionHook.can(requiredPermission);
    }

    // Stock Adjustments
    function canPerformAdjustment(action, docOrStatus) {
        return canPerform(action, docOrStatus, stockAdjustmentStatusConfig, stockAdjustmentPermissions);
    }

    // Purchase Orders
    function canPerformPurchaseOrder(action, docOrStatus) {
        return canPerform(action, docOrStatus, purchaseOrderStatusConfig, purchaseOrderPermissions);
    }

    // Goods Receipts
    function canPerformGoodsReceipt(action, docOrStatus) {
        return canPerform(action, docOrStatus, goodsReceiptStatusConfig, goodsReceiptPermissions);
    }

    return {
        canPerform,

        // Stock Adjustments
        canPerformAdjustment,
        canEditAdjustment: (docOrStatus) => canPerformAdjustment('edit', docOrStatus),
        canPostAdjustment: (docOrStatus) => canPerformAdjustment('post', docOrStatus),
        canVoidAdjustment: (docOrStatus) => canPerformAdjustment('void', docOrStatus),

        // Purchase Orders
        canPerformPurchaseOrder,
        canEditPurchaseOrder: (docOrStatus) => canPerformPurchaseOrder('edit', docOrStatus),
        canApprovePurchaseOrder: (docOrStatus) => canPerformPurchaseOrder('approve', docOrStatus),
        canVoidPurchaseOrder: (docOrStatus) => canPerformPurchaseOrder('void', docOrStatus),
        canClosePurchaseOrder: (docOrStatus) => canPerformPurchaseOrder('close', docOrStatus),
        canCreateReceiptFromPO: (docOrStatus) => canPerformPurchaseOrder('create_receipt', docOrStatus),

        // Goods Receipts
        canPerformGoodsReceipt,
        canEditGoodsReceipt: (docOrStatus) => canPerformGoodsReceipt('edit', docOrStatus),
        canPostGoodsReceipt: (docOrStatus) => canPerformGoodsReceipt('post', docOrStatus),
        canVoidGoodsReceipt: (docOrStatus) => canPerformGoodsReceipt('void', docOrStatus),
    };
}

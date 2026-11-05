import { usePermission } from '@/composables/usePermission';
import { stockAdjustmentStatusConfig, stockAdjustmentPermissions } from '@/config/inventory';
import {
    purchaseOrderStatusConfig,
    purchaseOrderPermissions,
    goodsReceiptStatusConfig,
    goodsReceiptPermissions,
} from '@/config/purchasing';
import {
    salesOrderStatusConfig,
    salesOrderPermissions,
    deliveryOrderStatusConfig,
    deliveryOrderPermissions,
    salesInvoiceStatusConfig,
    salesInvoicePermissions,
} from '@/config/sales';
import { paymentStatusConfig, paymentPermissions } from '@/config/payments';

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

    // Sales Orders
    function canPerformSalesOrder(action, docOrStatus) {
        return canPerform(action, docOrStatus, salesOrderStatusConfig, salesOrderPermissions);
    }

    // Delivery Orders
    function canPerformDeliveryOrder(action, docOrStatus) {
        return canPerform(action, docOrStatus, deliveryOrderStatusConfig, deliveryOrderPermissions);
    }

    // Sales Invoices
    function canPerformSalesInvoice(action, docOrStatus) {
        return canPerform(action, docOrStatus, salesInvoiceStatusConfig, salesInvoicePermissions);
    }

    // Payments
    function canPerformPayment(action, docOrStatus) {
        return canPerform(action, docOrStatus, paymentStatusConfig, paymentPermissions);
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

        // Sales Orders
        canPerformSalesOrder,
        canEditSalesOrder: (docOrStatus) => canPerformSalesOrder('edit', docOrStatus),
        canApproveSalesOrder: (docOrStatus) => canPerformSalesOrder('approve', docOrStatus),
        canVoidSalesOrder: (docOrStatus) => canPerformSalesOrder('void', docOrStatus),
        canCloseSalesOrder: (docOrStatus) => canPerformSalesOrder('close', docOrStatus),
        canCreateDOFromSO: (docOrStatus) => canPerformSalesOrder('create_delivery', docOrStatus),

        // Delivery Orders
        canPerformDeliveryOrder,
        canEditDeliveryOrder: (docOrStatus) => canPerformDeliveryOrder('edit', docOrStatus),
        canPostDeliveryOrder: (docOrStatus) => canPerformDeliveryOrder('post', docOrStatus),
        canVoidDeliveryOrder: (docOrStatus) => canPerformDeliveryOrder('void', docOrStatus),
        canPerformSalesInvoice,
        canEditSalesInvoice: (docOrStatus) => canPerformSalesInvoice('edit', docOrStatus),
        canPostSalesInvoice: (docOrStatus) => canPerformSalesInvoice('post', docOrStatus),
        canVoidSalesInvoice: (docOrStatus) => canPerformSalesInvoice('void', docOrStatus),
        canPerformPayment,
        canEditPayment: (docOrStatus) => canPerformPayment('edit', docOrStatus),
        canPostPayment: (docOrStatus) => canPerformPayment('post', docOrStatus),
        canVoidPayment: (docOrStatus) => canPerformPayment('void', docOrStatus),
    };
}

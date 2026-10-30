import { usePermission } from '@/composables/usePermission';
import { stockAdjustmentStatusConfig, stockAdjustmentPermissions } from '@/config/inventory';

export function useActionGuard(customCan = null) {
    const permissionHook = customCan ? { can: customCan } : usePermission();

    function canPerform(action, statusOrDoc, config = stockAdjustmentStatusConfig, permissions = stockAdjustmentPermissions) {
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

    function canPerformAdjustment(action, docOrStatus) {
        return canPerform(action, docOrStatus, stockAdjustmentStatusConfig, stockAdjustmentPermissions);
    }

    return {
        canPerform,
        canPerformAdjustment,
        canEditAdjustment: (docOrStatus) => canPerformAdjustment('edit', docOrStatus),
        canPostAdjustment: (docOrStatus) => canPerformAdjustment('post', docOrStatus),
        canVoidAdjustment: (docOrStatus) => canPerformAdjustment('void', docOrStatus),
    };
}

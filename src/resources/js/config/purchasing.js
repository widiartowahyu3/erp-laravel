export const purchaseOrderStatusConfig = {
    draft: {
        label: 'Draf',
        variant: 'neutral',
        allowedActions: ['edit', 'approve', 'void'],
    },
    approved: {
        label: 'Disetujui',
        variant: 'info',
        allowedActions: ['create_receipt', 'void'],
    },
    partially_received: {
        label: 'Diterima Sebagian',
        variant: 'warning',
        allowedActions: ['create_receipt', 'close', 'void'],
    },
    received: {
        label: 'Diterima Lengkap',
        variant: 'success',
        allowedActions: ['close'],
    },
    closed: {
        label: 'Ditutup',
        variant: 'neutral',
        allowedActions: [],
    },
    void: {
        label: 'Dibatalkan',
        variant: 'danger',
        allowedActions: [],
    },
};

export const purchaseOrderPermissions = {
    view: 'purchase_orders.view',
    create: 'purchase_orders.create',
    edit: 'purchase_orders.update',
    update: 'purchase_orders.update',
    approve: 'purchase_orders.approve',
    void: 'purchase_orders.void',
    close: 'purchase_orders.update',
    create_receipt: 'goods_receipts.create',
};

export const goodsReceiptStatusConfig = {
    draft: {
        label: 'Draf',
        variant: 'neutral',
        allowedActions: ['edit', 'post', 'void'],
    },
    posted: {
        label: 'Diposting',
        variant: 'success',
        allowedActions: ['void'],
    },
    void: {
        label: 'Dibatalkan',
        variant: 'danger',
        allowedActions: [],
    },
};

export const goodsReceiptPermissions = {
    view: 'goods_receipts.view',
    create: 'goods_receipts.create',
    edit: 'goods_receipts.update',
    update: 'goods_receipts.update',
    post: 'goods_receipts.post',
    void: 'goods_receipts.void',
};

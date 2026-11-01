export const salesOrderStatusConfig = {
    draft: {
        label: 'Draf',
        variant: 'neutral',
        allowedActions: ['edit', 'approve', 'void'],
    },
    approved: {
        label: 'Disetujui',
        variant: 'info',
        allowedActions: ['create_delivery', 'void'],
    },
    partially_delivered: {
        label: 'Terkirim Sebagian',
        variant: 'warning',
        allowedActions: ['create_delivery', 'close', 'void'],
    },
    delivered: {
        label: 'Terkirim Lengkap',
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

export const salesOrderPermissions = {
    view: 'sales_orders.view',
    create: 'sales_orders.create',
    edit: 'sales_orders.update',
    update: 'sales_orders.update',
    approve: 'sales_orders.approve',
    void: 'sales_orders.void',
    close: 'sales_orders.update',
    create_delivery: 'delivery_orders.create',
};

export const deliveryOrderStatusConfig = {
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

export const deliveryOrderPermissions = {
    view: 'delivery_orders.view',
    create: 'delivery_orders.create',
    edit: 'delivery_orders.update',
    update: 'delivery_orders.update',
    post: 'delivery_orders.post',
    void: 'delivery_orders.void',
};

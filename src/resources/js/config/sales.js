export const salesOrderStatusConfig = {
    draft: {
        allowedActions: ['edit', 'approve', 'void'],
    },
    approved: {
        allowedActions: ['create_delivery', 'void'],
    },
    partially_delivered: {
        allowedActions: ['create_delivery', 'close', 'void'],
    },
    delivered: {
        allowedActions: ['close'],
    },
    closed: {
        allowedActions: [],
    },
    void: {
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
        allowedActions: ['edit', 'post', 'void'],
    },
    posted: {
        allowedActions: ['void'],
    },
    void: {
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

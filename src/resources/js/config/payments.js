export const paymentStatusConfig = {
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

export const paymentPermissions = {
    view: 'payments.view',
    create: 'payments.create',
    edit: 'payments.update',
    update: 'payments.update',
    post: 'payments.post',
    void: 'payments.void',
};

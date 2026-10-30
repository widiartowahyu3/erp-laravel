export const stockAdjustmentStatusConfig = {
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

export const stockAdjustmentPermissions = {
    view: 'stock_adjustments.view',
    create: 'stock_adjustments.create',
    edit: 'stock_adjustments.update',
    update: 'stock_adjustments.update',
    post: 'stock_adjustments.post',
    void: 'stock_adjustments.void',
};

export const stockMovementTypeConfig = {
    receipt: {
        label: 'Penerimaan',
        variant: 'info',
        sign: '+',
        isPositive: true,
    },
    delivery: {
        label: 'Pengeluaran',
        variant: 'danger',
        sign: '-',
        isPositive: false,
    },
    adjustment_in: {
        label: 'Penyesuaian Masuk',
        variant: 'info',
        sign: '+',
        isPositive: true,
    },
    adjustment_out: {
        label: 'Penyesuaian Keluar',
        variant: 'warning',
        sign: '-',
        isPositive: false,
    },
};

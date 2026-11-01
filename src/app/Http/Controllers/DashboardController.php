<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardSummary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Handle the incoming request to display the dashboard.
     */
    public function __invoke(Request $request, DashboardSummary $summary): Response
    {
        $user = $request->user();

        $purchaseOrders = $user?->can('purchase_orders.view')
            ? $summary->getPurchaseOrdersSummary()
            : null;

        $goodsReceipts = $user?->can('goods_receipts.view')
            ? $summary->getGoodsReceiptsSummary()
            : null;

        $salesOrders = $user?->can('sales_orders.view')
            ? $summary->getSalesOrdersSummary()
            : null;

        $deliveryOrders = $user?->can('delivery_orders.view')
            ? $summary->getDeliveryOrdersSummary()
            : null;

        $stockBalances = $user?->can('stock_balances.view')
            ? $summary->getStockBalancesSummary()
            : null;

        $stockMovements = $user?->can('stock_movements.view')
            ? $summary->getRecentStockMovements()
            : null;

        return Inertia::render('Dashboard', [
            'purchaseOrders' => $purchaseOrders,
            'goodsReceipts' => $goodsReceipts,
            'salesOrders' => $salesOrders,
            'deliveryOrders' => $deliveryOrders,
            'stockBalances' => $stockBalances,
            'stockMovements' => $stockMovements,
        ]);
    }
}

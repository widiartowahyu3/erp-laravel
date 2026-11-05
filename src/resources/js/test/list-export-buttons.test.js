import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const pagesDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../Pages');

describe('list pages offer an export of what they show', () => {
    it.each([
        ['MasterData/Products', '/master/products'],
        ['MasterData/Customers', '/master/customers'],
        ['MasterData/Suppliers', '/master/suppliers'],
        ['Inventory/StockBalances', '/inventory/stock-balances'],
        ['Inventory/StockMovements', '/inventory/stock-movements'],
        ['Purchasing/PurchaseOrders', '/purchasing/purchase-orders'],
        ['Purchasing/GoodsReceipts', '/purchasing/goods-receipts'],
        ['Purchasing/Invoices', '/purchasing/invoices'],
        ['Sales/SalesOrders', '/sales/sales-orders'],
        ['Sales/DeliveryOrders', '/sales/delivery-orders'],
        ['Sales/Invoices', '/sales/invoices'],
        ['Accounting/Payments', '/accounting/payments'],
        ['Accounting/Journals', '/accounting/journals'],
    ])('%s', (page, url) => {
        const source = fs.readFileSync(path.join(pagesDir, page, 'Index.vue'), 'utf-8');

        expect(source).toContain(`<ExportButton url="${url}" :query="items.query ?? {}" />`);
    });
});

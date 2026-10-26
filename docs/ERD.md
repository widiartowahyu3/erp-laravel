# Entity Relationship Diagram (ERD) - ERP Distribusi/Trading

Dokumen ini mendefinisikan Entity Relationship Diagram (ERD) untuk seluruh 37 tabel domain pada arsitektur database ERP Distribusi/Trading. Diagram disajikan menggunakan sintaks Mermaid `erDiagram` terstruktur per modul domain bisnis beserta pemetaan relasi antar modul.

---

## 1. Modul Administrasi & Keamanan (8 Tabel)

```mermaid
erDiagram
    users ||--o{ roles : "created_by / updated_by"
    users ||--o{ role_user : "user_id"
    roles ||--o{ role_user : "role_id"
    permissions ||--o{ permission_role : "permission_id"
    roles ||--o{ permission_role : "role_id"
    users ||--o{ audit_logs : "user_id"
    users ||--o{ settings : "updated_by"

    users {
        bigint id PK
        string name "100"
        string email "150 UK"
        string password "255"
        boolean is_active "default 1"
        timestamp last_login_at "nullable"
        string remember_token "100 nullable"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "nullable"
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    roles {
        bigint id PK
        string name "50 UK"
        string display_name "100"
        string description "255 nullable"
        boolean is_system "default 0"
        timestamp created_at
        timestamp updated_at
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    permissions {
        bigint id PK
        string name "100 UK"
        string module "50"
        string display_name "100"
        timestamp created_at
        timestamp updated_at
    }

    role_user {
        bigint role_id PK,FK
        bigint user_id PK,FK
        timestamp created_at "nullable"
    }

    permission_role {
        bigint permission_id PK,FK
        bigint role_id PK,FK
    }

    audit_logs {
        bigint id PK
        bigint user_id "FK users nullable"
        string event "50"
        string auditable_type "100"
        bigint auditable_id
        json old_values "nullable"
        json new_values "nullable"
        string ip_address "45 nullable"
        string user_agent "255 nullable"
        timestamp created_at
    }

    document_sequences {
        bigint id PK
        string doc_type "30 UK_part"
        string period "6 UK_part (YYYYMM)"
        string prefix "10"
        bigint last_number "default 0"
        timestamp created_at
        timestamp updated_at
    }

    settings {
        bigint id PK
        string key "100 UK"
        text value "nullable"
        string type "20 default string"
        string group "50"
        bigint updated_by "FK users nullable"
        timestamp created_at
        timestamp updated_at
    }
```

---

## 2. Modul Master Data (8 Tabel)

```mermaid
erDiagram
    product_categories ||--o{ product_categories : "parent_id"
    product_categories ||--o{ products : "category_id"
    units ||--o{ products : "base_unit_id"
    payment_terms ||--o{ suppliers : "payment_term_id"
    payment_terms ||--o{ customers : "payment_term_id"

    units {
        bigint id PK
        string code "10 UK"
        string name "50"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "nullable"
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    product_categories {
        bigint id PK
        bigint parent_id "FK self nullable"
        string code "30 UK"
        string name "100"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "nullable"
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    warehouses {
        bigint id PK
        string code "20 UK"
        string name "100"
        text address "nullable"
        boolean is_active "default 1"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "nullable"
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    tax_rates {
        bigint id PK
        string code "20 UK"
        string name "100"
        decimal rate "5,2 CHECK 0-100"
        boolean is_active "default 1"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "nullable"
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    payment_terms {
        bigint id PK
        string code "20 UK"
        string name "100"
        smallint days "unsigned default 0"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "nullable"
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    suppliers {
        bigint id PK
        string code "30 UK"
        string name "150"
        string contact_name "100 nullable"
        string phone "30 nullable"
        string email "150 nullable"
        text address "nullable"
        string tax_id "30 nullable"
        bigint payment_term_id "FK nullable"
        boolean is_active "default 1"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "nullable"
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    customers {
        bigint id PK
        string code "30 UK"
        string name "150"
        string contact_name "100 nullable"
        string phone "30 nullable"
        string email "150 nullable"
        text address "nullable"
        string tax_id "30 nullable"
        decimal credit_limit "18,2 CHECK >= 0"
        bigint payment_term_id "FK nullable"
        boolean is_active "default 1"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "nullable"
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    products {
        bigint id PK
        string sku "50 UK"
        string name "150"
        bigint category_id FK
        bigint base_unit_id FK
        string barcode "50 UK nullable"
        text description "nullable"
        boolean track_stock "default 1"
        decimal selling_price "18,2 CHECK >= 0"
        decimal min_stock "18,4 CHECK >= 0"
        boolean is_active "default 1"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "nullable"
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }
```

---

## 3. Modul Pembelian / Pengadaan (4 Tabel)

```mermaid
erDiagram
    suppliers ||--o{ purchase_orders : "supplier_id"
    warehouses ||--o{ purchase_orders : "warehouse_id"
    payment_terms ||--o{ purchase_orders : "payment_term_id"
    purchase_orders ||--o{ purchase_order_items : "purchase_order_id"
    products ||--o{ purchase_order_items : "product_id"
    tax_rates ||--o{ purchase_order_items : "tax_rate_id"
    purchase_orders ||--o{ goods_receipts : "purchase_order_id"
    suppliers ||--o{ goods_receipts : "supplier_id"
    warehouses ||--o{ goods_receipts : "warehouse_id"
    goods_receipts ||--o{ goods_receipt_items : "goods_receipt_id"
    purchase_order_items ||--o{ goods_receipt_items : "purchase_order_item_id"
    products ||--o{ goods_receipt_items : "product_id"

    purchase_orders {
        bigint id PK
        string number "30 UK"
        bigint supplier_id FK
        bigint warehouse_id FK
        date order_date
        date expected_date "nullable"
        bigint payment_term_id "FK nullable"
        string status "20 CHECK draft..void"
        decimal subtotal "18,2"
        decimal discount_amount "18,2"
        decimal tax_amount "18,2"
        decimal total "18,2 CHECK >= 0"
        text notes "nullable"
        bigint approved_by "FK users nullable"
        timestamp approved_at "nullable"
        bigint voided_by "FK users nullable"
        timestamp voided_at "nullable"
        string void_reason "255 nullable"
        timestamp created_at
        timestamp updated_at
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    purchase_order_items {
        bigint id PK
        bigint purchase_order_id "FK UK_part"
        smallint line_no "unsigned UK_part"
        bigint product_id FK
        decimal quantity "18,4 CHECK > 0"
        decimal received_quantity "18,4 CHECK >= 0"
        decimal unit_price "18,4 CHECK >= 0"
        decimal discount_amount "18,2"
        bigint tax_rate_id "FK nullable"
        decimal tax_amount "18,2"
        decimal line_total "18,2"
        timestamp created_at
        timestamp updated_at
    }

    goods_receipts {
        bigint id PK
        string number "30 UK"
        bigint purchase_order_id FK
        bigint supplier_id FK
        bigint warehouse_id FK
        date receipt_date
        string status "20 CHECK draft..void"
        text notes "nullable"
        bigint posted_by "FK users nullable"
        timestamp posted_at "nullable"
        bigint voided_by "FK users nullable"
        timestamp voided_at "nullable"
        string void_reason "255 nullable"
        timestamp created_at
        timestamp updated_at
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    goods_receipt_items {
        bigint id PK
        bigint goods_receipt_id "FK UK_part"
        smallint line_no "unsigned UK_part"
        bigint purchase_order_item_id FK
        bigint product_id FK
        decimal quantity "18,4 CHECK > 0"
        decimal unit_cost "18,4 CHECK >= 0"
        timestamp created_at
        timestamp updated_at
    }
```

---

## 4. Modul Inventori & Gudang (4 Tabel)

```mermaid
erDiagram
    products ||--o{ stock_balances : "product_id"
    warehouses ||--o{ stock_balances : "warehouse_id"
    products ||--o{ stock_movements : "product_id"
    warehouses ||--o{ stock_movements : "warehouse_id"
    warehouses ||--o{ stock_adjustments : "warehouse_id"
    stock_adjustments ||--o{ stock_adjustment_items : "stock_adjustment_id"
    products ||--o{ stock_adjustment_items : "product_id"

    stock_balances {
        bigint id PK
        bigint product_id "FK UK_part"
        bigint warehouse_id "FK UK_part"
        decimal quantity "18,4 CHECK >= 0"
        decimal avg_cost "18,4 CHECK >= 0"
        timestamp created_at
        timestamp updated_at
    }

    stock_movements {
        bigint id PK
        datetime movement_date
        bigint product_id FK
        bigint warehouse_id FK
        string movement_type "30 CHECK type"
        decimal quantity "18,4 CHECK <> 0"
        decimal unit_cost "18,4"
        decimal balance_after "18,4"
        decimal avg_cost_after "18,4"
        string source_type "50"
        bigint source_id
        bigint created_by "FK users nullable"
        timestamp created_at
    }

    stock_adjustments {
        bigint id PK
        string number "30 UK"
        bigint warehouse_id FK
        date adjustment_date
        string reason "255"
        string status "20 CHECK draft..void"
        text notes "nullable"
        bigint posted_by "FK users nullable"
        timestamp posted_at "nullable"
        bigint voided_by "FK users nullable"
        timestamp voided_at "nullable"
        string void_reason "255 nullable"
        timestamp created_at
        timestamp updated_at
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    stock_adjustment_items {
        bigint id PK
        bigint stock_adjustment_id "FK UK_part"
        smallint line_no "unsigned UK_part"
        bigint product_id FK
        decimal system_quantity "18,4"
        decimal counted_quantity "18,4 CHECK >= 0"
        decimal difference_quantity "18,4"
        decimal unit_cost "18,4"
        timestamp created_at
        timestamp updated_at
    }
```

---

## 5. Modul Penjualan & Distribusi (4 Tabel)

```mermaid
erDiagram
    customers ||--o{ sales_orders : "customer_id"
    warehouses ||--o{ sales_orders : "warehouse_id"
    payment_terms ||--o{ sales_orders : "payment_term_id"
    sales_orders ||--o{ sales_order_items : "sales_order_id"
    products ||--o{ sales_order_items : "product_id"
    tax_rates ||--o{ sales_order_items : "tax_rate_id"
    sales_orders ||--o{ delivery_orders : "sales_order_id"
    customers ||--o{ delivery_orders : "customer_id"
    warehouses ||--o{ delivery_orders : "warehouse_id"
    delivery_orders ||--o{ delivery_order_items : "delivery_order_id"
    sales_order_items ||--o{ delivery_order_items : "sales_order_item_id"
    products ||--o{ delivery_order_items : "product_id"

    sales_orders {
        bigint id PK
        string number "30 UK"
        bigint customer_id FK
        bigint warehouse_id FK
        date order_date
        bigint payment_term_id "FK nullable"
        string status "20 CHECK draft..void"
        decimal subtotal "18,2"
        decimal discount_amount "18,2"
        decimal tax_amount "18,2"
        decimal total "18,2 CHECK >= 0"
        text notes "nullable"
        bigint approved_by "FK users nullable"
        timestamp approved_at "nullable"
        bigint voided_by "FK users nullable"
        timestamp voided_at "nullable"
        string void_reason "255 nullable"
        timestamp created_at
        timestamp updated_at
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    sales_order_items {
        bigint id PK
        bigint sales_order_id "FK UK_part"
        smallint line_no "unsigned UK_part"
        bigint product_id FK
        decimal quantity "18,4 CHECK > 0"
        decimal delivered_quantity "18,4 CHECK >= 0"
        decimal unit_price "18,4 CHECK >= 0"
        decimal discount_amount "18,2"
        bigint tax_rate_id "FK nullable"
        decimal tax_amount "18,2"
        decimal line_total "18,2"
        timestamp created_at
        timestamp updated_at
    }

    delivery_orders {
        bigint id PK
        string number "30 UK"
        bigint sales_order_id FK
        bigint customer_id FK
        bigint warehouse_id FK
        date delivery_date
        text shipping_address "nullable"
        string status "20 CHECK draft..void"
        text notes "nullable"
        bigint posted_by "FK users nullable"
        timestamp posted_at "nullable"
        bigint voided_by "FK users nullable"
        timestamp voided_at "nullable"
        string void_reason "255 nullable"
        timestamp created_at
        timestamp updated_at
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    delivery_order_items {
        bigint id PK
        bigint delivery_order_id "FK UK_part"
        smallint line_no "unsigned UK_part"
        bigint sales_order_item_id FK
        bigint product_id FK
        decimal quantity "18,4 CHECK > 0"
        decimal unit_cost "18,4 (snapshot HPP)"
        timestamp created_at
        timestamp updated_at
    }
```

---

## 6. Modul Keuangan & Akuntansi (9 Tabel)

```mermaid
erDiagram
    chart_of_accounts ||--o{ chart_of_accounts : "parent_id"
    journals ||--o{ journals : "reversal_of_id"
    journals ||--o{ journal_lines : "journal_id"
    chart_of_accounts ||--o{ journal_lines : "account_id"
    customers ||--o{ sales_invoices : "customer_id"
    sales_orders ||--o{ sales_invoices : "sales_order_id"
    journals ||--o{ sales_invoices : "journal_id"
    sales_invoices ||--o{ sales_invoice_items : "sales_invoice_id"
    products ||--o{ sales_invoice_items : "product_id"
    delivery_order_items ||--o{ sales_invoice_items : "delivery_order_item_id"
    tax_rates ||--o{ sales_invoice_items : "tax_rate_id"
    suppliers ||--o{ purchase_invoices : "supplier_id"
    purchase_orders ||--o{ purchase_invoices : "purchase_order_id"
    journals ||--o{ purchase_invoices : "journal_id"
    purchase_invoices ||--o{ purchase_invoice_items : "purchase_invoice_id"
    products ||--o{ purchase_invoice_items : "product_id"
    goods_receipt_items ||--o{ purchase_invoice_items : "goods_receipt_item_id"
    tax_rates ||--o{ purchase_invoice_items : "tax_rate_id"
    customers ||--o{ payments : "customer_id"
    suppliers ||--o{ payments : "supplier_id"
    chart_of_accounts ||--o{ payments : "account_id"
    journals ||--o{ payments : "journal_id"
    payments ||--o{ payment_allocations : "payment_id"
    sales_invoices ||--o{ payment_allocations : "sales_invoice_id"
    purchase_invoices ||--o{ payment_allocations : "purchase_invoice_id"

    chart_of_accounts {
        bigint id PK
        string code "20 UK"
        string name "150"
        string type "20 CHECK"
        string normal_balance "6 CHECK"
        bigint parent_id "FK self nullable"
        boolean is_postable "default 1"
        boolean is_active "default 1"
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "nullable"
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    journals {
        bigint id PK
        string number "30 UK"
        date journal_date
        string description "255"
        string source_type "50 nullable"
        bigint source_id "nullable"
        string status "20 CHECK"
        decimal total_debit "18,2"
        decimal total_credit "18,2"
        bigint reversal_of_id "FK self nullable"
        bigint posted_by "FK users nullable"
        timestamp posted_at "nullable"
        bigint voided_by "FK users nullable"
        timestamp voided_at "nullable"
        string void_reason "255 nullable"
        timestamp created_at
        timestamp updated_at
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    journal_lines {
        bigint id PK
        bigint journal_id "FK UK_part"
        smallint line_no "unsigned UK_part"
        bigint account_id FK
        decimal debit "18,2 CHECK"
        decimal credit "18,2 CHECK"
        string description "255 nullable"
        timestamp created_at
        timestamp updated_at
    }

    sales_invoices {
        bigint id PK
        string number "30 UK"
        bigint customer_id FK
        bigint sales_order_id "FK nullable"
        date invoice_date
        date due_date
        string status "20 CHECK"
        decimal subtotal "18,2"
        decimal discount_amount "18,2"
        decimal tax_amount "18,2"
        decimal total "18,2"
        decimal paid_amount "18,2 CHECK <= total"
        bigint journal_id "FK nullable"
        text notes "nullable"
        bigint posted_by "FK users nullable"
        timestamp posted_at "nullable"
        bigint voided_by "FK users nullable"
        timestamp voided_at "nullable"
        string void_reason "255 nullable"
        timestamp created_at
        timestamp updated_at
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    sales_invoice_items {
        bigint id PK
        bigint sales_invoice_id "FK UK_part"
        smallint line_no "unsigned UK_part"
        bigint product_id "FK nullable"
        bigint delivery_order_item_id "FK nullable"
        string description "255"
        decimal quantity "18,4 CHECK > 0"
        decimal unit_price "18,4"
        decimal discount_amount "18,2"
        bigint tax_rate_id "FK nullable"
        decimal tax_amount "18,2"
        decimal line_total "18,2"
        timestamp created_at
        timestamp updated_at
    }

    purchase_invoices {
        bigint id PK
        string number "30 UK"
        bigint supplier_id "FK UK_part"
        bigint purchase_order_id "FK nullable"
        string supplier_invoice_number "50 UK_part"
        date invoice_date
        date due_date
        string status "20 CHECK"
        decimal subtotal "18,2"
        decimal discount_amount "18,2"
        decimal tax_amount "18,2"
        decimal total "18,2"
        decimal paid_amount "18,2 CHECK <= total"
        bigint journal_id "FK nullable"
        text notes "nullable"
        bigint posted_by "FK users nullable"
        timestamp posted_at "nullable"
        bigint voided_by "FK users nullable"
        timestamp voided_at "nullable"
        string void_reason "255 nullable"
        timestamp created_at
        timestamp updated_at
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    purchase_invoice_items {
        bigint id PK
        bigint purchase_invoice_id "FK UK_part"
        smallint line_no "unsigned UK_part"
        bigint product_id "FK nullable"
        bigint goods_receipt_item_id "FK nullable"
        string description "255"
        decimal quantity "18,4 CHECK > 0"
        decimal unit_price "18,4"
        decimal discount_amount "18,2"
        bigint tax_rate_id "FK nullable"
        decimal tax_amount "18,2"
        decimal line_total "18,2"
        timestamp created_at
        timestamp updated_at
    }

    payments {
        bigint id PK
        string number "30 UK"
        string payment_type "20 CHECK"
        bigint customer_id "FK nullable"
        bigint supplier_id "FK nullable"
        date payment_date
        string method "30 CHECK"
        bigint account_id FK
        decimal amount "18,2 CHECK > 0"
        string reference "100 nullable"
        string status "20 CHECK"
        bigint journal_id "FK nullable"
        text notes "nullable"
        bigint posted_by "FK users nullable"
        timestamp posted_at "nullable"
        bigint voided_by "FK users nullable"
        timestamp voided_at "nullable"
        string void_reason "255 nullable"
        timestamp created_at
        timestamp updated_at
        bigint created_by "FK users nullable"
        bigint updated_by "FK users nullable"
    }

    payment_allocations {
        bigint id PK
        bigint payment_id "FK UK_part"
        bigint sales_invoice_id "FK nullable UK_part"
        bigint purchase_invoice_id "FK nullable UK_part"
        decimal amount "18,2 CHECK > 0"
        timestamp created_at
        timestamp updated_at
    }
```

<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Account Mapping
    |--------------------------------------------------------------------------
    |
    | Logical account keys used by automatic journals, resolved by account code.
    | Every mapped account must exist, be active and be postable.
    |
    */

    'accounts' => [
        'inventory' => '1301',
        'receivable' => '1201',
        'payable' => '2101',
        'vat_output' => '2201',
        'vat_input' => '1401',
        'grir' => '2103',
        'sales' => '4101',
        'sales_discount' => '4201',
        'cogs' => '5101',
        'inventory_adjustment' => '5201',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cash and Bank Accounts
    |--------------------------------------------------------------------------
    |
    | Account codes that may be selected as the cash or bank side of a payment.
    |
    */

    'cash_bank' => ['1101', '1102'],
];

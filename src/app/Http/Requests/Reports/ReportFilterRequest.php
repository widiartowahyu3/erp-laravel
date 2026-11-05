<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Services\Reports\ReportFilter;
use Illuminate\Foundation\Http\FormRequest;

class ReportFilterRequest extends FormRequest
{
    private const int DEFAULT_PER_PAGE = 50;

    /**
     * Access is decided by the route's own permission.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d', 'required_with:date_to'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'required_with:date_from', 'after_or_equal:date_from'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'below_minimum' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
            'export' => ['nullable', 'in:csv'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date_from' => 'tanggal awal',
            'date_to' => 'tanggal akhir',
            'as_of' => 'tanggal posisi',
            'account_id' => 'akun',
            'customer_id' => 'pelanggan',
            'warehouse_id' => 'gudang',
            'per_page' => 'jumlah per halaman',
            'export' => 'format ekspor',
        ];
    }

    /**
     * The chosen filter, opening on the current month and today when nothing is chosen.
     */
    public function reportFilter(): ReportFilter
    {
        return new ReportFilter(
            dateFrom: $this->filled('date_from') ? $this->string('date_from')->toString() : now()->startOfMonth()->toDateString(),
            dateTo: $this->filled('date_to') ? $this->string('date_to')->toString() : now()->toDateString(),
            asOf: $this->filled('as_of') ? $this->string('as_of')->toString() : now()->toDateString(),
            accountId: $this->filled('account_id') ? $this->integer('account_id') : null,
            customerId: $this->filled('customer_id') ? $this->integer('customer_id') : null,
            warehouseId: $this->filled('warehouse_id') ? $this->integer('warehouse_id') : null,
            belowMinimumOnly: $this->boolean('below_minimum'),
            page: $this->filled('page') ? $this->integer('page') : 1,
            perPage: $this->filled('per_page') ? $this->integer('per_page') : self::DEFAULT_PER_PAGE,
        );
    }

    public function wantsCsv(): bool
    {
        return $this->input('export') === 'csv';
    }
}

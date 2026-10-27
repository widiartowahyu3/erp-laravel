<?php

declare(strict_types=1);

namespace App\Support\Inertia;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

final class Paginated
{
    private function __construct() {}

    /**
     * Format a paginator into the standard Inertia pagination payload structure.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param  LengthAwarePaginator<TKey, TValue>  $paginator
     * @param  (callable(TValue): mixed)|null  $transform
     * @return array{
     *     data: list<mixed>,
     *     meta: array{page: int, per_page: int, total: int, last_page: int, from: int|null, to: int|null},
     *     query: array{search: string|null, sort: string|null, direction: string|null, filter: array<string, mixed>}
     * }
     */
    public static function from(
        LengthAwarePaginator $paginator,
        ?callable $transform = null,
        ?Request $request = null
    ): array {
        $req = $request ?? request();

        $items = $paginator->items();
        $data = $transform !== null
            ? array_values(array_map($transform, $items))
            : array_values($items);

        $filter = $req->input('filter');

        return [
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'query' => [
                'search' => is_string($req->input('search')) ? $req->input('search') : null,
                'sort' => is_string($req->input('sort')) ? $req->input('sort') : null,
                'direction' => is_string($req->input('direction')) ? $req->input('direction') : null,
                'filter' => is_array($filter) ? $filter : [],
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Query;

use App\Http\Requests\Support\ListRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

/**
 * @template TModel of Model
 */
final class ListQuery
{
    /**
     * @var list<string>
     */
    private array $searchableColumns = [];

    /**
     * @var list<string>
     */
    private array $sortableColumns = [];

    /**
     * @var array<string, string|callable(Builder<TModel>, mixed): void>
     */
    private array $filterableDefinitions = [];

    private string $defaultSortColumn = 'id';

    private string $defaultSortDirection = 'desc';

    /**
     * @param  Builder<TModel>  $builder
     */
    private function __construct(
        private Builder $builder
    ) {}

    /**
     * Create a new ListQuery instance for an Eloquent builder or relation.
     *
     * @template T of Model
     *
     * @param  Builder<T>|Relation<T, Model, mixed>  $builder
     * @return self<T>
     */
    public static function for(Builder|Relation $builder): self
    {
        /** @var Builder<T> $eloquentBuilder */
        $eloquentBuilder = $builder instanceof Relation ? $builder->getQuery() : $builder;

        return new self($eloquentBuilder);
    }

    /**
     * Set columns eligible for LIKE search.
     *
     * @param  list<string>  $columns
     * @return $this
     */
    public function searchable(array $columns): self
    {
        $this->searchableColumns = $columns;

        return $this;
    }

    /**
     * Set columns eligible for sorting.
     *
     * @param  list<string>  $columns
     * @return $this
     */
    public function sortable(array $columns): self
    {
        $this->sortableColumns = $columns;

        return $this;
    }

    /**
     * Set filters with column mappings or callables.
     *
     * @param  array<string, string|callable(Builder<TModel>, mixed): void>  $definitions
     * @return $this
     */
    public function filterable(array $definitions): self
    {
        $this->filterableDefinitions = $definitions;

        return $this;
    }

    /**
     * Set default sort column and direction when sort parameter is omitted or invalid.
     *
     * @return $this
     */
    public function defaultSort(string $column, string $direction = 'desc'): self
    {
        $this->defaultSortColumn = $column;
        $this->defaultSortDirection = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return $this;
    }

    /**
     * Escape LIKE wildcards (\, %, _) to ensure user input is treated literally.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Apply search, sort, and filters to the builder.
     *
     * @return Builder<TModel>
     */
    public function apply(ListRequest|Request $request): Builder
    {
        $search = $request->input('search');
        if (is_string($search) && trim($search) !== '' && ! empty($this->searchableColumns)) {
            $escaped = self::escapeLike(trim($search));

            $this->builder->where(function (Builder $query) use ($escaped): void {
                foreach ($this->searchableColumns as $i => $column) {
                    if ($i === 0) {
                        $query->where($column, 'LIKE', "%{$escaped}%");
                    } else {
                        $query->orWhere($column, 'LIKE', "%{$escaped}%");
                    }
                }
            });
        }

        $filters = $request->input('filter');
        if (is_array($filters)) {
            foreach ($filters as $filterKey => $filterValue) {
                if ($filterValue === null || $filterValue === '') {
                    continue;
                }

                if (! array_key_exists($filterKey, $this->filterableDefinitions)) {
                    continue; // Strictly ignore unrecognized filter keys
                }

                $definition = $this->filterableDefinitions[$filterKey];

                if (is_callable($definition)) {
                    $definition($this->builder, $filterValue);
                } elseif (is_string($definition)) {
                    $this->builder->where($definition, (string) $filterValue);
                }
            }
        }

        $requestedSort = $request->input('sort');
        $rawDirection = (string) $request->input('direction', 'asc');
        $direction = strtolower($rawDirection) === 'desc' ? 'desc' : 'asc';

        if (is_string($requestedSort) && in_array($requestedSort, $this->sortableColumns, true)) {
            $this->builder->orderBy($requestedSort, $direction);

            // Secondary order by ID for deterministic pagination stability
            if ($requestedSort !== 'id') {
                $this->builder->orderBy('id', 'desc');
            }
        } else {
            $this->builder->orderBy($this->defaultSortColumn, $this->defaultSortDirection);

            if ($this->defaultSortColumn !== 'id') {
                $this->builder->orderBy('id', 'desc');
            }
        }

        return $this->builder;
    }

    /**
     * Apply query configurations and paginate results.
     *
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(ListRequest|Request $request, int $defaultPerPage = 25): LengthAwarePaginator
    {
        $this->apply($request);

        $perPageInput = $request->input('per_page');
        $perPage = is_numeric($perPageInput)
            ? max(1, min(100, (int) $perPageInput))
            : max(1, min(100, $defaultPerPage));

        $pageInput = $request->input('page');
        $page = is_numeric($pageInput)
            ? max(1, (int) $pageInput)
            : 1;

        /** @var LengthAwarePaginator<int, TModel> $paginator */
        $paginator = $this->builder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page
        );

        return $paginator;
    }
}

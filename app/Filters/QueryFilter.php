<?php

namespace App\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

abstract class QueryFilter
{
    public function __construct(protected Request $request) {}

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query): Builder
    {
        foreach ($this->availableFilters() as $filter) {
            $value = $this->request->query($filter);

            if ($value === null || $value === '') {
                continue;
            }

            if (method_exists($this, $filter)) {
                $this->{$filter}($query, $value);
            }
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    abstract protected function availableFilters(): array;
}

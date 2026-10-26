<?php

namespace App\Http\Controllers;

use App\Services\AccessControl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

abstract class Controller
{
    /**
     * Validation rule: the ID must be a row of $table that belongs to the user's current organization,
     * so one company can never point a document at another company's warehouse, product or record.
     */
    protected function inOrganization(Request $request, string $table): Exists
    {
        return Rule::exists($table, 'id')->where('organization_id', $request->user()->currentOrganization()->id);
    }

    /**
     * Validation rule: a warehouse of the user's company that the user works in. Used for every warehouse_id
     * a request sends, so an employee cannot reach another warehouse by changing the ID.
     */
    protected function accessibleWarehouse(Request $request): Exists
    {
        $ids = app(AccessControl::class)->warehouseIds($request->user());

        return $this->inOrganization($request, 'warehouses')->when($ids !== null, fn (Exists $rule) => $rule->whereIn('id', $ids));
    }

    /**
     * Limit a list to rows of warehouses the user works in (no change for full access).
     *
     * @template TQuery of Builder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    protected function inUserWarehouses(Builder|Relation $query, Request $request, string $column = 'warehouse_id'): Builder|Relation
    {
        return app(AccessControl::class)->scopeToWarehouses($query, $request->user(), $column);
    }

    /**
     * Rows per page from ?per_page=, kept between 1 and $max (100 unless a screen needs a full list).
     */
    protected function perPage(Request $request, int $default = 15, int $max = 100): int
    {
        return min(max($request->integer('per_page', $default), 1), $max);
    }
}

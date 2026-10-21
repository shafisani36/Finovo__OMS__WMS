<?php

namespace App\Http\Controllers;

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
     * Rows per page from ?per_page=, kept between 1 and 100.
     */
    protected function perPage(Request $request, int $default = 15): int
    {
        return min(max($request->integer('per_page', $default), 1), 100);
    }
}

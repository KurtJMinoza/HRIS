<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\User;
use App\Services\DataScopeService;
use Illuminate\Http\Request;

trait AssertsEmployeeOrgScope
{
    protected function assertEmployeeOrgScope(Request $request, User $employee): void
    {
        app(DataScopeService::class)->ensureEmployeeAccessible($request->user(), $employee);
    }
}

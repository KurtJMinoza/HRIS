<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** One grouped pass for branch roster counts — avoids correlated COUNT per branch row. */
final class BranchEmployeeCounts
{
    public static function subquery(): Builder
    {
        $visible = static fn ($query) => $query
            ->whereIn('u.role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('u.is_system_user', false)
            ->where('u.is_hidden', false);

        $direct = DB::table('users as u')
            ->tap($visible)
            ->whereNotNull('u.branch_id')
            ->selectRaw('u.branch_id as branch_id, u.id as user_id');

        $viaDepartment = DB::table('users as u')
            ->join('departments as ud', 'ud.id', '=', 'u.department_id')
            ->tap($visible)
            ->whereNotNull('ud.branch_id')
            ->selectRaw('ud.branch_id as branch_id, u.id as user_id');

        return DB::query()
            ->fromSub($direct->union($viaDepartment), 'branch_user_links')
            ->groupBy('branch_id')
            ->selectRaw('branch_id, count(distinct user_id) as employees_count');
    }
}

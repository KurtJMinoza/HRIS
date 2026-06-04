<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class HeadAssignmentEmployeeSearchCache
{
    private const VERSION_KEY = 'head_assignment_employee_search:version';

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public static function flush(): void
    {
        if (! Cache::has(self::VERSION_KEY)) {
            Cache::forever(self::VERSION_KEY, 1);

            return;
        }

        Cache::increment(self::VERSION_KEY);
    }
}

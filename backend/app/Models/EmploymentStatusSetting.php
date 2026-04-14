<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class EmploymentStatusSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        if (! Schema::hasTable('employment_status_settings')) {
            return $default;
        }

        $row = self::query()->where('key', $key)->first();
        if (! $row) {
            return $default;
        }

        return $row->value;
    }
}

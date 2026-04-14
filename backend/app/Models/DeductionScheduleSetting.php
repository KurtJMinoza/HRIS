<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeductionScheduleSetting extends Model
{
    public const SCHEDULE_15TH = '15th';

    public const SCHEDULE_30TH = '30th';

    public const SCHEDULE_BOTH = 'both';

    public const GOV_SSS = 'government:SSS';

    public const GOV_PHILHEALTH = 'government:PHILHEALTH';

    public const GOV_PAGIBIG = 'government:PAGIBIG';

    public const GOV_WITHHOLDING = 'government:WITHHOLDING';

    protected $fillable = [
        'company_id',
        'deduction_key',
        'schedule_type',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

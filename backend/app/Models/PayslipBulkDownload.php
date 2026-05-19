<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayslipBulkDownload extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'payroll_batch_run_id',
        'requested_by_user_id',
        'status',
        'total_files',
        'processed_files',
        'file_path',
        'file_format',
        'selected_employee_ids',
        'force_regenerate',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'selected_employee_ids' => 'array',
            'force_regenerate' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function payrollBatchRun(): BelongsTo
    {
        return $this->belongsTo(PayrollBatchRun::class, 'payroll_batch_run_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function progressPercent(): int
    {
        $total = max(0, (int) $this->total_files);
        if ($total === 0) {
            return match ((string) $this->status) {
                self::STATUS_COMPLETED => 100,
                self::STATUS_PROCESSING => 5,
                default => 0,
            };
        }

        return min(100, (int) round(((int) $this->processed_files / $total) * 100));
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentDocument extends Model
{
    public const TYPES = [
        'Resume',
        'Portfolio',
        'NBI Clearance',
        'Government ID',
        'Diploma / TOR',
        'Certificates',
        'Birth Certificate',
        'Medical',
        'Other Documents',
    ];

    public const STATUSES = ['Pending', 'Verified', 'Rejected'];

    protected $fillable = [
        'applicant_id',
        'document_type',
        'file_path',
        'file_name',
        'file_mime',
        'file_size',
        'status',
        'remarks',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplicant::class, 'applicant_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

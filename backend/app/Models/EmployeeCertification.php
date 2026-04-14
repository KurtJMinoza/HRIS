<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeCertification extends Model
{
    protected $table = 'employee_certifications';

    protected $fillable = [
        'user_id',
        'certification_name',
        'issuing_organization',
        'issue_date',
        'expiration_date',
        'credential_id',
        'credential_url',
        'certificate_path',
        'certificate_mime',
        'certificate_size',
        'verification_status',
        'verified_by',
        'verified_at',
        'rejection_reason',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiration_date' => 'date',
        'verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}

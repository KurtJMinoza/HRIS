<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeGovernmentIdDocument extends Model
{
    protected $table = 'employee_government_id_documents';

    protected $fillable = [
        'user_id',
        'id_type',
        'id_number',
        'issuing_agency',
        'expiry_date',
        'document_path',
        'document_mime',
        'document_size',
        'status',
        'verified_by',
        'verified_at',
        'rejection_reason',
    ];

    protected $casts = [
        'expiry_date' => 'date',
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeDocument extends Model
{
    protected $table = 'employee_documents';

    protected $fillable = [
        'user_id',
        'category',
        'document_name',
        'version',
        'expiry_date',
        'status',
        'review_note',
        'uploaded_by',
        'reviewed_by',
        'reviewed_at',
        'file_path',
        'file_mime',
        'file_size',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'reviewed_at' => 'datetime',
        'file_size' => 'integer',
    ];
}

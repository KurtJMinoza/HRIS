<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegularizationRequirement extends Model
{
    protected $fillable = [
        'user_id',
        'performance_review_completed',
        'performance_review_notes',
        'performance_review_completed_at',
        'performance_review_completed_by',
        'checklist_completed',
        'checklist_notes',
        'checklist_completed_at',
        'checklist_completed_by',
        'training_completed',
        'training_completed_at',
        'training_completed_by',
        'documents_submitted',
        'documents_submitted_at',
        'documents_submitted_by',
        'manager_recommendation_received',
        'manager_recommendation_received_at',
        'manager_recommendation_received_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'performance_review_completed' => 'boolean',
            'checklist_completed' => 'boolean',
            'training_completed' => 'boolean',
            'documents_submitted' => 'boolean',
            'manager_recommendation_received' => 'boolean',
            'performance_review_completed_at' => 'datetime',
            'checklist_completed_at' => 'datetime',
            'training_completed_at' => 'datetime',
            'documents_submitted_at' => 'datetime',
            'manager_recommendation_received_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

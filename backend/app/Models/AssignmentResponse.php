<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One learner's answer to one question. */
class AssignmentResponse extends Model
{
    protected $fillable = [
        'assignment_submission_id', 'assignment_item_id', 'body',
        'selected_options', 'is_correct', 'score',
    ];

    protected function casts(): array
    {
        return [
            'selected_options' => 'array',
            'is_correct' => 'boolean',
            'score' => 'float',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'assignment_submission_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssignmentItem::class, 'assignment_item_id');
    }
}

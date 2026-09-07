<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What one learner answered, once. */
class ClassAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_question_id', 'user_id', 'body', 'selected_options', 'is_correct', 'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'selected_options' => 'array',
            'is_correct' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ClassQuestion::class, 'class_question_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Tuesdays at 18:00, ninety minutes, from the first of Mehr."
 *
 * The rule is not the class: it is the instruction a generator follows to make
 * sessions ahead of now. Editing it never rewrites a session that has already
 * been taught, which is the whole reason the two are separate rows.
 */
class ClassScheduleRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_group_id', 'weekday', 'start_time', 'duration_minutes',
        'starts_on', 'ends_on', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'duration_minutes' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class, 'class_group_id');
    }
}

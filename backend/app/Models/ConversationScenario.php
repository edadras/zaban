<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConversationScenario extends Model
{
    use SoftDeletes;

    protected $table = 'conversation_scenarios';

    protected $fillable = [
        'language_id',
        'slug',
        'title',
        'setting',
        'situation',
        'learner_role',
        'ai_role',
        'character_id',
        'cefr_level_id',
        'objectives',
        'target_turns',
    ];

    protected function casts(): array
    {
        return [
            'objectives' => 'array',
        ];
    }
}

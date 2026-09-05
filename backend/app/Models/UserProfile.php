<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $table = 'user_profiles';

    protected $fillable = [
        'user_id',
        'native_language_id',
        'target_language_id',
        'country_code',
        'date_of_birth',
        'learning_objective',
        'profession',
        'interests',
        'favourite_topics',
    ];

    protected function casts(): array
    {
        return [
            'interests' => 'array',
            'favourite_topics' => 'array',
            'date_of_birth' => 'date',
        ];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function nativeLanguage(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Language::class, 'native_language_id');
    }

    public function targetLanguage(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Language::class, 'target_language_id');
    }
}

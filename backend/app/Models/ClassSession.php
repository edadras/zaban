<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One meeting of a class, at a time, in a room. */
class ClassSession extends Model
{
    use HasFactory;

    public const SCHEDULED = 'scheduled';
    public const LIVE = 'live';
    public const ENDED = 'ended';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'class_group_id', 'coach_id', 'schedule_rule_id', 'title', 'agenda',
        'starts_at', 'ends_at', 'status', 'room_name', 'started_at',
        'ended_at_actual', 'notified_at', 'recording_media_asset_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at_actual' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class, 'class_group_id');
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ClassMaterial::class)->orderBy('position');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ClassParticipant::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ClassQuestion::class);
    }

    public function isLive(): bool
    {
        return $this->status === self::LIVE;
    }

    /**
     * The window in which a learner may knock on the door.
     *
     * Fifteen minutes before the hour so nobody is locked out for being early,
     * and until the scheduled end plus an hour so a class that overruns does
     * not start turning people away mid-explanation.
     */
    public function isJoinable(): bool
    {
        if (in_array($this->status, [self::ENDED, self::CANCELLED], true)) {
            return false;
        }

        return now()->gte($this->starts_at->copy()->subMinutes(15))
            && now()->lte($this->ends_at->copy()->addHour());
    }
}

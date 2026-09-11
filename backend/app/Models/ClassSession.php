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

    // The life of a recording, from the coach pressing record to a file that
    // can be played. `processing` is the gap the media server needs after the
    // class ends to finish writing the file.
    public const RECORDING_OFF = 'none';

    public const RECORDING_STARTING = 'starting';

    public const RECORDING_ON = 'recording';

    public const RECORDING_PROCESSING = 'processing';

    public const RECORDING_READY = 'ready';

    public const RECORDING_FAILED = 'failed';

    protected $fillable = [
        'class_group_id', 'coach_id', 'schedule_rule_id', 'title', 'agenda',
        'starts_at', 'ends_at', 'status', 'stage', 'room_name', 'started_at',
        'ended_at_actual', 'notified_at', 'recording_media_asset_id',
        'recording_egress_id', 'recording_status', 'recording_started_at',
        'recording_ended_at', 'recording_duration_ms', 'recording_error',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at_actual' => 'datetime',
            'notified_at' => 'datetime',
            'recording_started_at' => 'datetime',
            'recording_ended_at' => 'datetime',
            'stage' => 'array',
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

    public function recording(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'recording_media_asset_id');
    }

    /** Whether there is something to play back. */
    public function hasRecording(): bool
    {
        return $this->recording_status === self::RECORDING_READY
            && $this->recording_media_asset_id !== null;
    }

    public function isRecording(): bool
    {
        return in_array($this->recording_status, [self::RECORDING_STARTING, self::RECORDING_ON], true);
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

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ClassChatMessage::class);
    }

    public function isLive(): bool
    {
        return $this->status === self::LIVE;
    }

    /**
     * The window in which a learner may knock on the door.
     *
     * Once the coach has opened the room (`live`), the door stays open until
     * they end it — even if the timetable said the hour was later. Without
     * that, starting a class early leaves every learner looking at a card with
     * no "Join" button.
     *
     * For a class still only scheduled: fifteen minutes before the hour so
     * nobody is locked out for being early, and until the scheduled end plus
     * an hour so a class that overruns does not start turning people away.
     */
    public function isJoinable(): bool
    {
        if (in_array($this->status, [self::ENDED, self::CANCELLED], true)) {
            return false;
        }

        if ($this->status === self::LIVE) {
            return true;
        }

        if ($this->starts_at === null || $this->ends_at === null) {
            return false;
        }

        return now()->gte($this->starts_at->copy()->subMinutes(15))
            && now()->lte($this->ends_at->copy()->addHour());
    }
}

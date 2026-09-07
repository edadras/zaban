<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's part at one school.
 *
 * The role lives here rather than on the user because it is not a property of
 * the person: teaching at one school and studying at another is an ordinary
 * thing for someone to do, and `users.role` can only hold one answer.
 */
class SchoolMember extends Model
{
    use HasFactory;

    public const OWNER = 'owner';
    public const ADMIN = 'admin';
    public const COACH = 'coach';
    public const STUDENT = 'student';

    /** The roles that may run a school. */
    public const MANAGERS = [self::OWNER, self::ADMIN];

    public const ROLES = [self::OWNER, self::ADMIN, self::COACH, self::STUDENT];

    protected $fillable = [
        'school_id', 'user_id', 'role', 'status', 'display_name', 'bio', 'invited_by', 'joined_at',
    ];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

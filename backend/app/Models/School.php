<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A language school: the people it employs, the learners it teaches.
 *
 * Everything in the classroom module hangs off this. A coach is a coach *at a
 * school*, not in general, so the same account can teach at one and study at
 * another without either school seeing the other's roll.
 */
class School extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'owner_user_id', 'timezone', 'locale', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(SchoolMember::class);
    }

    public function coaches(): HasMany
    {
        return $this->members()->where('role', SchoolMember::COACH);
    }

    public function students(): HasMany
    {
        return $this->members()->where('role', SchoolMember::STUDENT);
    }

    public function classGroups(): HasMany
    {
        return $this->hasMany(ClassGroup::class);
    }
}

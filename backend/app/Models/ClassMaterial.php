<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the coach brings to a session.
 *
 * Either a file they uploaded, or something the corpus already teaches, or
 * text they typed. `KINDS` names what the client should render; the nullable
 * foreign keys say where the content lives.
 */
class ClassMaterial extends Model
{
    use HasFactory;

    public const KINDS = ['video', 'pdf', 'image', 'audio', 'text', 'quiz', 'lesson', 'exercise'];

    protected $fillable = [
        'class_session_id', 'uploaded_by', 'kind', 'title', 'body',
        'media_asset_id', 'lesson_id', 'exercise_id', 'position', 'shared_at',
    ];

    protected function casts(): array
    {
        return ['shared_at' => 'datetime', 'position' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}

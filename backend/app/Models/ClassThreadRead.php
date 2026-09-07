<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** When somebody last looked at a thread. */
class ClassThreadRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['class_thread_id', 'user_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}

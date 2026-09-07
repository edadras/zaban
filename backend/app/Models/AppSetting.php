<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    protected $table = 'app_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        return Cache::remember("app_setting:{$key}", 60, function () use ($key, $default) {
            $row = static::query()->find($key);

            return $row?->value ?? $default;
        });
    }

    public static function putValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value],
        );
        Cache::forget("app_setting:{$key}");
    }

    public static function forgetCache(string $key): void
    {
        Cache::forget("app_setting:{$key}");
    }
}

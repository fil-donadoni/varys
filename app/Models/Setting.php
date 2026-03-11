<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = ['id'];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        return self::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function setValue(string $key, ?string $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}

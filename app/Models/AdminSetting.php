<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminSetting extends Model
{
    protected $fillable = ['key', 'value', 'type'];

    /**
     * Get a setting value by key with type casting.
     *
     * When $type is provided, the $default is also cast through the same
     * type-casting logic so callers always receive the expected PHP type
     * even when no DB row exists yet.
     */
    public static function getValue(string $key, mixed $default = null, ?string $type = null): mixed
    {
        $setting = static::where('key', $key)->first();

        $raw = $setting?->value ?? $default;
        $castType = $setting?->type ?? $type;

        return match ($castType) {
            'boolean' => static::castBoolean($raw),
            'integer' => (int) $raw,
            'json'    => is_string($raw) ? json_decode($raw, true) : $raw,
            default   => $raw,
        };
    }

    /**
     * Safely cast a value to boolean.
     *
     * The string "false" must be treated as false — PHP's (bool) cast
     * treats any non-empty string as true.
     */
    private static function castBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Set a setting value by key.
     */
    public static function setValue(string $key, mixed $value, string $type = 'string'): static
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => is_array($value) ? json_encode($value) : (string) $value, 'type' => $type]
        );
    }
}

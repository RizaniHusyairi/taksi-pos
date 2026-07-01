<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Setting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Memo per-request: semua setting di-load 1x lalu dibaca dari memori.
     * (Sengaja BUKAN Cache facade — driver cache di sini 'database', jadi cache
     * justru = query DB. Memo statis lebih murah & otomatis reset tiap request.)
     */
    protected static ?\Illuminate\Support\Collection $memo = null;

    public static function getValue(string $key, $default = null)
    {
        if (static::$memo === null) {
            static::$memo = static::pluck('value', 'key');
        }
        return static::$memo->get($key, $default);
    }

    public static function forgetMemo(): void
    {
        static::$memo = null;
    }
}

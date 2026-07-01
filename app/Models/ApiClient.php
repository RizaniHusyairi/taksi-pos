<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Kredensial API key untuk sistem eksternal (website manajemen koperasi)
 * mengonsumsi Management API. Plaintext key hanya ada saat dibuat; DB
 * menyimpan hash-nya saja.
 */
class ApiClient extends Model
{
    protected $fillable = ['name', 'key_hash', 'key_prefix', 'last_used_at', 'active'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'active'       => 'boolean',
    ];

    /**
     * Buat API key baru. Kembalikan [plaintextKey, ApiClient].
     * Plaintext HANYA dikembalikan di sini (sekali) — tampilkan ke admin lalu buang.
     */
    public static function generate(string $name): array
    {
        $plain = 'tpk_' . Str::random(48);

        $client = static::create([
            'name'       => $name,
            'key_hash'   => hash('sha256', $plain),
            'key_prefix' => substr($plain, 0, 12),
            'active'     => true,
        ]);

        return [$plain, $client];
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');                     // label klien (mis. "Website Koperasi Pusat")
            $table->string('key_hash', 64)->unique();   // SHA-256 dari API key (plaintext TIDAK disimpan)
            $table->string('key_prefix', 20);           // potongan awal key untuk identifikasi di UI
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};

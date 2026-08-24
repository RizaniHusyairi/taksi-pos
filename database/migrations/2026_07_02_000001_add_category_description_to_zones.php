<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zona dikelompokkan mengikuti poster tarif resmi bandara:
     * - category    : 'dalam' (Dalam Kota Samarinda) | 'luar' (Luar Kota)
     * - description : keterangan area (mis. "Depan Bandara") — opsional
     */
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->string('category', 10)->default('dalam')->after('price');
            $table->string('description')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn(['category', 'description']);
        });
    }
};

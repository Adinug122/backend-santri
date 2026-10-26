<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('laporans', function (Blueprint $table) {
            $table->boolean('menyangkut_pimpinan')->default(false)->after('tampilkan_identitas');
            $table->timestamp('pengawas_dibaca')->nullable()->after('pimpinan_dibaca');
            $table->index(['menyangkut_pimpinan', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laporans', function (Blueprint $table) {
            $table->dropIndex(['menyangkut_pimpinan', 'status']);
            $table->dropColumn(['menyangkut_pimpinan', 'pengawas_dibaca']);
        });
    }
};

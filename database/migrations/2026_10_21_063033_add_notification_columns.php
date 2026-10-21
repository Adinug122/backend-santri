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
            $table->timestamp('status_diubah')->nullable();
            $table->timestamp('santri_dibaca')->nullable();
            $table->timestamp('pengurus_dibaca')->nullable();
            $table->timestamp('pimpinan_dibaca')->nullable();
        });

        Schema::table('percakapans', function (Blueprint $table) {
            $table->index(['laporan_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('percakapans', function (Blueprint $table) {
            $table->dropForeign(['laporan_id']);
            $table->dropIndex(['laporan_id', 'created_at']);
            $table->foreign('laporan_id')->references('id')->on('laporans')->cascadeOnDelete();
        });

        Schema::table('laporans', function (Blueprint $table) {
            $table->dropColumn(['status_diubah', 'santri_dibaca', 'pengurus_dibaca', 'pimpinan_dibaca']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('laporans', function (Blueprint $table) {
            $table->string('status', 32)->default('baru')->change();
            $table->boolean('is_locked')->default(false)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laporans', function (Blueprint $table) {
            $table->dropColumn('is_locked');
        });

        DB::table('laporans')
            ->where('status', 'DITERUSKAN_KE_BERWAJIB')
            ->update(['status' => 'diproses']);

        Schema::table('laporans', function (Blueprint $table) {
            $table->enum('status', ['diproses', 'baru', 'selesai'])->default('baru')->change();
        });
    }
};

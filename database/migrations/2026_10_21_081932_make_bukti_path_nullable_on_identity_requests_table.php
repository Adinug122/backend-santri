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
        Schema::table('identity_requests', function (Blueprint $table) {
            $table->string('bukti_path')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Permintaan identitas tanpa bukti tidak bisa disimpan pada skema lama,
     * sehingga record tersebut dibuang sebelum kolom dibuat non-nullable.
     */
    public function down(): void
    {
        DB::table('identity_requests')->whereNull('bukti_path')->delete();

        Schema::table('identity_requests', function (Blueprint $table) {
            $table->string('bukti_path')->nullable(false)->change();
        });
    }
};

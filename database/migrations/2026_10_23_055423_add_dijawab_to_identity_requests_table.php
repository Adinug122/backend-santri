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
            $table->dateTime('dijawab')->nullable()->after('status');
        });

        DB::table('identity_requests')
            ->whereIn('status', ['disetujui', 'ditolak'])
            ->whereNull('dijawab')
            ->update(['dijawab' => DB::raw('updated_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('identity_requests', function (Blueprint $table) {
            $table->dropColumn('dijawab');
        });
    }
};

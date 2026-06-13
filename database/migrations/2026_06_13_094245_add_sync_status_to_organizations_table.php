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
        Schema::table('organizations', function (Blueprint $table) {
            // pending → syncing → done | failed
            $table->string('sync_status')->default('pending')->after('last_synced_at');
            $table->text('sync_error')->nullable()->after('sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['sync_status', 'sync_error']);
        });
    }
};

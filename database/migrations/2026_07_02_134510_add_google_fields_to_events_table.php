<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('google_event_id')->nullable()->after('color');
            $table->string('google_calendar_id')->nullable()->after('google_event_id');
            $table->timestamp('google_updated_at')->nullable()->after('google_calendar_id');
            $table->timestamp('last_synced_at')->nullable()->after('google_updated_at');
            $table->string('sync_source')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'google_event_id',
                'google_calendar_id',
                'google_updated_at',
                'last_synced_at',
                'sync_source',
            ]);
        });
    }
};

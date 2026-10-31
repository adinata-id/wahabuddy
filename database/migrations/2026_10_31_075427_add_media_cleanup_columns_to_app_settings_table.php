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
        Schema::table('app_settings', function (Blueprint $table) {
            // Storage selection for new uploads (public local disk or s3).
            $table->string('media_disk')->default('public');

            // Auto-cleanup cadence: daily | weekly | monthly | custom.
            // weekly: media_cleanup_day is 0 (Sunday) - 6 (Saturday).
            // monthly: media_cleanup_day is 1-28. custom: interval in days.
            $table->boolean('media_cleanup_enabled')->default(true);
            $table->string('media_cleanup_frequency')->default('weekly');
            $table->unsignedTinyInteger('media_cleanup_day')->nullable();
            $table->unsignedSmallInteger('media_cleanup_interval_days')->nullable();
            // Keep media this many days after its last referencing row went
            // terminal (WhatsApp stories live ~24h; 3 days adds margin).
            $table->unsignedSmallInteger('media_cleanup_retention_days')->default(3);
            // Also purge media of canceled/expired/failed_max work, not only
            // sent/completed (off by default: owner opt-in).
            $table->boolean('media_cleanup_include_terminal')->default(false);
            $table->timestamp('media_cleanup_last_run_at')->nullable();
            $table->timestamp('media_cleanup_next_run_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn([
                'media_disk',
                'media_cleanup_enabled',
                'media_cleanup_frequency',
                'media_cleanup_day',
                'media_cleanup_interval_days',
                'media_cleanup_retention_days',
                'media_cleanup_include_terminal',
                'media_cleanup_last_run_at',
                'media_cleanup_next_run_at',
            ]);
        });
    }
};

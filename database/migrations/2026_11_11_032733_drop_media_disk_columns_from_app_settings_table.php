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
            // Media storage is S3-only now (WAHA downloads media itself, so
            // uploads need presigned URLs, not a local disk plus a tunnel):
            // the disk choice and its public base URL are gone. Existing
            // media_files rows keep their disk value for previews/cleanup.
            $table->dropColumn(['media_disk', 'media_public_base_url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('media_disk')->default('public');
            $table->string('media_public_base_url')->nullable()->after('media_disk');
        });
    }
};

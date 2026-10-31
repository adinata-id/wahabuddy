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
        // Bounded audit trail for the sent-media cleanup (newest 100 rows
        // are kept, mirroring the story-log retention approach).
        Schema::create('media_cleanup_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger'); // scheduled | manual
            $table->string('status')->default('running'); // running | completed | failed
            $table->unsignedInteger('files_purged')->default(0);
            $table->unsignedBigInteger('bytes_freed')->default(0);
            $table->unsignedInteger('files_failed')->default(0);
            $table->boolean('capped')->default(false);
            $table->text('message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_cleanup_runs');
    }
};

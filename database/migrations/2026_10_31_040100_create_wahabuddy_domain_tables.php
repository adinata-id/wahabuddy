<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WahaBuddy domain tables per spec section F: portable migrations,
     * foreign keys, indexes, string-backed enums, UTC timestamps.
     */
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('video_codec')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // image | video | text
            $table->foreignId('media_file_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $table->text('text')->nullable();
            $table->string('background_color')->default('#38b42f');
            $table->unsignedTinyInteger('font')->default(1);
            $table->timestamp('scheduled_at');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sending_started_at')->nullable();
            $table->uuid('attempt_token')->nullable();
            $table->unsignedInteger('connection_revision');
            $table->string('waha_message_id')->nullable();
            $table->string('waha_key_id')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('deleted_from_waha_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['status', 'sending_started_at']);
        });

        Schema::create('story_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->string('event');
            $table->uuid('attempt_token')->nullable();
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['story_id', 'created_at', 'id']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('connection_revision');
            $table->string('chat_id');
            $table->string('name')->nullable();
            $table->string('type')->default('contact'); // contact | group
            $table->timestamp('opted_in_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_revision', 'chat_id']);
            $table->index(['type', 'chat_id']);
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // text | image
            $table->foreignId('media_file_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $table->text('template_text');
            $table->string('status')->default('draft'); // draft|queued|running|paused|completed|canceled
            $table->unsignedInteger('connection_revision');
            $table->timestamp('next_send_at')->nullable();
            $table->unsignedInteger('batch_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_send_at']);
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained('broadcasts')->restrictOnDelete();
            $table->string('chat_id');
            $table->string('name')->nullable();
            $table->text('rendered_text');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->uuid('attempt_token')->nullable();
            $table->timestamp('sending_started_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('waha_message_id')->nullable();
            $table->string('waha_key_id')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['broadcast_id', 'chat_id']);
            $table->index(['broadcast_id', 'status']);
        });

        Schema::create('broadcast_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained('broadcasts')->cascadeOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('broadcast_recipients')->cascadeOnDelete();
            $table->string('event');
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['broadcast_id', 'created_at', 'id']);
        });

        Schema::create('scheduler_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status')->default('running'); // running|succeeded|failed
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('send_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('attempt_token')->unique();
            $table->string('subject_type'); // story | recipient
            $table->unsignedBigInteger('subject_id');
            $table->unsignedInteger('connection_revision');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('outcome')->default('started'); // started|sent|rejected|unknown
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('message_id')->nullable();
            $table->string('key_id')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('send_attempts');
        Schema::dropIfExists('scheduler_runs');
        Schema::dropIfExists('broadcast_logs');
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcasts');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('story_logs');
        Schema::dropIfExists('stories');
        Schema::dropIfExists('media_files');
    }
};

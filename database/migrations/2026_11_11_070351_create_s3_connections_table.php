<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('s3_connections', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->text('key')->nullable();
            $table->text('secret')->nullable();
            $table->string('bucket')->nullable();
            $table->string('region', 64)->default('auto');
            $table->string('endpoint')->nullable();
            $table->boolean('use_path_style_endpoint')->default(true);
            $table->timestamp('last_test_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->text('last_test_error')->nullable();
            // Single-row guard: concurrent web/worker/scheduler first reads
            // race on creation (spec 3.1); the unique constant pins one row.
            $table->boolean('singleton')->default(1)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('s3_connections');
    }
};

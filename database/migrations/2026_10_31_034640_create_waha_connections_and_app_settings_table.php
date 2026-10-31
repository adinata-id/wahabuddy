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
        Schema::create('waha_connections', function (Blueprint $table) {
            $table->id();
            $table->string('base_url')->nullable();
            $table->text('api_key')->nullable();
            $table->string('basic_username')->nullable();
            $table->text('basic_password')->nullable();
            $table->string('session')->default('default');
            $table->unsignedInteger('revision')->default(1);
            $table->json('capabilities')->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->text('last_test_error')->nullable();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('display_timezone')->default('Asia/Jakarta');
            $table->unsignedTinyInteger('broadcast_delay_min')->default(8);
            $table->unsignedTinyInteger('broadcast_delay_max')->default(20);
            $table->unsignedSmallInteger('broadcast_batch_size')->default(50);
            $table->unsignedSmallInteger('broadcast_batch_pause')->default(60);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waha_connections');
        Schema::dropIfExists('app_settings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ext_resellerclub_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('type');
            $table->string('idempotency_key')->unique();
            $table->unsignedBigInteger('rc_order_id')->nullable();
            $table->unsignedBigInteger('rc_action_id')->nullable();
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ext_resellerclub_operations');
    }
};

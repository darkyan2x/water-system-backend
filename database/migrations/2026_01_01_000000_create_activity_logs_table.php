<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_role')->nullable()->index();

            $table->foreignId('target_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('target_account_number')->nullable()->index();
            $table->string('target_account_name')->nullable();

            $table->string('module')->index();
            $table->string('action')->index();
            $table->text('description')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();

            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['module', 'action']);
            $table->index(['target_user_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};

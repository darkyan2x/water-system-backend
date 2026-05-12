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
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->string('name')->index();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            $table->string('role')->default('user');
            $table->string('account_number')->nullable()->index();
            $table->string('mobile')->nullable();
            $table->string('address')->nullable();
            $table->string('barangay')->nullable()->index();
            $table->string('purok')->nullable();
            $table->string('account_name')->nullable();
            $table->unsignedInteger('current_reading')->nullable();
            $table->unsignedInteger('previous_reading')->nullable();
            $table->decimal('x_coordinate', 10, 7)->nullable();
            $table->decimal('y_coordinate', 10, 7)->nullable();
            $table->enum('status', ['ok', 'delinquent', 'due', 'setup', 'for_reading', 'disconnected'])
                ->default('ok')
                ->index();
            $table->unsignedInteger('last_usage')->nullable();
            $table->date('billing_date')->nullable()->index();
            $table->enum('account_type', ['residential', 'commercial', 'industrial', 'special_use'])
                ->default('residential')
                ->index();

            $table->decimal('balance', 12, 2)->default(0);

            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};

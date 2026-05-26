<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reading_id')
                ->constrained('readings')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('teller_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->decimal('amount', 12, 2);

            $table->date('payment_date');

            $table->string('or_number')->nullable();
            $table->string('payment_method')->nullable(); // cash, gcash, bank, etc.
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index(['reading_id', 'payment_date']);
            $table->index(['user_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_payments');
    }
};

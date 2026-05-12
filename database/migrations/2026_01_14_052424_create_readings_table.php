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
        Schema::create('readings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('encoder_user_id')->nullable()->index();
            $table->date('date')->index();
            $table->unsignedInteger('value');  // meter reading value
            $table->unsignedInteger('usage')->nullable(); // computed usage
            $table->enum('status', ['paid', 'unpaid'])->default('unpaid')->index();

            $table->timestamps();

            // Optional: prevent duplicates per user per billing date
            $table->unique(['user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('readings');
    }
};

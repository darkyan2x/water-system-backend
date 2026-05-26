<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            $table->integer('previous_reading')->default(0)->after('date');
            $table->integer('current_reading')->default(0)->after('previous_reading');
        });

        // Copy old value column into amount_due first
        Schema::table('readings', function (Blueprint $table) {
            $table->decimal('amount_due', 12, 2)->default(0)->after('usage');
        });

        DB::statement('UPDATE readings SET amount_due = value');

        // Optional: remove value after confirming your code no longer uses it
        Schema::table('readings', function (Blueprint $table) {
            $table->dropColumn('value');
        });
    }

    public function down(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            $table->integer('value')->default(0)->after('date');
        });

        DB::statement('UPDATE readings SET value = amount_due');

        Schema::table('readings', function (Blueprint $table) {
            $table->dropColumn([
                'previous_reading',
                'current_reading',
                'amount_due',
            ]);
        });
    }
};
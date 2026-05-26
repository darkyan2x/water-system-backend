<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE readings
            MODIFY status ENUM('unpaid', 'partial', 'paid')
            NOT NULL DEFAULT 'unpaid'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE readings
            MODIFY status ENUM('paid', 'unpaid')
            NOT NULL DEFAULT 'unpaid'
        ");
    }
};

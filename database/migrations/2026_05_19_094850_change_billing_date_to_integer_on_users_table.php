<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // If billing_date currently contains real dates, preserve only the day number.
        DB::statement("ALTER TABLE users ADD COLUMN billing_date_temp TINYINT UNSIGNED NULL AFTER billing_date");

        DB::statement("
            UPDATE users 
            SET billing_date_temp = 
                CASE 
                    WHEN billing_date IS NULL THEN NULL
                    ELSE DAY(billing_date)
                END
        ");

        DB::statement("ALTER TABLE users DROP COLUMN billing_date");

        DB::statement("
            ALTER TABLE users 
            CHANGE billing_date_temp billing_date TINYINT UNSIGNED NULL
        ");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users ADD COLUMN billing_date_temp DATETIME NULL AFTER billing_date");

        DB::statement("
            UPDATE users 
            SET billing_date_temp = 
                CASE 
                    WHEN billing_date IS NULL THEN NULL
                    ELSE STR_TO_DATE(CONCAT(YEAR(CURDATE()), '-', MONTH(CURDATE()), '-', billing_date), '%Y-%m-%d')
                END
        ");

        DB::statement("ALTER TABLE users DROP COLUMN billing_date");

        DB::statement("
            ALTER TABLE users 
            CHANGE billing_date_temp billing_date DATETIME NULL
        ");
    }
};
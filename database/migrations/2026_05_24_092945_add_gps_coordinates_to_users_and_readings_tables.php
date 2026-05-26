<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'latitude')) {
            Schema::table('users', function (Blueprint $table) {
                $table->decimal('latitude', 10, 7)->nullable()->after('barangay');
            });
        }

        if (!Schema::hasColumn('users', 'longitude')) {
            Schema::table('users', function (Blueprint $table) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            });
        }

        if (!Schema::hasColumn('readings', 'latitude')) {
            Schema::table('readings', function (Blueprint $table) {
                $table->decimal('latitude', 10, 7)->nullable()->after('amount_due');
            });
        }

        if (!Schema::hasColumn('readings', 'longitude')) {
            Schema::table('readings', function (Blueprint $table) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('readings', 'longitude')) {
            Schema::table('readings', function (Blueprint $table) {
                $table->dropColumn('longitude');
            });
        }

        if (Schema::hasColumn('readings', 'latitude')) {
            Schema::table('readings', function (Blueprint $table) {
                $table->dropColumn('latitude');
            });
        }

        if (Schema::hasColumn('users', 'longitude')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('longitude');
            });
        }

        if (Schema::hasColumn('users', 'latitude')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('latitude');
            });
        }
    }
};
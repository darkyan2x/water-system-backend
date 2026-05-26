<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('readings', 'amount_due')) {
            Schema::table('readings', function (Blueprint $table) {
                $table->decimal('amount_due', 12, 2)->default(0)->after('usage');
            });
        }

        if (! Schema::hasColumn('readings', 'amount_paid')) {
            Schema::table('readings', function (Blueprint $table) {
                $table->decimal('amount_paid', 12, 2)->default(0)->after('amount_due');
            });
        }

        if (! Schema::hasColumn('readings', 'payment_status')) {
            Schema::table('readings', function (Blueprint $table) {
                $table->string('payment_status')->default('unpaid')->index()->after('amount_paid');
            });
        }

        /**
         * Backfill existing records.
         * If amount_due is greater than 0 and amount_paid is 0, mark unpaid.
         */
        if (
            Schema::hasColumn('readings', 'amount_due') &&
            Schema::hasColumn('readings', 'amount_paid') &&
            Schema::hasColumn('readings', 'payment_status')
        ) {
            DB::table('readings')
                ->whereRaw('COALESCE(amount_due, 0) > COALESCE(amount_paid, 0)')
                ->update([
                    'payment_status' => 'unpaid',
                ]);

            DB::table('readings')
                ->whereRaw('COALESCE(amount_due, 0) > 0')
                ->whereRaw('COALESCE(amount_paid, 0) >= COALESCE(amount_due, 0)')
                ->update([
                    'payment_status' => 'paid',
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            if (Schema::hasColumn('readings', 'payment_status')) {
                $table->dropColumn('payment_status');
            }

            if (Schema::hasColumn('readings', 'amount_paid')) {
                $table->dropColumn('amount_paid');
            }

            if (Schema::hasColumn('readings', 'amount_due')) {
                $table->dropColumn('amount_due');
            }
        });
    }
};
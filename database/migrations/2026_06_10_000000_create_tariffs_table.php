<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tariffs')) {
            Schema::create('tariffs', function (Blueprint $table) {
                $table->id();
                $table->string('account_type')->unique(); // residential, commercial, industrial, special_use
                $table->string('display_name'); // Residential, Commercial, Industrial, Special Use
                $table->decimal('base_rate', 12, 2)->default(0);
                $table->unsignedInteger('base_cubic_meters')->default(0);
                $table->json('tiers')->nullable();
                $table->decimal('excess_rate', 12, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        $now = now();

        $defaults = [
            [
                'account_type' => 'residential',
                'display_name' => 'Residential',
                'base_rate' => 50.00,
                'base_cubic_meters' => 10,
                'tiers' => json_encode([
                    ['id' => 'r1', 'label' => 'Next 10 cu. m.', 'price' => 5.00, 'isFlat' => false],
                    ['id' => 'r2', 'label' => 'Next 10 cu. m.', 'price' => 6.00, 'isFlat' => false],
                    ['id' => 'r3', 'label' => 'Next 10 cu. m.', 'price' => 6.50, 'isFlat' => false],
                    ['id' => 'r4', 'label' => 'Next 10 cu. m.', 'price' => 7.00, 'isFlat' => false],
                    ['id' => 'r5', 'label' => 'Next 10 cu. m.', 'price' => 7.50, 'isFlat' => false],
                    ['id' => 'r6', 'label' => 'Next 10 cu. m.', 'price' => 8.00, 'isFlat' => false],
                    ['id' => 'r7', 'label' => 'Next 10 cu. m.', 'price' => 8.50, 'isFlat' => false],
                    ['id' => 'r8', 'label' => 'Next 10 cu. m.', 'price' => 9.00, 'isFlat' => false],
                    ['id' => 'r9', 'label' => 'Next 10 cu. m.', 'price' => 9.50, 'isFlat' => false],
                ]),
                'excess_rate' => 12.00,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'account_type' => 'commercial',
                'display_name' => 'Commercial',
                'base_rate' => 100.00,
                'base_cubic_meters' => 10,
                'tiers' => json_encode([
                    ['id' => 'c1', 'label' => 'Next 10 cu. m.', 'price' => 6.00, 'isFlat' => false],
                    ['id' => 'c2', 'label' => 'Next 10 cu. m.', 'price' => 7.00, 'isFlat' => false],
                    ['id' => 'c3', 'label' => 'Next 10 cu. m.', 'price' => 8.00, 'isFlat' => false],
                    ['id' => 'c4', 'label' => 'Next 10 cu. m.', 'price' => 9.00, 'isFlat' => false],
                    ['id' => 'c5', 'label' => 'Next 10 cu. m.', 'price' => 10.00, 'isFlat' => false],
                    ['id' => 'c6', 'label' => 'Next 10 cu. m.', 'price' => 11.00, 'isFlat' => false],
                    ['id' => 'c7', 'label' => 'Next 10 cu. m.', 'price' => 12.00, 'isFlat' => false],
                    ['id' => 'c8', 'label' => 'Next 10 cu. m.', 'price' => 13.00, 'isFlat' => false],
                    ['id' => 'c9', 'label' => 'Next 10 cu. m.', 'price' => 13.50, 'isFlat' => false],
                ]),
                'excess_rate' => 15.00,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'account_type' => 'industrial',
                'display_name' => 'Industrial',
                'base_rate' => 1000.00,
                'base_cubic_meters' => 100,
                'tiers' => json_encode([
                    ['id' => 'i1', 'label' => 'Next 50 cu. m.', 'price' => 12.00, 'isFlat' => false],
                    ['id' => 'i2', 'label' => 'Next 50 cu. m.', 'price' => 13.00, 'isFlat' => false],
                    ['id' => 'i3', 'label' => 'Next 50 cu. m.', 'price' => 14.00, 'isFlat' => false],
                    ['id' => 'i4', 'label' => 'Next 50 cu. m.', 'price' => 15.00, 'isFlat' => false],
                    ['id' => 'i5', 'label' => 'Next 50 cu. m.', 'price' => 16.00, 'isFlat' => false],
                    ['id' => 'i6', 'label' => 'Next 50 cu. m.', 'price' => 17.00, 'isFlat' => false],
                    ['id' => 'i7', 'label' => 'Next 50 cu. m.', 'price' => 18.00, 'isFlat' => false],
                    ['id' => 'i8', 'label' => 'Next 50 cu. m.', 'price' => 19.00, 'isFlat' => false],
                ]),
                'excess_rate' => 20.00,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'account_type' => 'special_use',
                'display_name' => 'Special Use',
                'base_rate' => 0.00,
                'base_cubic_meters' => 0,
                'tiers' => json_encode([]),
                'excess_rate' => 30.00,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($defaults as $default) {
            DB::table('tariffs')->updateOrInsert(
                ['account_type' => $default['account_type']],
                $default
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tariffs');
    }
};

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tariff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TariffController extends Controller
{
    public function index(Request $request)
    {
        $this->ensureDefaultTariffsExist();

        return response()->json([
            'rates' => $this->frontendRatesPayload(),
            'tariffs' => Tariff::query()
                ->where('is_active', true)
                ->orderByRaw("FIELD(account_type, 'residential', 'commercial', 'industrial', 'special_use')")
                ->get(),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'rates' => ['required', 'array'],
        ]);

        $admin = $request->user();

        if (!$admin || !Hash::check($validated['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'password' => ['Invalid authorization password.'],
            ]);
        }

        $role = strtolower(trim((string) $admin->role));

        if (!in_array($role, ['admin', 'master'], true)) {
            return response()->json([
                'message' => 'Only admin or master accounts can update tariffs.',
            ], 403);
        }

        $this->ensureDefaultTariffsExist();

        DB::transaction(function () use ($validated) {
            $rates = $validated['rates'];

            foreach ($this->defaultConfigs() as $accountType => $defaultConfig) {
                $displayName = $defaultConfig['display_name'];
                $incoming = $rates[$displayName] ?? $rates[$accountType] ?? null;

                if (!is_array($incoming)) {
                    continue;
                }

                $baseRate = $this->numberValue($incoming['baseRate'] ?? $incoming['base_rate'] ?? $defaultConfig['base_rate']);
                $baseCbm = (int) $this->numberValue($incoming['baseCbm'] ?? $incoming['base_cubic_meters'] ?? $defaultConfig['base_cubic_meters']);
                $excessRate = $this->numberValue($incoming['excessPrice'] ?? $incoming['excess_rate'] ?? $defaultConfig['excess_rate']);
                $tiers = $this->cleanTiers($incoming['tiers'] ?? $defaultConfig['tiers']);

                Tariff::query()->updateOrCreate(
                    ['account_type' => $accountType],
                    [
                        'display_name' => $displayName,
                        'base_rate' => $baseRate,
                        'base_cubic_meters' => max(0, $baseCbm),
                        'tiers' => $tiers,
                        'excess_rate' => $excessRate,
                        'is_active' => true,
                    ]
                );
            }
        });

        return response()->json([
            'message' => 'Tariffs updated successfully.',
            'rates' => $this->frontendRatesPayload(),
        ]);
    }

    private function frontendRatesPayload(): array
    {
        $tariffs = Tariff::query()
            ->where('is_active', true)
            ->get()
            ->keyBy('account_type');

        $payload = [];

        foreach ($this->defaultConfigs() as $accountType => $defaultConfig) {
            $tariff = $tariffs->get($accountType);
            $displayName = $tariff?->display_name ?: $defaultConfig['display_name'];

            $payload[$displayName] = [
                'type' => $displayName,
                'baseRate' => (float) ($tariff?->base_rate ?? $defaultConfig['base_rate']),
                'baseCbm' => (int) ($tariff?->base_cubic_meters ?? $defaultConfig['base_cubic_meters']),
                'tiers' => $tariff?->tiers ?: $defaultConfig['tiers'],
                'excessPrice' => (float) ($tariff?->excess_rate ?? $defaultConfig['excess_rate']),
            ];
        }

        return $payload;
    }

    private function ensureDefaultTariffsExist(): void
    {
        foreach ($this->defaultConfigs() as $accountType => $config) {
            Tariff::query()->firstOrCreate(
                ['account_type' => $accountType],
                [
                    'display_name' => $config['display_name'],
                    'base_rate' => $config['base_rate'],
                    'base_cubic_meters' => $config['base_cubic_meters'],
                    'tiers' => $config['tiers'],
                    'excess_rate' => $config['excess_rate'],
                    'is_active' => true,
                ]
            );
        }
    }

    private function cleanTiers($tiers): array
    {
        if (!is_array($tiers)) {
            return [];
        }

        $clean = [];

        foreach (array_values($tiers) as $index => $tier) {
            if (!is_array($tier)) {
                continue;
            }

            $clean[] = [
                'id' => (string) ($tier['id'] ?? 'tier_' . ($index + 1)),
                'label' => (string) ($tier['label'] ?? 'Next cu. m.'),
                'price' => $this->numberValue($tier['price'] ?? 0),
                'isFlat' => (bool) ($tier['isFlat'] ?? false),
            ];
        }

        return $clean;
    }

    private function numberValue($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $number = (float) $value;

        return $number < 0 ? 0.0 : $number;
    }

    private function defaultConfigs(): array
    {
        return [
            'residential' => [
                'display_name' => 'Residential',
                'base_rate' => 50.00,
                'base_cubic_meters' => 10,
                'tiers' => [
                    ['id' => 'r1', 'label' => 'Next 10 cu. m.', 'price' => 5.00, 'isFlat' => false],
                    ['id' => 'r2', 'label' => 'Next 10 cu. m.', 'price' => 6.00, 'isFlat' => false],
                    ['id' => 'r3', 'label' => 'Next 10 cu. m.', 'price' => 6.50, 'isFlat' => false],
                    ['id' => 'r4', 'label' => 'Next 10 cu. m.', 'price' => 7.00, 'isFlat' => false],
                    ['id' => 'r5', 'label' => 'Next 10 cu. m.', 'price' => 7.50, 'isFlat' => false],
                    ['id' => 'r6', 'label' => 'Next 10 cu. m.', 'price' => 8.00, 'isFlat' => false],
                    ['id' => 'r7', 'label' => 'Next 10 cu. m.', 'price' => 8.50, 'isFlat' => false],
                    ['id' => 'r8', 'label' => 'Next 10 cu. m.', 'price' => 9.00, 'isFlat' => false],
                    ['id' => 'r9', 'label' => 'Next 10 cu. m.', 'price' => 9.50, 'isFlat' => false],
                ],
                'excess_rate' => 12.00,
            ],
            'commercial' => [
                'display_name' => 'Commercial',
                'base_rate' => 100.00,
                'base_cubic_meters' => 10,
                'tiers' => [
                    ['id' => 'c1', 'label' => 'Next 10 cu. m.', 'price' => 6.00, 'isFlat' => false],
                    ['id' => 'c2', 'label' => 'Next 10 cu. m.', 'price' => 7.00, 'isFlat' => false],
                    ['id' => 'c3', 'label' => 'Next 10 cu. m.', 'price' => 8.00, 'isFlat' => false],
                    ['id' => 'c4', 'label' => 'Next 10 cu. m.', 'price' => 9.00, 'isFlat' => false],
                    ['id' => 'c5', 'label' => 'Next 10 cu. m.', 'price' => 10.00, 'isFlat' => false],
                    ['id' => 'c6', 'label' => 'Next 10 cu. m.', 'price' => 11.00, 'isFlat' => false],
                    ['id' => 'c7', 'label' => 'Next 10 cu. m.', 'price' => 12.00, 'isFlat' => false],
                    ['id' => 'c8', 'label' => 'Next 10 cu. m.', 'price' => 13.00, 'isFlat' => false],
                    ['id' => 'c9', 'label' => 'Next 10 cu. m.', 'price' => 13.50, 'isFlat' => false],
                ],
                'excess_rate' => 15.00,
            ],
            'industrial' => [
                'display_name' => 'Industrial',
                'base_rate' => 1000.00,
                'base_cubic_meters' => 100,
                'tiers' => [
                    ['id' => 'i1', 'label' => 'Next 50 cu. m.', 'price' => 12.00, 'isFlat' => false],
                    ['id' => 'i2', 'label' => 'Next 50 cu. m.', 'price' => 13.00, 'isFlat' => false],
                    ['id' => 'i3', 'label' => 'Next 50 cu. m.', 'price' => 14.00, 'isFlat' => false],
                    ['id' => 'i4', 'label' => 'Next 50 cu. m.', 'price' => 15.00, 'isFlat' => false],
                    ['id' => 'i5', 'label' => 'Next 50 cu. m.', 'price' => 16.00, 'isFlat' => false],
                    ['id' => 'i6', 'label' => 'Next 50 cu. m.', 'price' => 17.00, 'isFlat' => false],
                    ['id' => 'i7', 'label' => 'Next 50 cu. m.', 'price' => 18.00, 'isFlat' => false],
                    ['id' => 'i8', 'label' => 'Next 50 cu. m.', 'price' => 19.00, 'isFlat' => false],
                ],
                'excess_rate' => 20.00,
            ],
            'special_use' => [
                'display_name' => 'Special Use',
                'base_rate' => 0.00,
                'base_cubic_meters' => 0,
                'tiers' => [],
                'excess_rate' => 30.00,
            ],
        ];
    }
}

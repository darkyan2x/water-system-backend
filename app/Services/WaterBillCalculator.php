<?php

namespace App\Services;

use App\Models\Tariff;
use InvalidArgumentException;
use Throwable;

class WaterBillCalculator
{
    /**
     * Fallback values. These are the original fixed values and are kept as a safety net.
     * If the tariffs table is missing, empty, or an account type has no active tariff,
     * the calculator will still produce the correct old calculation.
     */
    private const DEFAULT_RATE_TABLES = [
        'commercial' => [
            'minimum' => [
                'up_to' => 10,
                'amount_cents' => 10000, // ₱100.00
                'label' => 'First 10 cu.m.',
            ],
            'tiers' => [
                ['after' => 10, 'up_to' => 20, 'rate_cents' => 600, 'label' => 'Next 10 cu.m.'],
                ['after' => 20, 'up_to' => 30, 'rate_cents' => 700, 'label' => 'Next 10 cu.m.'],
                ['after' => 30, 'up_to' => 40, 'rate_cents' => 800, 'label' => 'Next 10 cu.m.'],
                ['after' => 40, 'up_to' => 50, 'rate_cents' => 900, 'label' => 'Next 10 cu.m.'],
                ['after' => 50, 'up_to' => 60, 'rate_cents' => 1000, 'label' => 'Next 10 cu.m.'],
                ['after' => 60, 'up_to' => 70, 'rate_cents' => 1100, 'label' => 'Next 10 cu.m.'],
                ['after' => 70, 'up_to' => 80, 'rate_cents' => 1200, 'label' => 'Next 10 cu.m.'],
                ['after' => 80, 'up_to' => 90, 'rate_cents' => 1300, 'label' => 'Next 10 cu.m.'],
                ['after' => 90, 'up_to' => 100, 'rate_cents' => 1350, 'label' => 'Next 10 cu.m.'],
                ['after' => 100, 'up_to' => null, 'rate_cents' => 1500, 'label' => 'In excess of 100 cu.m.'],
            ],
        ],
        'residential' => [
            'minimum' => [
                'up_to' => 10,
                'amount_cents' => 5000, // ₱50.00
                'label' => 'First 10 cu.m.',
            ],
            'tiers' => [
                ['after' => 10, 'up_to' => 20, 'rate_cents' => 500, 'label' => 'Next 10 cu.m.'],
                ['after' => 20, 'up_to' => 30, 'rate_cents' => 600, 'label' => 'Next 10 cu.m.'],
                ['after' => 30, 'up_to' => 40, 'rate_cents' => 650, 'label' => 'Next 10 cu.m.'],
                ['after' => 40, 'up_to' => 50, 'rate_cents' => 700, 'label' => 'Next 10 cu.m.'],
                ['after' => 50, 'up_to' => 60, 'rate_cents' => 750, 'label' => 'Next 10 cu.m.'],
                ['after' => 60, 'up_to' => 70, 'rate_cents' => 800, 'label' => 'Next 10 cu.m.'],
                ['after' => 70, 'up_to' => 80, 'rate_cents' => 850, 'label' => 'Next 10 cu.m.'],
                ['after' => 80, 'up_to' => 90, 'rate_cents' => 900, 'label' => 'Next 10 cu.m.'],
                ['after' => 90, 'up_to' => 100, 'rate_cents' => 950, 'label' => 'Next 10 cu.m.'],
                ['after' => 100, 'up_to' => null, 'rate_cents' => 1200, 'label' => 'In excess of 100 cu.m.'],
            ],
        ],
        'industrial' => [
            'minimum' => [
                'up_to' => 100,
                'amount_cents' => 100000, // ₱1,000.00
                'label' => 'First 100 cu.m.',
            ],
            'tiers' => [
                ['after' => 100, 'up_to' => 150, 'rate_cents' => 1200, 'label' => 'Next 50 cu.m.'],
                ['after' => 150, 'up_to' => 200, 'rate_cents' => 1300, 'label' => 'Next 50 cu.m.'],
                ['after' => 200, 'up_to' => 250, 'rate_cents' => 1400, 'label' => 'Next 50 cu.m.'],
                ['after' => 250, 'up_to' => 300, 'rate_cents' => 1500, 'label' => 'Next 50 cu.m.'],
                ['after' => 300, 'up_to' => 350, 'rate_cents' => 1600, 'label' => 'Next 50 cu.m.'],
                ['after' => 350, 'up_to' => 400, 'rate_cents' => 1700, 'label' => 'Next 50 cu.m.'],
                ['after' => 400, 'up_to' => 450, 'rate_cents' => 1800, 'label' => 'Next 50 cu.m.'],
                ['after' => 450, 'up_to' => 500, 'rate_cents' => 1900, 'label' => 'Next 50 cu.m.'],
                ['after' => 500, 'up_to' => null, 'rate_cents' => 2000, 'label' => 'In excess of 500 cu.m.'],
            ],
        ],
        'special_use' => [
            'type' => 'flat',
            'rate_cents' => 3000, // ₱30.00 per cu.m.
            'label' => 'Flat rate of ₱30.00/cu.m.',
        ],
    ];

    /**
     * Calculates the water bill.
     *
     * Keep this signature unchanged so existing reading store code will continue to work:
     * calculate($accountType, $currentReading, $previousReading)
     */
    public function calculate(string $accountType, float $currentReading, float $previousReading): array
    {
        $accountType = $this->normalizeAccountType($accountType);

        if (!isset(self::DEFAULT_RATE_TABLES[$accountType])) {
            throw new InvalidArgumentException("Unsupported account type: {$accountType}");
        }

        if ($currentReading < $previousReading) {
            throw new InvalidArgumentException('Current reading cannot be lower than previous reading.');
        }

        $usage = $currentReading - $previousReading;
        $rateTable = $this->rateTableFor($accountType);

        /*
        * Special Use:
        * Flat rate per cu.m.
        */
        if (($rateTable['type'] ?? null) === 'flat') {
            $amountCents = (int) round($usage * $rateTable['rate_cents']);

            return [
                'account_type' => $accountType,
                'previous_reading' => $previousReading,
                'current_reading' => $currentReading,
                'usage' => $usage,
                'total_amount' => $amountCents / 100,
                'breakdown' => [
                    [
                        'description' => $rateTable['label'],
                        'cu_m' => $usage,
                        'rate' => $rateTable['rate_cents'] / 100,
                        'amount' => $amountCents / 100,
                    ],
                ],
            ];
        }

        /*
        * Tiered calculation for residential, commercial, and industrial.
        * This matches the original fixed WaterBillCalculator logic:
        * - Always charge the base/minimum amount once.
        * - Then charge excess consumption per configured tier.
        */
        $totalCents = 0;
        $breakdown = [];

        $minimum = $rateTable['minimum'];
        $minimumUsage = min($usage, $minimum['up_to']);

        $totalCents += (int) $minimum['amount_cents'];

        $breakdown[] = [
            'description' => $minimum['label'],
            'cu_m' => $minimumUsage,
            'rate' => 'Minimum',
            'amount' => $minimum['amount_cents'] / 100,
        ];

        foreach ($rateTable['tiers'] as $tier) {
            if ($usage <= $tier['after']) {
                continue;
            }

            $upperLimit = $tier['up_to'] ?? $usage;
            $billableCuM = min($usage, $upperLimit) - $tier['after'];

            if ($billableCuM <= 0) {
                continue;
            }

            $amountCents = (int) round($billableCuM * $tier['rate_cents']);
            $totalCents += $amountCents;

            $breakdown[] = [
                'description' => $tier['label'],
                'cu_m' => $billableCuM,
                'rate' => $tier['rate_cents'] / 100,
                'amount' => $amountCents / 100,
            ];
        }

        return [
            'account_type' => $accountType,
            'previous_reading' => $previousReading,
            'current_reading' => $currentReading,
            'usage' => $usage,
            'total_amount' => $totalCents / 100,
            'breakdown' => $breakdown,
        ];
    }

    private function rateTableFor(string $accountType): array
    {
        $tariff = $this->activeTariff($accountType);

        if (!$tariff) {
            return self::DEFAULT_RATE_TABLES[$accountType];
        }

        if ($accountType === 'special_use') {
            $rateCents = $this->pesoToCents($tariff->excess_rate);

            return [
                'type' => 'flat',
                'rate_cents' => $rateCents,
                'label' => 'Flat rate of ₱' . number_format($rateCents / 100, 2) . '/cu.m.',
            ];
        }

        $baseCbm = max(0, (int) $tariff->base_cubic_meters);
        $baseCents = $this->pesoToCents($tariff->base_rate);
        $tiers = $this->buildDynamicTiers($accountType, $baseCbm, $tariff->tiers ?? [], $tariff->excess_rate);

        return [
            'minimum' => [
                'up_to' => $baseCbm,
                'amount_cents' => $baseCents,
                'label' => 'First ' . $baseCbm . ' cu.m.',
            ],
            'tiers' => $tiers,
        ];
    }

    private function activeTariff(string $accountType): ?Tariff
    {
        try {
            return Tariff::query()
                ->where('account_type', $accountType)
                ->where('is_active', true)
                ->first();
        } catch (Throwable $e) {
            // Safety fallback: if the tariffs table/model is not ready yet,
            // keep the old fixed calculator values working.
            return null;
        }
    }

    private function buildDynamicTiers(string $accountType, int $baseCbm, array $incomingTiers, $excessRate): array
    {
        $tiers = [];
        $after = $baseCbm;
        $defaultStep = $accountType === 'industrial' ? 50 : 10;

        foreach (array_values($incomingTiers) as $index => $tier) {
            if (!is_array($tier)) {
                continue;
            }

            $label = trim((string) ($tier['label'] ?? ''));
            $step = $this->tierStepFromLabel($label, $defaultStep);
            $rateCents = $this->pesoToCents($tier['price'] ?? 0);

            if ($step <= 0) {
                $step = $defaultStep;
            }

            $upTo = $after + $step;

            $tiers[] = [
                'after' => $after,
                'up_to' => $upTo,
                'rate_cents' => $rateCents,
                'label' => $label !== '' ? $label : 'Next ' . $step . ' cu.m.',
            ];

            $after = $upTo;
        }

        $excessCents = $this->pesoToCents($excessRate);

        $tiers[] = [
            'after' => $after,
            'up_to' => null,
            'rate_cents' => $excessCents,
            'label' => 'In excess of ' . $after . ' cu.m.',
        ];

        return $tiers;
    }

    private function tierStepFromLabel(string $label, int $fallback): int
    {
        if (preg_match('/(\d+(?:\.\d+)?)/', $label, $matches)) {
            return (int) round((float) $matches[1]);
        }

        return $fallback;
    }

    private function pesoToCents($peso): int
    {
        return (int) round(((float) $peso) * 100);
    }

    private function normalizeAccountType(string $accountType): string
    {
        $type = strtolower(trim($accountType));
        $type = str_replace(['-', ' '], '_', $type);

        return match ($type) {
            'residential' => 'residential',
            'commercial' => 'commercial',
            'industrial' => 'industrial',
            'special_use' => 'special_use',
            default => $type,
        };
    }
}

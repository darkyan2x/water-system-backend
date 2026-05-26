<?php

namespace App\Services;

use InvalidArgumentException;

class WaterBillCalculator
{
    private const RATE_TABLES = [
        'commercial' => [
            'minimum' => [
                'up_to' => 10,
                'amount_cents' => 10000, // ₱100.00
                'label' => 'First 10 cu.m.',
            ],

            'tiers' => [
                [
                    'after' => 10,
                    'up_to' => 20,
                    'rate_cents' => 600, // ₱6.00
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 20,
                    'up_to' => 30,
                    'rate_cents' => 700, // ₱7.00
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 30,
                    'up_to' => 40,
                    'rate_cents' => 800, // ₱8.00
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 40,
                    'up_to' => 50,
                    'rate_cents' => 900, // ₱9.00
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 50,
                    'up_to' => 60,
                    'rate_cents' => 1000, // ₱10.00
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 60,
                    'up_to' => 70,
                    'rate_cents' => 1100, // ₱11.00
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 70,
                    'up_to' => 80,
                    'rate_cents' => 1200, // ₱12.00
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 80,
                    'up_to' => 90,
                    'rate_cents' => 1300, // ₱13.00
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 90,
                    'up_to' => 100,
                    'rate_cents' => 1350, // ₱13.50
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 100,
                    'up_to' => null,
                    'rate_cents' => 1500, // ₱15.00
                    'label' => 'In excess of 100 cu.m.',
                ],
            ],
        ],
        'residential' => [
            'minimum' => [
                'up_to' => 10,
                'amount_cents' => 5000, // ₱50.00
                'label' => 'First 10 cu.m.',
            ],

            'tiers' => [
                [
                    'after' => 10,
                    'up_to' => 20,
                    'rate_cents' => 500, // ₱5.00
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 20,
                    'up_to' => 30,
                    'rate_cents' => 600, // ₱6.00
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 30,
                    'up_to' => 40,
                    'rate_cents' => 650, // ₱6.50
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 40,
                    'up_to' => 50,
                    'rate_cents' => 700, // ₱7.00
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 50,
                    'up_to' => 60,
                    'rate_cents' => 750, // ₱7.50
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 60,
                    'up_to' => 70,
                    'rate_cents' => 800, // ₱8.00
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 70,
                    'up_to' => 80,
                    'rate_cents' => 850, // ₱8.50
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 80,
                    'up_to' => 90,
                    'rate_cents' => 900, // ₱9.00
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 90,
                    'up_to' => 100,
                    'rate_cents' => 950, // ₱9.50
                    'label' => 'Next 10 cu.m.',
                ],
                [
                    'after' => 100,
                    'up_to' => null,
                    'rate_cents' => 1200, // ₱12.00
                    'label' => 'In excess of 100 cu.m.',
                ],
            ],
        ],
        'industrial' => [
            'minimum' => [
                'up_to' => 100,
                'amount_cents' => 100000, // ₱1,000.00
                'label' => 'First 100 cu.m.',
            ],

            'tiers' => [
                [
                    'after' => 100,
                    'up_to' => 150,
                    'rate_cents' => 1200, // ₱12.00
                    'label' => 'Next 50 cu.m.',
                ],
                [
                    'after' => 150,
                    'up_to' => 200,
                    'rate_cents' => 1300, // ₱13.00
                    'label' => 'Next 50 cu.m.',
                ],
                [
                    'after' => 200,
                    'up_to' => 250,
                    'rate_cents' => 1400, // ₱14.00
                    'label' => 'Next 50 cu.m.',
                ],
                [
                    'after' => 250,
                    'up_to' => 300,
                    'rate_cents' => 1500, // ₱15.00
                    'label' => 'Next 50 cu.m.',
                ],
                [
                    'after' => 300,
                    'up_to' => 350,
                    'rate_cents' => 1600, // ₱16.00
                    'label' => 'Next 50 cu.m.',
                ],
                [
                    'after' => 350,
                    'up_to' => 400,
                    'rate_cents' => 1700, // ₱17.00
                    'label' => 'Next 50 cu.m.',
                ],
                [
                    'after' => 400,
                    'up_to' => 450,
                    'rate_cents' => 1800, // ₱18.00
                    'label' => 'Next 50 cu.m.',
                ],
                [
                    'after' => 450,
                    'up_to' => 500,
                    'rate_cents' => 1900, // ₱19.00
                    'label' => 'Next 50 cu.m.',
                ],
                [
                    'after' => 500,
                    'up_to' => null,
                    'rate_cents' => 2000, // ₱20.00
                    'label' => 'In excess of 500 cu.m.',
                ],
            ],
        ],
        'special_use' => [
            'type' => 'flat',
            'rate_cents' => 3000, // ₱30.00 per cu.m.
            'label' => 'Flat rate of ₱30.00/cu.m.',
        ],
    ];

    public function calculate(string $accountType, float $currentReading, float $previousReading): array
    {
        $accountType = strtolower($accountType);

        if (!isset(self::RATE_TABLES[$accountType])) {
            throw new InvalidArgumentException("Unsupported account type: {$accountType}");
        }

        if ($currentReading < $previousReading) {
            throw new InvalidArgumentException('Current reading cannot be lower than previous reading.');
        }

        $usage = $currentReading - $previousReading;

        $rateTable = self::RATE_TABLES[$accountType];

        /*
        * Special Use:
        * Flat rate per cu.m.
        */
        if (($rateTable['type'] ?? null) === 'flat') {
            $amountCents = round($usage * $rateTable['rate_cents']);

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
        * Existing tiered calculation for:
        * residential, commercial, industrial
        */
        $totalCents = 0;
        $breakdown = [];

        $minimum = $rateTable['minimum'];

        $minimumUsage = min($usage, $minimum['up_to']);

        $totalCents += $minimum['amount_cents'];

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

            $amountCents = round($billableCuM * $tier['rate_cents']);

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
}
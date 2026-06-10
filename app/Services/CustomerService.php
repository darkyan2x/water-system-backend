<?php

namespace App\Services;

use App\Models\Reading;
use App\Models\User;
use Illuminate\Support\Carbon;


class CustomerService
{

    public function getCycleDateForMonth(Carbon $month, int $billingDay): Carbon
    {
        $billingDay = max(1, min($billingDay, 31));

        $daysInMonth = $month->copy()->endOfMonth()->day;

        $actualDay = min($billingDay, $daysInMonth);

        return $month->copy()
            ->startOfMonth()
            ->day($actualDay)
            ->startOfDay();
    }

    public function getFirstNextReadingDate(Carbon $createdAt, int $billingDay): Carbon
    {
        $nextMonth = $createdAt->copy()->addMonthNoOverflow();

        return $this->getCycleDateForMonth($nextMonth, $billingDay);
    }

    public function getNextReadingDateAfterCycle(Carbon $currentCycleDate, int $billingDay): Carbon
    {
        $nextMonth = $currentCycleDate->copy()->addMonthNoOverflow();

        return $this->getCycleDateForMonth($nextMonth, $billingDay);
    }
}
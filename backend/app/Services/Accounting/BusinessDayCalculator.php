<?php

namespace App\Services\Accounting;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class BusinessDayCalculator
{
    /**
     * Add N business days (Mon–Fri). Statutory holidays can be layered later.
     */
    public function addBusinessDays(CarbonInterface|string $from, int $days): Carbon
    {
        $date = Carbon::parse($from)->startOfDay();
        $remaining = max(0, $days);

        while ($remaining > 0) {
            $date->addDay();
            if ($date->isWeekday()) {
                $remaining--;
            }
        }

        return $date;
    }
}

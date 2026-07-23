<?php

namespace App\Console\Commands;

use App\Services\Booking\BookingService;
use Illuminate\Console\Command;

class ReleaseExpiredBookingHoldsCommand extends Command
{
    protected $signature = 'booking:release-expired-holds';

    protected $description = 'Expire soft booking holds past their TTL and free slot claims';

    public function handle(BookingService $bookings): int
    {
        $count = $bookings->releaseExpiredHolds();
        $this->info("Released {$count} expired booking hold(s).");

        return self::SUCCESS;
    }
}

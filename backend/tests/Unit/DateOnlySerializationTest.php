<?php

namespace Tests\Unit;

use App\Casts\DateOnly;
use App\Models\Job;
use App\Services\SmsMessageTemplates;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class DateOnlySerializationTest extends TestCase
{
    public function test_date_only_cast_serializes_as_ymd(): void
    {
        $cast = new DateOnly;
        $carbon = Carbon::parse('2026-07-13')->startOfDay();

        $serialized = $cast->serialize(new Job, 'scheduled_start_date', $carbon, []);

        $this->assertSame('2026-07-13', $serialized);
    }

    public function test_sms_format_date_keeps_calendar_day_from_iso_utc_midnight(): void
    {
        $formatted = SmsMessageTemplates::formatDate('2026-07-13T00:00:00.000000Z');

        $this->assertSame('Jul 13, 2026', $formatted);
    }

    public function test_sms_format_date_accepts_carbon_instance(): void
    {
        $formatted = SmsMessageTemplates::formatDate(Carbon::parse('2026-08-05')->startOfDay());

        $this->assertSame('Aug 5, 2026', $formatted);
    }
}

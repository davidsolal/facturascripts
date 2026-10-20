<?php

use FacturaScripts\Plugins\ZasadyBilling\Lib\TargetCalendar;
use PHPUnit\Framework\TestCase;

/**
 * The cron only warns about a "rate from the previous day" on days when the ECB
 * is expected to publish. Getting this calendar wrong either hides a real gap
 * (a business day invoiced with a stale rate) or cries wolf every weekend.
 */
final class TargetCalendarTest extends TestCase
{
    public function testWeekendsAreNotBusinessDays(): void
    {
        // 2026-09-19 is a Saturday, 2026-09-20 a Sunday.
        $this->assertFalse(TargetCalendar::isBusinessDay('2026-09-19'));
        $this->assertFalse(TargetCalendar::isBusinessDay('2026-09-20'));
        $this->assertTrue(TargetCalendar::isBusinessDay('2026-09-18'));
    }

    public function testFixedClosingDays(): void
    {
        // 2027: 1 Jan is a Friday, 1 May a Saturday, 25 Dec a Saturday,
        // 26 Dec a Sunday. Use 2024 for weekday-aligned checks instead.
        $this->assertFalse(TargetCalendar::isBusinessDay('2024-01-01'));
        $this->assertFalse(TargetCalendar::isBusinessDay('2024-05-01'));
        $this->assertFalse(TargetCalendar::isBusinessDay('2024-12-25'));
        $this->assertFalse(TargetCalendar::isBusinessDay('2024-12-26'));
        // A plain midweek day stays a business day.
        $this->assertTrue(TargetCalendar::isBusinessDay('2024-05-02'));
    }

    public function testEasterSundayIsComputedCorrectly(): void
    {
        $this->assertSame('2024-03-31', TargetCalendar::easterSunday(2024)->format('Y-m-d'));
        $this->assertSame('2025-04-20', TargetCalendar::easterSunday(2025)->format('Y-m-d'));
        $this->assertSame('2026-04-05', TargetCalendar::easterSunday(2026)->format('Y-m-d'));
        $this->assertSame('2027-03-28', TargetCalendar::easterSunday(2027)->format('Y-m-d'));
    }

    public function testGoodFridayAndEasterMondayAreClosingDays(): void
    {
        // Easter Sunday 2026-04-05: Good Friday the 3rd, Easter Monday the 6th.
        $this->assertFalse(TargetCalendar::isBusinessDay('2026-04-03'));
        $this->assertFalse(TargetCalendar::isBusinessDay('2026-04-06'));
        // The Thursday before and the Tuesday after are normal business days.
        $this->assertTrue(TargetCalendar::isBusinessDay('2026-04-02'));
        $this->assertTrue(TargetCalendar::isBusinessDay('2026-04-07'));
    }
}

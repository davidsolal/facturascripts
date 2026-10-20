<?php

use FacturaScripts\Plugins\ZasadyBilling\Lib\Period;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    public function testKeyIsPerCompanyAndMonth(): void
    {
        $this->assertSame('zasady-2-issued-2026-09', Period::key(2, '2026-09-19'));
        $this->assertNotSame(Period::key(2, '2026-09-19'), Period::key(3, '2026-09-19'));
        $this->assertNotSame(Period::key(2, '2026-09-19'), Period::key(2, '2026-10-19'));
    }

    public function testSettingsKeyIsTheSharedSourceOfTruth(): void
    {
        // isIssued()/markIssued() both derive from settingsKey(); key()
        // composes it with Profile::group() for the human-readable id.
        $this->assertSame('issued-2026-09', Period::settingsKey('2026-09-19'));
        $this->assertSame(
            'zasady-2-' . Period::settingsKey('2026-09-19'),
            Period::key(2, '2026-09-19')
        );
    }

    public function testDueDateIsMonthEnd(): void
    {
        $this->assertSame('2026-09-30', Period::dueDate('2026-09-19'));
        $this->assertSame('2026-02-28', Period::dueDate('2026-02-19'));
        $this->assertSame('2028-02-29', Period::dueDate('2028-02-19'));
    }

    public function testInvoiceDateClampsBillingDayToMonthLength(): void
    {
        // The 19th (the default billing day) exists in every month.
        $this->assertSame('2026-01-19', Period::invoiceDate(2026, 1, 19));
        // Day 31 doesn't exist in February; clamp down to the last day.
        $this->assertSame('2026-02-28', Period::invoiceDate(2026, 2, 31));
        // 2028 is a leap year: February has 29 days.
        $this->assertSame('2028-02-29', Period::invoiceDate(2028, 2, 31));
    }

    public function testMonthRangeCoversTheWholePeriodMonth(): void
    {
        // The idempotency guard looks for an invoice anywhere in the month, so
        // a run on the 20th still sees the invoice issued on the 19th.
        $this->assertSame('2026-09-01', Period::monthStart('2026-09-19'));
        $this->assertSame('2026-09-30', Period::monthEnd('2026-09-19'));
        $this->assertSame('2026-02-28', Period::monthEnd('2026-02-19'));
        $this->assertSame('2028-02-29', Period::monthEnd('2028-02-19'));
    }

    public function testTodayIsResolvedInTheBillingTimezone(): void
    {
        // The container clock is UTC; the operator's day is Europe/Madrid. A
        // UTC "today" made the cron miss its billing day around midnight.
        $this->assertSame('Europe/Madrid', Period::today()->getTimezone()->getName());
        $this->assertSame('00:00:00', Period::today()->format('H:i:s'));
    }
}

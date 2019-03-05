<?php

declare(strict_types=1);

namespace App\Tests\AutoReview;

use PHPUnit\Framework\TestCase;

class UtcTimeZoneTest extends TestCase
{
    public function test_the_system_uses_utc_timezone(): void
    {
        self::assertSame('UTC', date_default_timezone_get());
    }
}

<?php
declare(strict_types=1);

namespace X68000\Printer\Tests;

use PHPUnit\Framework\TestCase;
use X68000\Printer\DecodeResult;

final class DecodeResultTest extends TestCase
{
    public function testReportsWhetherWarningsExist(): void
    {
        self::assertFalse((new DecodeResult('decoded'))->hasWarnings());
        self::assertTrue((new DecodeResult('decoded', 1))->hasWarnings());
        self::assertTrue((new DecodeResult('decoded', 0, 1))->hasWarnings());
    }
}

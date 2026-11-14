<?php

namespace Tests\Unit;

use App\Domain\Series\DocumentNumber;
use App\Domain\Series\SeriesCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class DocumentNumberTest extends TestCase
{
    public function test_formats_eight_digits_and_preserves_code(): void
    {
        $this->assertSame('F001-00000001', (string) new DocumentNumber('01', 'F001', 1));
        $this->assertSame('BC01-99999999', (string) new DocumentNumber('07', 'BC01', 99999999));
        $this->assertSame('FC01', SeriesCode::normalize(' fc01 '));
    }

    #[TestWith(['01', 'F001', 0])]
    #[TestWith(['01', 'F001', -1])]
    #[TestWith(['01', 'F001', 100000000])]
    #[TestWith(['01', 'B001', 1])]
    #[TestWith(['03', 'F001', 1])]
    #[TestWith(['07', 'A001', 1])]
    #[TestWith(['01', 'f001', 1])]
    #[TestWith(['01', 'F-01', 1])]
    public function test_rejects_invalid_number_or_series(string $type, string $code, int $number): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DocumentNumber($type, $code, $number);
    }
}

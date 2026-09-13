<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Math;

use App\Shared\Math\Statistics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Statistics::class)]
final class StatisticsTest extends TestCase
{
    public function testMedianOfAnEmptyListIsNull(): void
    {
        self::assertNull(Statistics::median([]));
    }

    /**
     * @param list<float> $values
     */
    #[DataProvider('medianCases')]
    public function testMedian(float $expected, array $values): void
    {
        self::assertSame($expected, Statistics::median($values));
    }

    /**
     * @return iterable<string, array{float, list<float>}>
     */
    public static function medianCases(): iterable
    {
        yield 'single value' => [5.0, [5.0]];
        yield 'odd count' => [3.0, [1.0, 3.0, 9.0]];
        yield 'even count averages the middle pair' => [2.5, [1.0, 2.0, 3.0, 4.0]];
        yield 'unsorted input' => [3.0, [9.0, 1.0, 3.0]];
    }

    public function testPercentileOfAnEmptyListIsNull(): void
    {
        self::assertNull(Statistics::percentile([], 0.9));
    }

    public function testPercentileInterpolatesLinearly(): void
    {
        // Nearest-rank interpolation over [1, 2, 3, 4]: p50 sits between 2 and 3.
        self::assertSame(2.5, Statistics::percentile([1.0, 2.0, 3.0, 4.0], 0.5));
        self::assertSame(1.0, Statistics::percentile([1.0, 2.0, 3.0, 4.0], 0.0));
        self::assertSame(4.0, Statistics::percentile([1.0, 2.0, 3.0, 4.0], 1.0));
    }

    public function testPercentileRejectsARatioOutsideTheUnitInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Statistics::percentile([1.0], 1.5);
    }

    public function testClampKeepsValuesInsideTheUnitInterval(): void
    {
        self::assertSame(0.0, Statistics::clampUnit(-2.0));
        self::assertSame(1.0, Statistics::clampUnit(7.0));
        self::assertSame(0.3, Statistics::clampUnit(0.3));
    }
}

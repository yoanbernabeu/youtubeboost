<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scoring\Model;

use App\Scoring\Model\Signal;
use App\Scoring\Model\Weights;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Weights::class)]
final class WeightsTest extends TestCase
{
    public function testDefaultsMatchTheProductSpecification(): void
    {
        $weights = Weights::defaults();

        self::assertSame(30.0, $weights->get(Signal::Decline));
        self::assertSame(30.0, $weights->get(Signal::LowCtr));
        self::assertSame(15.0, $weights->get(Signal::Impressions));
        self::assertSame(25.0, $weights->get(Signal::Potential));
    }

    public function testItIsBuiltFromAPartialMapAndFallsBackOnDefaults(): void
    {
        $weights = Weights::fromArray(['decline' => 50.0]);

        self::assertSame(50.0, $weights->get(Signal::Decline));
        self::assertSame(30.0, $weights->get(Signal::LowCtr));
    }

    public function testItIgnoresUnknownKeys(): void
    {
        $weights = Weights::fromArray(['decline' => 10.0, 'unknown' => 99.0]);

        self::assertSame(['decline' => 10.0, 'low_ctr' => 30.0, 'impressions' => 15.0, 'potential' => 25.0], $weights->toArray());
    }

    public function testItAcceptsIntegerAndNumericStringValues(): void
    {
        $weights = Weights::fromArray(['decline' => 10, 'low_ctr' => '20']);

        self::assertSame(10.0, $weights->get(Signal::Decline));
        self::assertSame(20.0, $weights->get(Signal::LowCtr));
    }

    public function testItRejectsNegativeWeights(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Weights::fromArray(['decline' => -1.0]);
    }

    public function testItRejectsAnAllZeroSet(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Weights::fromArray(['decline' => 0, 'low_ctr' => 0, 'impressions' => 0, 'potential' => 0]);
    }

    public function testItExposesTheTotalWeight(): void
    {
        self::assertSame(100.0, Weights::defaults()->total());
    }
}

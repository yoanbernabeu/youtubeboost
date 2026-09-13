<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scoring\Model;

use App\Scoring\Model\Signal;
use App\Scoring\Model\SignalSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignalSet::class)]
final class SignalSetTest extends TestCase
{
    public function testAnEmptySetReportsEverySignalAsMissing(): void
    {
        $set = SignalSet::empty();

        foreach (Signal::cases() as $signal) {
            self::assertNull($set->get($signal));
            self::assertFalse($set->has($signal));
        }
    }

    public function testItStoresValuesImmutably(): void
    {
        $set = SignalSet::empty();
        $other = $set->with(Signal::Decline, 0.5);

        self::assertNull($set->get(Signal::Decline));
        self::assertSame(0.5, $other->get(Signal::Decline));
    }

    public function testItClampsValuesToTheUnitInterval(): void
    {
        $set = SignalSet::empty()
            ->with(Signal::Decline, 1.8)
            ->with(Signal::LowCtr, -0.4);

        self::assertSame(1.0, $set->get(Signal::Decline));
        self::assertSame(0.0, $set->get(Signal::LowCtr));
    }

    public function testItRoundTripsThroughAnArray(): void
    {
        $set = SignalSet::fromArray(['decline' => 0.25, 'potential' => null]);

        self::assertSame(0.25, $set->get(Signal::Decline));
        self::assertNull($set->get(Signal::Potential));
        self::assertSame(
            ['decline' => 0.25, 'low_ctr' => null, 'impressions' => null, 'potential' => null],
            $set->toArray(),
        );
    }

    public function testItIgnoresUnknownKeysWhenHydrating(): void
    {
        $set = SignalSet::fromArray(['nope' => 0.5]);

        self::assertSame(SignalSet::empty()->toArray(), $set->toArray());
    }
}

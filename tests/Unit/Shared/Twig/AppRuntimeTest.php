<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Twig;

use App\Scoring\Model\Signal;
use App\Settings\Settings;
use App\Settings\Store\InMemorySettingStore;
use App\Shared\Twig\AppRuntime;
use App\YouTube\Repository\ChannelRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * The filters the whole interface formats through.
 *
 * Numbers, percentages and ages are the part of the translation that no catalogue
 * can carry, so they are checked in both languages: the thousands separator, the
 * decimal comma and the space before the percent sign all move from one to the other.
 */
#[CoversClass(AppRuntime::class)]
final class AppRuntimeTest extends TestCase
{
    /**
     * The narrow no-break space ICU groups French thousands with.
     */
    private const string NARROW_NBSP = "\u{202F}";

    /**
     * The no-break space ICU puts before a French percent sign.
     */
    private const string NBSP = "\u{00A0}";

    public function testEnglishNumbersAreGroupedWithCommas(): void
    {
        $runtime = self::runtime('en');

        self::assertSame('12,345', $runtime->formatNumber(12345));
        self::assertSame('1.6', $runtime->formatDecimal(1.55));
        self::assertSame('4.20%', $runtime->formatPercent(4.2, 2));
        self::assertSame('+12%', $runtime->formatVariation(12.0));
        self::assertSame('-8%', $runtime->formatVariation(-8.0));
    }

    public function testFrenchNumbersUseACommaAndASpace(): void
    {
        $runtime = self::runtime('fr');

        self::assertSame('12' . self::NARROW_NBSP . '345', $runtime->formatNumber(12345));
        self::assertSame('1,6', $runtime->formatDecimal(1.55));
        self::assertSame('4,20' . self::NBSP . '%', $runtime->formatPercent(4.2, 2));
        self::assertSame('+12' . self::NBSP . '%', $runtime->formatVariation(12.0));
    }

    public function testAMissingValueIsShownAsADash(): void
    {
        $runtime = self::runtime('en');

        self::assertSame('—', $runtime->formatNumber(null));
        self::assertSame('—', $runtime->formatDecimal(null));
        self::assertSame('—', $runtime->formatPercent(null));
        self::assertSame('—', $runtime->formatVariation(null));
        self::assertSame('—', $runtime->formatDuration(null));
        self::assertSame('—', $runtime->formatAge(null));
        self::assertSame('—', $runtime->formatByteSize(null));
    }

    /**
     * A duration reads the same everywhere: it is digits and colons.
     */
    public function testTheDurationIsTheYouTubeBadge(): void
    {
        self::assertSame('15:33', self::runtime('fr')->formatDuration(933));
        self::assertSame('1:02:03', self::runtime('en')->formatDuration(3723));
    }

    public function testTheScoreToneFollowsTheBands(): void
    {
        $runtime = self::runtime('en');

        self::assertSame('hot', $runtime->scoreTone(70));
        self::assertSame('warm', $runtime->scoreTone(45));
        self::assertSame('muted', $runtime->scoreTone(20));
        self::assertSame('cold', $runtime->scoreTone(19));
    }

    #[DataProvider('ages')]
    public function testTheAgeIsTheBareDurationTheTemplatesPutInTheirOwnSentence(string $locale, int $days, string $expected): void
    {
        $runtime = self::translatedRuntime($locale);

        $date = new \DateTimeImmutable(\sprintf('-%d days -1 hour', $days));

        self::assertSame($expected, $runtime->formatAge($date));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function ages(): iterable
    {
        yield 'today in English' => ['en', 0, 'today'];
        yield 'yesterday in English' => ['en', 1, 'yesterday'];
        yield 'days in English' => ['en', 12, '12 days'];
        yield 'months in English' => ['en', 90, '3 months'];
        yield 'a single month in English' => ['en', 31, '1 month'];

        yield "aujourd'hui en français" => ['fr', 0, "aujourd'hui"];
        yield 'hier en français' => ['fr', 1, 'hier'];
        yield 'jours en français' => ['fr', 12, '12 jours'];
        yield 'mois en français' => ['fr', 90, '3 mois'];
    }

    public function testAYearOldVideoIsCountedInYears(): void
    {
        $date = new \DateTimeImmutable('-800 days -1 hour');

        self::assertSame('2.2 years', self::translatedRuntime('en')->formatAge($date));
        self::assertSame('2,2 ans', self::translatedRuntime('fr')->formatAge($date));
    }

    public function testTheFrenchSingularCoversEverythingBelowTwoYears(): void
    {
        $date = new \DateTimeImmutable('-500 days -1 hour');

        self::assertSame('1,4 an', self::translatedRuntime('fr')->formatAge($date));
        self::assertSame('1.4 years', self::translatedRuntime('en')->formatAge($date));
    }

    public function testAFileWeightUsesTheUnitsOfTheLanguage(): void
    {
        self::assertSame('900 B', self::translatedRuntime('en')->formatByteSize(900));
        self::assertSame('900 o', self::translatedRuntime('fr')->formatByteSize(900));
        self::assertSame('12 KB', self::translatedRuntime('en')->formatByteSize(12 * 1024));
        self::assertSame('1.5 MB', self::translatedRuntime('en')->formatByteSize((int) (1.5 * 1024 * 1024)));
        self::assertSame('1,5 Mo', self::translatedRuntime('fr')->formatByteSize((int) (1.5 * 1024 * 1024)));
    }

    public function testTheSignalsAreNamedAndExplainedInTheLanguageOfTheInterface(): void
    {
        self::assertSame('Decline', self::translatedRuntime('en')->signalLabel(Signal::Decline));
        self::assertSame('Déclin', self::translatedRuntime('fr')->signalLabel('decline'));
        self::assertNotSame(
            '',
            self::translatedRuntime('fr')->signalExplanation(Signal::LowCtr),
        );
    }

    /**
     * An unknown name must not blow up a page that is only drawing a legend.
     */
    public function testAnUnknownSignalNameFallsBackToTheStructuralOne(): void
    {
        $runtime = self::translatedRuntime('en');

        self::assertSame($runtime->signalLabel(Signal::Decline), $runtime->signalLabel('nonsense'));
    }

    /**
     * Reads the real catalogue, so the wording under test is the shipped one.
     */
    private static function translatedRuntime(string $locale): AppRuntime
    {
        $catalogue = \dirname(__DIR__, 4) . '/translations/messages.' . $locale . '.yaml';
        if (!is_file($catalogue)) {
            self::markTestSkipped(\sprintf('The %s catalogue is missing.', $locale));
        }

        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $catalogue, $locale);

        if (!$translator->getCatalogue($locale)->has('shared.age.today')) {
            self::markTestSkipped(\sprintf('The shared keys are not merged into the %s catalogue yet.', $locale));
        }

        return self::runtime($locale, $translator);
    }

    private static function runtime(string $locale, ?Translator $translator = null): AppRuntime
    {
        if (null === $translator) {
            $translator = new Translator($locale);
        }

        return new AppRuntime(
            // Never called here: the formatting filters do not look at the channel.
            new \ReflectionClass(ChannelRepository::class)->newInstanceWithoutConstructor(),
            new Settings(new InMemorySettingStore(), 'gemini-3.1-flash-lite', 'gemini-3.1-flash-image'),
            $translator,
        );
    }
}

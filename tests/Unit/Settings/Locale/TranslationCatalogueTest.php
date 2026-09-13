<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settings\Locale;

use App\Settings\Locale\AppLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps the two languages honest.
 *
 * A key present on one side only does not break anything visibly: the fallback
 * quietly serves English to someone who asked for French. This is the only place
 * that notices.
 */
final class TranslationCatalogueTest extends TestCase
{
    private const string DIRECTORY = __DIR__ . '/../../../../translations';

    /**
     * Every domain shipped in `translations/`, discovered rather than listed, so
     * that a new one is covered the day it is added.
     *
     * @return iterable<string, array{string}>
     */
    public static function domains(): iterable
    {
        $files = glob(self::DIRECTORY . '/*.*.yaml');
        if (false === $files) {
            $files = [];
        }

        $domains = [];
        foreach ($files as $file) {
            $domains[] = explode('.', basename($file))[0];
        }

        $domains = array_unique($domains);
        sort($domains);

        foreach ($domains as $domain) {
            yield $domain => [$domain];
        }
    }

    /**
     * The interface catalogue is the one domain the application cannot do without.
     */
    public function testTheInterfaceCatalogueIsShipped(): void
    {
        $names = array_map(
            static fn (array $arguments): string => $arguments[0],
            iterator_to_array(self::domains(), false),
        );

        self::assertContains('messages', $names);
    }

    #[DataProvider('domains')]
    public function testEveryDomainCoversEveryShippedLanguage(string $domain): void
    {
        foreach (AppLocale::cases() as $locale) {
            self::assertFileExists(
                self::path($domain, $locale),
                \sprintf('The "%s" domain has no catalogue for %s.', $domain, $locale->value),
            );
        }
    }

    #[DataProvider('domains')]
    public function testBothLanguagesDefineTheSameKeys(string $domain): void
    {
        $english = self::keys($domain, AppLocale::English);
        $french = self::keys($domain, AppLocale::French);

        self::assertSame(
            [],
            array_values(array_diff($english, $french)),
            \sprintf('These "%s" keys are missing from the French catalogue.', $domain),
        );
        self::assertSame(
            [],
            array_values(array_diff($french, $english)),
            \sprintf('These "%s" keys are missing from the English catalogue.', $domain),
        );
    }

    #[DataProvider('domains')]
    public function testNoTranslationIsLeftEmpty(string $domain): void
    {
        foreach (AppLocale::cases() as $locale) {
            foreach (self::flatten(self::load($domain, $locale)) as $key => $value) {
                self::assertNotSame(
                    '',
                    trim($value),
                    \sprintf('"%s" is empty in %s/%s.', $key, $domain, $locale->value),
                );
            }
        }
    }

    private static function path(string $domain, AppLocale $locale): string
    {
        return \sprintf('%s/%s.%s.yaml', self::DIRECTORY, $domain, $locale->value);
    }

    /**
     * @return list<string>
     */
    private static function keys(string $domain, AppLocale $locale): array
    {
        $keys = array_keys(self::flatten(self::load($domain, $locale)));
        sort($keys);

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(string $domain, AppLocale $locale): array
    {
        $parsed = Yaml::parseFile(self::path($domain, $locale));

        return \is_array($parsed) ? $parsed : [];
    }

    /**
     * A leaf that is not a scalar becomes an empty string, which
     * {@see testNoTranslationIsLeftEmpty} then reports: a null or a stray list in
     * a catalogue is a mistake, not a translation.
     *
     * @param array<array-key, mixed> $tree
     *
     * @return array<string, string>
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
            if (\is_array($value)) {
                $flat = [...$flat, ...self::flatten($value, $path)];
                continue;
            }
            $flat[$path] = \is_scalar($value) ? (string) $value : '';
        }

        return $flat;
    }
}

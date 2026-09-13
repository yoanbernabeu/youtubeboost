<?php

declare(strict_types=1);

namespace App\Settings\Locale;

/**
 * The language the generated content is written in.
 *
 * The instructions sent to Gemini stay in English, because that is what the models
 * follow most reliably, but the words meant for the audience — the text burnt into
 * a thumbnail — are written in the language of the application. Prompts are built
 * in a worker, with no request to read the locale from, so the answer comes from
 * the setting.
 */
final class ContentLanguage
{
    public function __construct(
        private readonly LocaleResolver $locales,
    ) {
    }

    /**
     * The English name of the language, e.g. "French", which is how a model is
     * told which language to write in.
     */
    public function englishName(): string
    {
        return $this->locales->current()->englishName();
    }

    public function locale(): AppLocale
    {
        return $this->locales->current();
    }
}

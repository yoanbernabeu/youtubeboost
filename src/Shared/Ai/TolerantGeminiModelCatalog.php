<?php

declare(strict_types=1);

namespace App\Shared\Ai;

use Symfony\AI\Platform\Bridge\Gemini\Gemini;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Lets the creator name a Gemini model the installed library has not heard of yet.
 *
 * The bridge ships a hardcoded catalogue, and `Provider::supports()` reads a model
 * absent from it as "this platform cannot serve that", which surfaces as
 * `No provider found for model "…"`. Google releases models faster than the
 * library tags versions, so a perfectly real model — `gemini-3.8-flash` was one —
 * is refused before a single request leaves the machine.
 *
 * An unknown `gemini-…` name is therefore assumed to exist and sent off. If it
 * really does not, Google answers with its own error naming the model, which is a
 * better answer than a local catalogue miss: it is the authority on the question.
 */
#[AsDecorator('ai.platform.model_catalog.gemini')]
final class TolerantGeminiModelCatalog extends AbstractModelCatalog
{
    /**
     * What a current Gemini model can do. Capabilities only gate library features
     * this application does not use — it passes its own options to
     * `generateContent` — so the generous set costs nothing and avoids a refusal
     * based on a guess about a model we deliberately know nothing about.
     *
     * @var list<Capability>
     */
    private const array ASSUMED_CAPABILITIES = [
        Capability::INPUT_MESSAGES,
        Capability::INPUT_IMAGE,
        Capability::INPUT_PDF,
        Capability::INPUT_AUDIO,
        Capability::OUTPUT_TEXT,
        Capability::OUTPUT_STREAMING,
        Capability::OUTPUT_STRUCTURED,
        Capability::TOOL_CALLING,
        Capability::THINKING,
    ];

    public function __construct(ModelCatalogInterface $catalogued)
    {
        $this->models = self::classStrings($catalogued->getModels());
    }

    public function getModel(string $modelName): Model
    {
        $name = explode('?', $modelName, 2)[0];

        if ('' !== $name && !isset($this->models[$name]) && str_starts_with($name, 'gemini-')) {
            $this->models[$name] = [
                'class' => Gemini::class,
                'capabilities' => self::assumedCapabilitiesFor($name),
            ];
        }

        return parent::getModel($modelName);
    }

    /**
     * @return list<Capability>
     */
    private static function assumedCapabilitiesFor(string $name): array
    {
        if (!str_contains($name, '-image')) {
            return self::ASSUMED_CAPABILITIES;
        }

        return [...self::ASSUMED_CAPABILITIES, Capability::OUTPUT_IMAGE];
    }

    /**
     * The catalogue interface only promises a `string` class name; the parent holds
     * itself to `class-string`, so an entry naming nothing loadable is dropped here
     * rather than surfacing later as a type error.
     *
     * @param array<string, array{class: string, capabilities: list<Capability>}> $models
     *
     * @return array<string, array{class: class-string, capabilities: list<Capability>}>
     */
    private static function classStrings(array $models): array
    {
        $typed = [];

        foreach ($models as $name => $model) {
            if (class_exists($model['class'])) {
                $typed[$name] = ['class' => $model['class'], 'capabilities' => $model['capabilities']];
            }
        }

        return $typed;
    }
}

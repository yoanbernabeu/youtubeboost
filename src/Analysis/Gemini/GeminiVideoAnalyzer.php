<?php

declare(strict_types=1);

namespace App\Analysis\Gemini;

use App\Analysis\Exception\AnalysisFailedException;
use App\Analysis\Model\AnalysisPlan;
use App\Analysis\Model\ThumbnailAngle;
use App\Analysis\Model\VideoBrief;
use App\Analysis\VideoAnalyzerInterface;
use App\Settings\Settings;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads a video with Gemini and asks for five thumbnail angles.
 *
 * The schema is sent straight through as `generationConfig`, which is what the
 * Gemini bridge does with any option it does not recognise. That keeps the JSON
 * contract explicit and under this class's control.
 */
#[AsAlias(VideoAnalyzerInterface::class)]
final class GeminiVideoAnalyzer implements VideoAnalyzerInterface
{
    /** High enough for five genuinely different ideas, low enough to stay on brief. */
    private const float TEMPERATURE = 0.9;

    public function __construct(
        #[Autowire(service: 'ai.platform.gemini')]
        private readonly PlatformInterface $platform,
        private readonly AnalysisPromptBuilder $prompts,
        private readonly Settings $settings,
    ) {
    }

    public function analyze(VideoBrief $brief): AnalysisPlan
    {
        $model = $this->settings->getTextModel();

        $messages = new MessageBag(
            Message::forSystem($this->prompts->systemPrompt()),
            Message::ofUser($this->prompts->userPrompt($brief)),
        );

        try {
            $raw = $this->platform->invoke($model, $messages, [
                'temperature' => self::TEMPERATURE,
                'responseMimeType' => 'application/json',
                'responseJsonSchema' => $this->prompts->responseSchema(),
            ])->asText();
        } catch (\Throwable $exception) {
            throw AnalysisFailedException::modelUnreachable($exception);
        }

        return $this->toPlan($raw, $model);
    }

    private function toPlan(string $raw, string $model): AnalysisPlan
    {
        $data = self::decode($raw);

        $angles = [];
        $rawAngles = $data['angles'] ?? [];
        if (\is_array($rawAngles)) {
            foreach (array_values($rawAngles) as $index => $angle) {
                if (\is_array($angle)) {
                    $angles[] = ThumbnailAngle::fromArray($index, $angle);
                }
            }
        }

        $angles = array_values(array_filter($angles, static fn (ThumbnailAngle $angle): bool => '' !== $angle->imagePrompt));
        if ([] === $angles) {
            throw AnalysisFailedException::noUsableAngle();
        }

        return new AnalysisPlan(
            self::stringOf($data, 'summary'),
            self::stringOf($data, 'promise'),
            $angles,
            $model,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $raw): array
    {
        // Models occasionally wrap JSON in a markdown fence despite the mime type.
        $trimmed = trim($raw);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = trim(preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $trimmed) ?? $trimmed);
        }

        try {
            $data = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw AnalysisFailedException::unreadableAnswer($exception);
        }

        if (!\is_array($data)) {
            throw AnalysisFailedException::unreadableAnswer();
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function stringOf(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return \is_scalar($value) ? trim((string) $value) : '';
    }
}

<?php

declare(strict_types=1);

namespace App\Analysis\Gemini;

use App\Settings\Entity\ReferenceAngle;

/**
 * The JSON schema the language model must answer with.
 *
 * Declared by hand rather than derived from a class: the constraints that matter
 * here are the ones a PHP type cannot express, such as "exactly five angles".
 */
final class ThumbnailPlanSchema
{
    private function __construct()
    {
    }

    /**
     * The language name is spelled in English ("French", "English") because that is
     * what the model reads best. Callers get it from the interface locale; the
     * default matches the application's own default locale.
     *
     * @return array<string, mixed>
     */
    public static function definition(int $angleCount = AnalysisPromptBuilder::ANGLE_COUNT, string $language = 'English'): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => \sprintf('What the video is about, in three %s sentences.', $language),
                ],
                'promise' => [
                    'type' => 'string',
                    'description' => \sprintf('The single promise the video makes to its viewer, in %s, one sentence.', $language),
                ],
                'angles' => [
                    'type' => 'array',
                    'minItems' => $angleCount,
                    'maxItems' => $angleCount,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'overlayText' => [
                                'type' => 'string',
                                'description' => \sprintf('Two to four %s words to burn into the image.', $language),
                            ],
                            'sceneDescription' => [
                                'type' => 'string',
                                'description' => \sprintf('What the thumbnail shows, in %s, for the creator to read.', $language),
                            ],
                            'faceExpression' => [
                                'type' => 'string',
                                'description' => \sprintf('The expression the creator should wear, in %s.', $language),
                            ],
                            'referenceAngle' => [
                                'type' => 'string',
                                'enum' => [ReferenceAngle::Front->value, ReferenceAngle::Right->value, ReferenceAngle::Left->value],
                                'description' => 'Which reference photo of the creator to work from.',
                            ],
                            'imagePrompt' => [
                                'type' => 'string',
                                'description' => 'Self-contained English prompt for the image model.',
                            ],
                        ],
                        'required' => ['overlayText', 'sceneDescription', 'faceExpression', 'referenceAngle', 'imagePrompt'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary', 'promise', 'angles'],
            'additionalProperties' => false,
        ];
    }
}

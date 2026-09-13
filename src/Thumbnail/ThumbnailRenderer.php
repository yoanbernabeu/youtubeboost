<?php

declare(strict_types=1);

namespace App\Thumbnail;

use App\Settings\Entity\ReferenceAngle;
use App\Shared\Image\ImageNormalizer;
use App\Shared\Translation\TranslatableThrowable;
use App\Thumbnail\Entity\ThumbnailProposal;
use App\Thumbnail\Generation\ImageGeneratorInterface;
use App\Thumbnail\Generation\ImageRequest;
use App\Thumbnail\Generation\ReferenceImage;
use App\Thumbnail\Generation\ReferenceImageProvider;
use App\Thumbnail\Storage\ThumbnailStore;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fills one proposal with an actual image.
 *
 * Generate, bring to 1280x720, store, record. Every failure lands on the proposal
 * so the grid can show which of the five did not make it, and why.
 */
final class ThumbnailRenderer
{
    public function __construct(
        private readonly ImageGeneratorInterface $generator,
        private readonly ReferenceImageProvider $references,
        private readonly ImageNormalizer $normalizer,
        private readonly ThumbnailStore $store,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        // The reason is stored on the row and printed as is by the grid, so it is
        // translated here, the way a failed job records why it stopped.
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function render(ThumbnailProposal $proposal): void
    {
        $proposal->markGenerating();
        $this->entityManager->flush();

        try {
            $generated = $this->generator->generate($this->requestFor($proposal));
            $png = $this->normalizer->toThumbnailPng($generated->binary);
            $path = $this->store->write(
                $png,
                $proposal->getVideo()->getYoutubeId(),
                \sprintf('angle-%d', $proposal->getAngleIndex() + 1),
            );

            $proposal->attachImage($path, \strlen($png), $generated->model, $this->now());
        } catch (\Throwable $exception) {
            $proposal->markFailed($exception instanceof TranslatableThrowable
                ? $exception->translatableMessage()->trans($this->translator)
                : $exception->getMessage());
        }

        $this->entityManager->flush();
    }

    private function requestFor(ThumbnailProposal $proposal): ImageRequest
    {
        $parent = $proposal->getParent();

        if (null !== $parent && null !== $parent->getImagePath()) {
            return new ImageRequest(
                $proposal->getPrompt(),
                [],
                new ReferenceImage($this->store->read($parent->getImagePath()), 'image/png', ReferenceAngle::Other, 'Version précédente'),
            );
        }

        return new ImageRequest($proposal->getPrompt(), $this->references->forAngle($proposal->getReferenceAngle()));
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}

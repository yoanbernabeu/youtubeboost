<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Ai\TolerantGeminiModelCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\AI\Platform\Bridge\Gemini\Gemini;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Checks the decoration is actually wired, not just that the class behaves: the
 * whole point is that the platform the application injects stops refusing models.
 */
#[CoversClass(TolerantGeminiModelCatalog::class)]
final class TolerantGeminiModelCatalogTest extends KernelTestCase
{
    private ModelCatalogInterface $catalog;

    protected function setUp(): void
    {
        self::bootKernel();
        $catalog = self::getContainer()->get('ai.platform.model_catalog.gemini');
        self::assertInstanceOf(TolerantGeminiModelCatalog::class, $catalog, 'The bundle catalogue must be decorated.');
        $this->catalog = $catalog;
    }

    public function testAModelTheLibraryAlreadyKnowsKeepsItsDeclaredCapabilities(): void
    {
        $model = $this->catalog->getModel('gemini-3.1-flash-image');

        self::assertInstanceOf(Gemini::class, $model);
        self::assertSame('gemini-3.1-flash-image', $model->getName());
        self::assertTrue($model->supports(Capability::OUTPUT_IMAGE));
    }

    /**
     * A model Google has released but the installed bridge does not list yet must
     * reach the API instead of being refused locally.
     */
    public function testAModelMissingFromTheLibraryIsStillServed(): void
    {
        $model = $this->catalog->getModel('gemini-42.0-flash');

        self::assertInstanceOf(Gemini::class, $model);
        self::assertSame('gemini-42.0-flash', $model->getName());
        self::assertTrue($model->supports(Capability::OUTPUT_STRUCTURED));
        self::assertFalse($model->supports(Capability::OUTPUT_IMAGE), 'Only an image model claims to draw.');
    }

    public function testAnUnknownImageModelIsAssumedToDraw(): void
    {
        self::assertTrue($this->catalog->getModel('gemini-42.0-flash-image')->supports(Capability::OUTPUT_IMAGE));
    }

    /**
     * The tolerance is deliberately limited to the Gemini family: anything else is
     * a configuration mistake worth reporting rather than forwarding.
     */
    public function testANameFromAnotherProviderIsStillRefused(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->catalog->getModel('gpt-5');
    }
}

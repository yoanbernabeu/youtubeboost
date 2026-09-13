<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Image;

use App\Shared\Image\ImageNormalizer;
use App\Shared\Image\InvalidImageException;
use App\Shared\Image\UploadImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageNormalizer::class)]
#[CoversClass(UploadImage::class)]
final class ImageNormalizerTest extends TestCase
{
    private ImageNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ImageNormalizer();
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    #[DataProvider('sourceSizes')]
    public function testItAlwaysProducesAYoutubeSizedPng(int $width, int $height): void
    {
        $png = $this->normalizer->toThumbnailPng(self::image($width, $height));

        self::assertSame("\x89PNG", substr($png, 0, 4));
        self::assertSame([ImageNormalizer::WIDTH, ImageNormalizer::HEIGHT], self::sizeOf($png));
    }

    /**
     * @return iterable<string, array{positive-int, positive-int}>
     */
    public static function sourceSizes(): iterable
    {
        yield 'what Gemini returns at 1K in 16:9' => [1344, 768];
        yield 'already the right size' => [1280, 720];
        yield 'four by three' => [1024, 768];
        yield 'vertical' => [720, 1280];
        yield 'tiny' => [64, 36];
        yield 'very wide' => [2560, 720];
    }

    public function testItCropsTheCentreRatherThanSquashingTheImage(): void
    {
        // A 4:3 source: the centre 400x224 band is exactly what a 16:9 crop keeps.
        // Painting it red and everything else green proves the crop happened: a
        // plain resize would have pulled the green rows into the result.
        $source = imagecreatetruecolor(400, 300);
        self::assertNotFalse($source);
        $green = (int) imagecolorallocate($source, 0, 255, 0);
        $red = (int) imagecolorallocate($source, 255, 0, 0);
        imagefill($source, 0, 0, $green);
        imagefilledrectangle($source, 0, 38, 399, 261, $red);
        ob_start();
        imagepng($source);
        $bytes = (string) ob_get_clean();

        $png = $this->normalizer->toThumbnailPng($bytes);

        $result = imagecreatefromstring($png);
        self::assertNotFalse($result);
        foreach ([[640, 20], [640, 360], [640, 700]] as [$x, $y]) {
            $index = imagecolorat($result, $x, $y);
            self::assertNotFalse($index);
            $colour = imagecolorsforindex($result, $index);
            self::assertGreaterThan(200, $colour['red'], \sprintf('Pixel %d,%d should come from the red band.', $x, $y));
            self::assertLessThan(60, $colour['green'], \sprintf('Pixel %d,%d must not pick up the green border.', $x, $y));
        }
    }

    public function testItReadsJpegSources(): void
    {
        $source = imagecreatetruecolor(1344, 768);
        self::assertNotFalse($source);
        ob_start();
        imagejpeg($source, null, 90);
        $jpeg = (string) ob_get_clean();

        self::assertSame([1280, 720], self::sizeOf($this->normalizer->toThumbnailPng($jpeg)));
    }

    public function testItRejectsSomethingThatIsNotAnImage(): void
    {
        $this->expectException(InvalidImageException::class);

        $this->normalizer->toThumbnailPng('certainly not an image');
    }

    public function testItRejectsAnEmptyPayload(): void
    {
        $this->expectException(InvalidImageException::class);

        $this->normalizer->toThumbnailPng('');
    }

    public function testASmallPngIsUploadedAsIs(): void
    {
        $png = $this->normalizer->toThumbnailPng(self::image(1280, 720));

        $upload = $this->normalizer->forUpload($png);

        self::assertSame('image/png', $upload->mimeType);
        self::assertSame($png, $upload->binary);
        self::assertLessThanOrEqual(ImageNormalizer::MAX_UPLOAD_BYTES, $upload->byteSize());
    }

    public function testAHeavyPngIsReEncodedAsJpegToFitYoutubeLimit(): void
    {
        $heavy = self::noisyPng(1280, 720);
        self::assertGreaterThan(ImageNormalizer::MAX_UPLOAD_BYTES, \strlen($heavy), 'The fixture must exceed the YouTube limit.');

        $upload = $this->normalizer->forUpload($heavy);

        self::assertSame('image/jpeg', $upload->mimeType);
        self::assertLessThanOrEqual(ImageNormalizer::MAX_UPLOAD_BYTES, $upload->byteSize());
        self::assertSame([1280, 720], self::sizeOf($upload->binary));
    }

    public function testASmallSourceKeepsTheMimeTypeItWasGiven(): void
    {
        $image = imagecreatetruecolor(1280, 720);
        self::assertNotFalse($image);
        ob_start();
        imagejpeg($image, null, 80);
        $jpeg = (string) ob_get_clean();

        $upload = $this->normalizer->forUpload($jpeg, 'image/jpeg');

        self::assertSame('image/jpeg', $upload->mimeType);
        self::assertSame($jpeg, $upload->binary);
    }

    public function testALargePhotoIsBoundedAndTurnedIntoJpeg(): void
    {
        $jpeg = $this->normalizer->toBoundedJpeg(self::image(4000, 3000), 1600);

        self::assertSame("\xFF\xD8", substr($jpeg, 0, 2), 'The result must be a JPEG.');
        self::assertSame([1600, 1200], self::sizeOf($jpeg));
    }

    public function testASmallPhotoIsOnlyReEncoded(): void
    {
        $jpeg = $this->normalizer->toBoundedJpeg(self::image(800, 600), 1600);

        self::assertSame([800, 600], self::sizeOf($jpeg));
    }

    public function testAPortraitPhotoIsBoundedOnItsHeight(): void
    {
        $jpeg = $this->normalizer->toBoundedJpeg(self::image(1200, 2400), 1200);

        self::assertSame([600, 1200], self::sizeOf($jpeg));
    }

    public function testABoundBelowOnePixelIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->normalizer->toBoundedJpeg(self::image(100, 100), 0);
    }

    public function testItExposesTheDimensionsOfAnImage(): void
    {
        self::assertSame([320, 180], $this->normalizer->dimensions(self::image(320, 180)));
    }

    public function testItRefusesToMeasureGarbage(): void
    {
        $this->expectException(InvalidImageException::class);

        $this->normalizer->dimensions('garbage');
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function image(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        $colour = (int) imagecolorallocate($image, 10, 120, 220);
        imagefill($image, 0, 0, $colour);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * Random noise is incompressible, which is the reliable way to build a PNG
     * heavier than two megabytes.
     */
    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function noisyPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        for ($x = 0; $x < $width; ++$x) {
            for ($y = 0; $y < $height; ++$y) {
                imagesetpixel($image, $x, $y, random_int(0, 16777215));
            }
        }

        ob_start();
        imagepng($image, null, 1);

        return (string) ob_get_clean();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function sizeOf(string $binary): array
    {
        $size = getimagesizefromstring($binary);
        self::assertNotFalse($size);

        return [$size[0], $size[1]];
    }
}

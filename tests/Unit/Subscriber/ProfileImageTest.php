<?php

declare(strict_types=1);

namespace App\Tests\Unit\Subscriber;

use App\Subscriber\ProfileImage;
use App\Validator\InvalidField;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileImageTest extends TestCase
{
    /** @return iterable<string, array{'png'|'jpeg'|'webp'}> */
    public static function formats(): iterable
    {
        yield 'png' => ['png'];
        yield 'jpeg' => ['jpeg'];
        yield 'webp' => ['webp'];
    }

    /** @param 'png'|'jpeg'|'webp' $format */
    #[DataProvider('formats')]
    public function testEverySupportedFormatBecomesTheStoredSquarePng(string $format): void
    {
        $stored = ProfileImage::fromBytes(self::halves(300, 120, $format))->png;
        $info = getimagesizefromstring($stored);
        self::assertNotFalse($info);
        self::assertSame([ProfileImage::SIZE, ProfileImage::SIZE, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
    }

    public function testTheCentredSquareIsKeptNotSquashed(): void
    {
        // Left half red, right half blue: the centred square of a wide picture still shows both, split down the middle.
        $image = self::decode(ProfileImage::fromBytes(self::halves(400, 100, 'png'))->png);
        self::assertSame('red', self::colour($image, 20, 128));
        self::assertSame('blue', self::colour($image, 235, 128));
    }

    public function testExifOrientationIsApplied(): void
    {
        // Stored landscape (red left, blue right) with Orientation 6: shown turned a quarter clockwise, red on top.
        $jpeg = self::withOrientation(self::halves(200, 100, 'jpeg'), 6);
        $image = self::decode(ProfileImage::fromBytes($jpeg)->png);
        self::assertSame('red', self::colour($image, 128, 20));
        self::assertSame('blue', self::colour($image, 128, 235));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'empty' => ['', 'No picture was received'];
        yield 'not an image' => ['<?php echo "hi";', 'is not a PNG, JPEG or WebP picture'];
        yield 'gif' => [(string) base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='), 'is not a PNG, JPEG or WebP picture'];
        yield 'decompression bomb' => [self::pngHeader(9000, 9000), 'too many pixels'];
        yield 'damaged' => [self::pngHeader(10, 10), 'could not be read'];
        yield 'too large' => [str_repeat('x', ProfileImage::MAX_UPLOAD_BYTES + 1), 'larger than 8 MB'];
    }

    #[DataProvider('refusals')]
    public function testRefusalsExplainThemselvesAtTheImageField(string $bytes, string $message): void
    {
        try {
            ProfileImage::fromBytes($bytes);
            self::fail('accepted');
        } catch (InvalidField $e) {
            self::assertSame('image', $e->field);
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testEditedDataUrls(): void
    {
        $png = self::halves(10, 10, 'png');
        self::assertSame($png, ProfileImage::decodeEdited('data:image/png;base64,' . base64_encode($png)));
        self::assertNull(ProfileImage::decodeEdited(''), 'no edit: the uploaded file is used');
        self::assertNull(ProfileImage::decodeEdited('data:image/jpeg;base64,' . base64_encode($png)), 'only the PNG the editor makes');
        self::assertNull(ProfileImage::decodeEdited('data:image/png;base64,***'));
        $this->expectException(InvalidField::class);
        ProfileImage::decodeEdited('data:image/png;base64,' . str_repeat('A', ProfileImage::MAX_EDITED_FIELD_BYTES));
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     * @param 'png'|'jpeg'|'webp' $format
     */
    private static function halves(int $width, int $height, string $format): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 0, 0, 255));
        ob_start();
        match ($format) {
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image, null, 95),
            'webp' => imagewebp($image, null, 95),
        };
        return (string) ob_get_clean();
    }

    /** A JPEG with an EXIF APP1 segment holding only the Orientation tag (little-endian TIFF), as cameras write it. */
    private static function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientation, 0) . pack('V', 0);
        $app1 = "Exif\0\0" . $tiff;
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    /** A PNG signature and header claiming the given size, with no image data. */
    private static function pngHeader(int $width, int $height): string
    {
        $ihdr = 'IHDR' . pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
    }

    private static function decode(string $png): \GdImage
    {
        $image = imagecreatefromstring($png);
        self::assertNotFalse($image);
        return $image;
    }

    private static function colour(\GdImage $image, int $x, int $y): string
    {
        $index = imagecolorat($image, $x, $y);
        self::assertNotFalse($index);
        $rgb = imagecolorsforindex($image, $index);
        return match (true) {
            $rgb['red'] > 200 && $rgb['blue'] < 60 => 'red',
            $rgb['blue'] > 200 && $rgb['red'] < 60 => 'blue',
            default => sprintf('rgb(%d,%d,%d)', $rgb['red'], $rgb['green'], $rgb['blue']),
        };
    }
}

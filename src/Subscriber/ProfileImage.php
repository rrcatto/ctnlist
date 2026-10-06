<?php

declare(strict_types=1);

namespace App\Subscriber;

use App\Validator\InvalidField;

/**
 * One profile picture, validated and re-encoded: bytes in, a square PNG of
 * SIZE pixels out. It reads and writes nothing else, so every check is
 * testable on its own.
 *
 * The browser's editor (assets/profile_image.js) posts the square it cropped
 * as a PNG data URL; without JavaScript the chosen file is posted and the
 * centred square is taken here. Either way the bytes are treated as an
 * upload: the type comes from the bytes (never the file name or the
 * browser's Content-Type), the pixel count is checked from the header before
 * anything is decoded (a small file can decode to gigabytes), and the result
 * is always re-encoded, so nothing the client sent is stored as sent.
 */
final class ProfileImage
{
    /** The stored square, in pixels: sharp at every size it is shown. */
    public const SIZE = 256;

    public const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

    /** The posted data URL (base64), checked before it is decoded. */
    public const MAX_EDITED_FIELD_BYTES = 4 * 1024 * 1024;

    public const MAX_SOURCE_PIXELS = 8000 * 8000;

    public const FORMATS = 'PNG, JPEG or WebP';

    /** @var array<int, string> */
    private const SUPPORTED = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];

    private function __construct(public readonly string $png)
    {
    }

    /** @throws InvalidField (field `image`) with a message for the subscriber */
    public static function fromBytes(string $bytes): self
    {
        if ($bytes === '') {
            throw new InvalidField('image', 'No picture was received. Choose a file and try again.');
        }
        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new InvalidField('image', 'That picture is larger than 8 MB. Choose a smaller file.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset(self::SUPPORTED[$info[2]])) {
            throw new InvalidField('image', 'That file is not a ' . self::FORMATS . ' picture.');
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_SOURCE_PIXELS) {
            throw new InvalidField('image', 'That picture has too many pixels. Choose a smaller one.');
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new InvalidField('image', 'That picture could not be read; it may be damaged.');
        }
        $source = self::upright($source, $bytes, $info[2]);
        return new self(self::encode(self::square($source)));
    }

    /**
     * The bytes of the editor's PNG data URL, or null when the field holds no
     * edit (then the uploaded file is used). The result still goes through
     * fromBytes(), so a hand-made field is only a differently shaped upload.
     *
     * @throws InvalidField when the field is too large to accept
     */
    public static function decodeEdited(string $field): ?string
    {
        $prefix = 'data:image/png;base64,';
        if (!str_starts_with($field, $prefix)) {
            return null;
        }
        if (strlen($field) > self::MAX_EDITED_FIELD_BYTES) {
            throw new InvalidField('image', 'The edited picture was too large. Choose a smaller one.');
        }
        $bytes = base64_decode(substr($field, strlen($prefix)), true);
        return $bytes === false || $bytes === '' ? null : $bytes;
    }

    /**
     * Turned the way the camera was held. Phones store a landscape bitmap and
     * an EXIF Orientation tag that the decoder ignores, so without this an
     * upright photo arrives on its side. imagerotate() turns anticlockwise for
     * positive angles; EXIF describes the clockwise turn to apply.
     */
    private static function upright(\GdImage $image, string $bytes, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return $image;
        }
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return $image;
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 0) : 0;
        $mirror = static function (\GdImage $image): \GdImage {
            imageflip($image, IMG_FLIP_HORIZONTAL);
            return $image;
        };
        $rotate = static fn(\GdImage $image, int $angle): \GdImage => imagerotate($image, $angle, 0) ?: $image;
        return match ($orientation) {
            2 => $mirror($image),
            3 => $rotate($image, 180),
            4 => $rotate($mirror($image), 180),
            5 => $rotate($mirror($image), 90),
            6 => $rotate($image, -90),
            7 => $rotate($mirror($image), -90),
            8 => $rotate($image, 90),
            default => $image,
        };
    }

    /** The centred square, scaled to SIZE: cropped rather than squashed. */
    private static function square(\GdImage $source): \GdImage
    {
        [$width, $height] = [imagesx($source), imagesy($source)];
        $side = min($width, $height);
        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);
        return $canvas;
    }

    private static function encode(\GdImage $image): string
    {
        ob_start();
        $written = imagepng($image, null, 6);
        $png = (string) ob_get_clean();
        if (!$written || $png === '') {
            throw new \RuntimeException('The profile picture could not be encoded.');
        }
        return $png;
    }
}

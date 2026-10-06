<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriber;

use App\Repository\SubscriberImageRepository;
use App\Subscriber\ProfileImage;
use App\Subscriber\ProfileImages;
use App\Tests\Integration\IntegrationTestCase;
use App\Validator\InvalidField;

final class ProfileImagesTest extends IntegrationTestCase
{
    public function testSaveReplaceAndRemove(): void
    {
        $images = $this->service(ProfileImages::class);
        $repository = $this->service(SubscriberImageRepository::class);
        $id = $this->createSubscriber('jane@example.com');
        $placeholder = $images->url(null);
        self::assertStringEndsWith('.svg', $placeholder);
        self::assertSame($placeholder, $images->url($id), 'no picture yet: the placeholder');
        self::assertFalse($images->has($id));

        $images->save($id, '', self::png(500, 300));
        $stored = $repository->find($id);
        self::assertNotNull($stored);
        self::assertSame([ProfileImage::SIZE, ProfileImage::SIZE], array_slice((array) getimagesizefromstring($stored['png']), 0, 2), 'the uploaded file, as a centred square');
        $first = $images->url($id);
        self::assertMatchesRegularExpression('#^/profile/image\?v=[0-9a-f]{12}$#', $first);

        // The editor's square wins over the file when both arrive.
        usleep(2000);
        $images->save($id, 'data:image/png;base64,' . base64_encode(self::png(256, 256)), self::png(10, 10));
        self::assertNotSame($first, $images->url($id), 'a new picture is a new address');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM subscriber_images WHERE si_s_id = ?', [$id]), 'replaced, not added');

        self::assertTrue($images->remove($id));
        self::assertFalse($images->remove($id));
        self::assertSame($placeholder, $images->url($id));
    }

    public function testRefusedPicturesStoreNothing(): void
    {
        $images = $this->service(ProfileImages::class);
        $id = $this->createSubscriber('jane@example.com');
        try {
            $images->save($id, 'data:image/png;base64,' . base64_encode('<svg onload="x()"/>'), '');
            self::fail('stored a non-picture');
        } catch (InvalidField $e) {
            self::assertSame('image', $e->field);
        }
        self::assertFalse($images->has($id));
    }

    /** Subscribers with consent events can never be deleted (append-only), so the rule is read from the schema. */
    public function testThePictureGoesWithTheSubscriber(): void
    {
        self::assertSame('c', $this->db->fetchOne(
            "SELECT confdeltype FROM pg_constraint WHERE conrelid = 'subscriber_images'::regclass AND contype = 'f' AND confrelid = 'subscribers'::regclass"
        ), 'ON DELETE CASCADE');
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagepng($image);
        return (string) ob_get_clean();
    }
}

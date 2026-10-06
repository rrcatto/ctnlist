<?php

declare(strict_types=1);

namespace App\Subscriber;

use App\Repository\SubscriberImageRepository;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Subscribers' profile pictures: saving (through ProfileImage), removing,
 * and the address a page shows, which is the subscriber's own picture
 * (versioned, so a new picture is a new URL and the old one can be cached
 * for good) or the placeholder.
 */
final class ProfileImages
{
    public const PLACEHOLDER = 'images/avatar-placeholder.svg';

    /** @var array<int, ?string> versions already looked up in this request */
    private array $versions = [];

    public function __construct(
        private readonly SubscriberImageRepository $images,
        private readonly UrlGeneratorInterface $urls,
        private readonly Packages $assets,
    ) {
    }

    /**
     * @param string $edited the editor's PNG data URL ('' when the browser did not edit)
     * @param string $uploaded the uploaded file's bytes ('' when none)
     * @throws \App\Validator\InvalidField with a message for the subscriber
     */
    public function save(int $subscriberId, string $edited, string $uploaded): void
    {
        $image = ProfileImage::fromBytes(ProfileImage::decodeEdited($edited) ?? $uploaded);
        $this->images->store($subscriberId, $image->png, ProfileImage::SIZE);
        unset($this->versions[$subscriberId]);
    }

    public function remove(int $subscriberId): bool
    {
        unset($this->versions[$subscriberId]);
        return $this->images->remove($subscriberId);
    }

    public function has(int $subscriberId): bool
    {
        return $this->version($subscriberId) !== null;
    }

    /** The subscriber's picture, or the placeholder (null subscriber: signed out). */
    public function url(?int $subscriberId): string
    {
        $version = $subscriberId === null ? null : $this->version($subscriberId);
        return $version === null ? $this->placeholderUrl() : $this->urls->generate('profile_image', ['v' => $version]);
    }

    public function placeholderUrl(): string
    {
        return $this->assets->getUrl(self::PLACEHOLDER);
    }

    private function version(int $subscriberId): ?string
    {
        return array_key_exists($subscriberId, $this->versions)
            ? $this->versions[$subscriberId]
            : $this->versions[$subscriberId] = $this->images->version($subscriberId);
    }
}

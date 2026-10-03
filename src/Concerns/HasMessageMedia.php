<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaUrlResolver;
use RoundlyConsulting\Messages\Exceptions\MessageException;
use RoundlyConsulting\Messages\Support\MessagesConfig;

/**
 * First-class file attachments for the bundled Message model, built on
 * roundly-consulting/media-library-for-laravel.
 *
 * Declares the message's private `attachments` bucket (images get a responsive width ladder,
 * other files are stored as passthrough originals) on top of media-library's `InteractsWithMedia`
 * seam, and adds messages-specific readers and signed/temporary URL helpers. Attachments default
 * to private and are served only through media's signed streaming route.
 *
 * @mixin Model
 */
trait HasMessageMedia
{
    use InteractsWithMedia;

    public function registerMediaBuckets(): void
    {
        $bucket = $this->addMediaBucket($this->attachmentsBucket())
            ->withVisibility($this->attachmentsVisibility());

        $accepted = MessagesConfig::acceptedMimeTypes();

        if ($accepted !== []) {
            $bucket->acceptsMimeTypes($accepted);
        }

        $maxFileSize = MessagesConfig::maxFileSize();

        if ($maxFileSize !== null) {
            $bucket->maxFileSize($maxFileSize);
        }

        $disk = MessagesConfig::disk();

        if ($disk !== null) {
            $bucket->useDisk($disk);
        } elseif ($this->attachmentsVisibility() === MessagesConfig::VISIBILITY_PRIVATE) {
            // A private attachment must not land on media-library's default disk: that is the
            // web-served `public` disk, where the file is reachable under /storage without the
            // signed URL. Its variants follow it, whatever `media.variants_disk` says.
            $privateDisk = MessagesConfig::privateDisk();

            $bucket->useDisk($privateDisk)->storingVariantsOnDisk($privateDisk);
        }

        // null lets media-library apply its configured default ladder; an explicit list overrides.
        $bucket->responsiveWidths(MessagesConfig::responsiveWidths());
    }

    /**
     * Every attachment on this message (images and non-images), in bucket order.
     *
     * @return Collection<int, Media>
     */
    public function attachments(): Collection
    {
        return $this->getMedia($this->attachmentsBucket());
    }

    /**
     * Image attachments only.
     *
     * @return Collection<int, Media>
     */
    public function imageAttachments(): Collection
    {
        return $this->attachments()
            ->filter(static fn (Media $media): bool => $media->isImage())
            ->values();
    }

    /**
     * Non-image (passthrough) attachments — PDFs, archives, and the like.
     *
     * @return Collection<int, Media>
     */
    public function fileAttachments(): Collection
    {
        return $this->attachments()
            ->reject(static fn (Media $media): bool => $media->isImage())
            ->values();
    }

    public function hasAttachments(): bool
    {
        return $this->hasMedia($this->attachmentsBucket());
    }

    /**
     * A short-lived, signed URL for an attachment (presigned on capable disks, otherwise via
     * media's signed streaming route). Works for both images and other file types.
     */
    public function attachmentUrl(Media $media, string $variant = ''): string
    {
        return $media->getTemporaryUrl($this->temporaryUrlExpiry(), $variant);
    }

    /**
     * A signed URL that forces a download (HTTP attachment). The `download` flag is part of the
     * signature, so it is minted through media's signed streaming route.
     */
    public function attachmentDownloadUrl(Media $media, string $variant = ''): string
    {
        return URL::temporarySignedRoute(
            MediaUrlResolver::ROUTE_NAME,
            $this->temporaryUrlExpiry(),
            ['media' => $media->uuid, 'variant' => $variant, 'download' => 1],
        );
    }

    /**
     * A signed URL for an image attachment's preview variant (falls back to the original when the
     * variant is not generated yet, honouring media's `url_fallback_to_original`).
     *
     * @throws MessageException when the media is not an image.
     */
    public function attachmentPreviewUrl(Media $media, string $variant = ''): string
    {
        if (! $media->isImage()) {
            throw MessageException::attachmentIsNotAnImage();
        }

        return $media->getTemporaryUrl($this->temporaryUrlExpiry(), $variant);
    }

    public function attachmentsBucket(): string
    {
        return MessagesConfig::attachmentsBucket();
    }

    private function attachmentsVisibility(): string
    {
        return MessagesConfig::attachmentsVisibility();
    }

    private function temporaryUrlExpiry(): DateTimeInterface
    {
        return CarbonImmutable::now()->addMinutes(MessagesConfig::temporaryUrlLifetime());
    }
}

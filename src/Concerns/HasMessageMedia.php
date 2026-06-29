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

        $accepted = config('messages.media.accepted_mime_types');

        if (is_array($accepted) && $accepted !== []) {
            $bucket->acceptsMimeTypes($this->stringList($accepted));
        }

        $maxFileSize = config('messages.media.max_file_size');

        if (is_int($maxFileSize) && $maxFileSize > 0) {
            $bucket->maxFileSize($maxFileSize);
        }

        $disk = config('messages.media.disk');

        if (is_string($disk) && $disk !== '') {
            $bucket->useDisk($disk);
        }

        $widths = config('messages.media.responsive_widths');

        // null lets media-library apply its configured default ladder; an explicit list overrides.
        $bucket->responsiveWidths(is_array($widths) ? $this->normalizeWidths($widths) : null);
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
        return (string) config('messages.media.attachments_bucket', 'attachments');
    }

    private function attachmentsVisibility(): string
    {
        $visibility = config('messages.media.visibility', 'private');

        return $visibility === 'public' ? 'public' : 'private';
    }

    private function temporaryUrlExpiry(): DateTimeInterface
    {
        $minutes = config('messages.media.temporary_url_lifetime');

        if (! is_numeric($minutes)) {
            $minutes = config('media.temporary_url_default_lifetime', 5);
        }

        return CarbonImmutable::now()->addMinutes(is_numeric($minutes) ? (int) $minutes : 5);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $clean = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $clean[] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param  array<array-key, mixed>  $widths
     * @return list<int>
     */
    private function normalizeWidths(array $widths): array
    {
        $clean = [];

        foreach ($widths as $width) {
            if (is_int($width) && $width > 0) {
                $clean[] = $width;
            }
        }

        return $clean;
    }
}

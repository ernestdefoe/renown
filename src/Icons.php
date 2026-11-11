<?php

namespace ErnestDefoe\Renown;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Intervention\Image\ImageManager;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Uploaded icons for ranks and prestige tiers, on the `flarum-assets` disk
 * beside the forum logo. Every upload is re-encoded to a 128px WebP: an icon
 * is drawn at 22–112px, and an admin's 5 MB PNG should never ride along
 * with every post.
 */
class Icons
{
    public const SIZE = 128;

    public const MAX_BYTES = 4 * 1024 * 1024;

    public const ALLOWED = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    private Cloud $disk;

    public function __construct(Factory $filesystem, private readonly ImageManager $images)
    {
        $this->disk = $filesystem->disk('flarum-assets');
    }

    /** @return string the stored file name */
    public function put(string $kind, int $step, UploadedFileInterface $file): string
    {
        $encoded = (string) $this->images
            ->read($file->getStream()->getContents())
            ->scaleDown(width: self::SIZE, height: self::SIZE)
            ->toWebp(85);

        // A fresh name per upload, so browsers holding the old icon fetch the new one.
        $name = 'renown-'.$kind.'-'.$step.'-'.bin2hex(random_bytes(4)).'.webp';
        $this->disk->put($name, $encoded);

        return $name;
    }

    public function forget(?string $path): void
    {
        if ($path && str_starts_with($path, 'renown-')) {
            $this->disk->delete($path);
        }
    }

    public function url(?string $path): ?string
    {
        return $path ? $this->disk->url($path) : null;
    }
}

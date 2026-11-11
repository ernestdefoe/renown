<?php

namespace ErnestDefoe\Renown;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Intervention\Image\ImageManager;

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

    /** The widest and tallest image accepted, checked before it is decoded. */
    public const MAX_PIXELS = 2048;

    /** The raster formats accepted, by what the file's own bytes say it is. */
    private const TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    private Cloud $disk;

    public function __construct(Factory $filesystem, private readonly ImageManager $images)
    {
        $this->disk = $filesystem->disk('flarum-assets');
    }

    public static function isRaster(string $bytes): bool
    {
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);

        // Read from the header before any decoding: a tiny file can claim enormous dimensions.
        return $info !== false && in_array($info[2], self::TYPES, true)
            && $info[0] > 0 && $info[1] > 0 && $info[0] <= self::MAX_PIXELS && $info[1] <= self::MAX_PIXELS;
    }

    /** @return string the stored file name */
    public function put(string $kind, int $step, string $bytes): string
    {
        $encoded = (string) $this->images
            ->read($bytes)
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

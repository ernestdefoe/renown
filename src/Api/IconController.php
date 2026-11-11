<?php

namespace ErnestDefoe\Renown\Api;

use ErnestDefoe\Renown\Curve;
use ErnestDefoe\Renown\Icons;
use ErnestDefoe\Renown\Refusal;
use ErnestDefoe\Renown\Settings;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/renown/icons/{kind}/{step} (multipart `icon`) and DELETE: an admin's
 * own icon for a rank (1–20) or a prestige tier (1–21). Without one, the
 * drawn insignia or emblem is used.
 */
class IconController extends Handler
{
    public function __construct(private readonly Icons $icons, private readonly Settings $settings)
    {
    }

    protected function respond(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        $actor->assertAdmin();

        $kind = self::route($request, 'kind');
        $step = (int) self::route($request, 'step');
        if (! in_array($kind, ['ranks', 'tiers'], true) || $step < 1 || $step > ($kind === 'ranks' ? Curve::RANKS : Curve::ETERNAL)) {
            throw new Refusal('renown_not_found', 404);
        }

        $all = $this->settings->json('icons');
        $old = $all[$kind][$step] ?? null;

        if ($request->getMethod() === 'DELETE') {
            unset($all[$kind][$step]);
            $url = null;
        } else {
            $file = $request->getUploadedFiles()['icon'] ?? null;
            if ($file && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new Refusal('renown_icon_too_large');
            }
            if (! $file || $file->getError() !== UPLOAD_ERR_OK) {
                throw new Refusal('renown_icon_missing');
            }
            if ($file->getSize() > Icons::MAX_BYTES) {
                throw new Refusal('renown_icon_too_large');
            }
            // Judged by its content, never the type the browser claims: only real PNG, JPEG, GIF
            // and WebP images reach the image library (no SVG, no HTML with an image name).
            $bytes = (string) $file->getStream();
            if (! Icons::isRaster($bytes)) {
                throw new Refusal('renown_icon_type');
            }
            try {
                $name = $this->icons->put($kind, $step, $bytes);
            } catch (\Throwable) {
                throw new Refusal('renown_icon_type');
            }
            $all[$kind][$step] = $name;
            $url = $this->icons->url($name);
        }

        $this->settings->setJson('icons', $all);
        $this->icons->forget(is_string($old) ? $old : null);

        return new JsonResponse(['data' => ['url' => $url]]);
    }
}

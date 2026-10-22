<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams the signed-in user's own tenant logo (Company 'logo' media).
 * Serves both GET /company/logo (web, tenant.web) and
 * GET /api/v1/company/logo (mobile, ResolveTenant) — tenancy is already
 * initialized by then, so the media lookup and the tenant-suffixed file path
 * can only ever be this user's own tenant. See Company::logoUrl() for why the
 * media library's own /storage URL can't be used.
 */
class CompanyLogoController extends Controller
{
    public function show(): BinaryFileResponse
    {
        $media = Company::cached()?->getFirstMedia('logo');

        abort_unless($media instanceof Media && is_file($media->getPath()), 404);

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            // The URL carries ?v={media id}, so a new upload is a new URL.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}

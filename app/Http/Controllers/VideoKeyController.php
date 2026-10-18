<?php

namespace App\Http\Controllers;

use App\Models\VideoRendition;
use App\Video\StreamToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve the AES-128 key for a specific rendition (for videos stored on R2).
 *
 * Playlists on R2 only reference the key by a relative random filename "{key_id}.key",
 * ensuring no API URLs or video IDs exist on storage. Viewers fetch keys via Cloudflare Worker;
 * the Worker verifies token + IP and proxies the request here. Here we re-verify
 * the stream token signature and expiration, ensuring the key belongs to the exact HLS directory
 * for which the token was issued.
 *
 * Keys never leave the database unencrypted (stored in video_renditions.encrypted_key, via Crypt):
 * even if someone downloads .ts files from R2, without a valid stream token they cannot decrypt them.
 */
class VideoKeyController extends Controller
{
    public function show(Request $request, string $keyId): Response
    {
        $rendition = VideoRendition::with('video')->where('key_id', $keyId)->first();

        abort_unless($rendition && $rendition->video?->isOnR2(), 404);

        abort_unless(
            StreamToken::fromConfig()->verifyStream((string) $request->query('token'), $rendition->hls_dir),
            403,
            'Token is expired or invalid.',
        );

        return response($rendition->key(), 200, [
            'Content-Type' => 'application/octet-stream',
            // Prevent any proxy/CDN from caching the key.
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

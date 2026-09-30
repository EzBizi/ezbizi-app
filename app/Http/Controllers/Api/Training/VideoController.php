<?php

namespace App\Http\Controllers\Api\Training;

use App\Http\Controllers\Api\Controller;
use App\Support\Training\GuideScreens;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page a training video plays in: the Vimeo or YouTube player in an
 * iframe, on EzBizi's own domain.
 *
 * A1 Tools found its app's WebView refused `player.vimeo.com` loaded as a
 * page (401 "restricted", its api/video_embed.php) and played the same video
 * inside an iframe on a real domain; this is that page. Checked 2026-09-30:
 * an A1 video plays here in a Chromium browser.
 *
 * Public, like /training/media's signed links are: it emits only a public
 * player, touches no data, and the id is matched to a strict charset by the
 * route, so it cannot become an open redirect or an injection.
 */
class VideoController extends Controller
{
    public function show(Request $request, string $platform, string $id): Response
    {
        // An unlisted Vimeo video plays only with its hash. Hex or nothing.
        $hash = strtolower((string) $request->query('h', ''));
        $hash = GuideScreens::isVimeoHash($hash) ? "&h={$hash}" : '';

        $src = $platform === 'vimeo'
            ? "https://player.vimeo.com/video/{$id}?app_id=122963&autoplay=1&title=0&byline=0&portrait=0&dnt=1{$hash}"
            : "https://www.youtube-nocookie.com/embed/{$id}?autoplay=1&rel=0&modestbranding=1&playsinline=1";

        $html = '<!doctype html><html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<style>html,body{margin:0;height:100%;background:#000}iframe{border:0;width:100%;height:100%}</style>'
            .'</head><body><iframe src="'.e($src).'" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe></body></html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}

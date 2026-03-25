<?php

use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});

Route::get('/post/{id}', function (int $id) {
    $post = Post::with(['user', 'media'])->find($id);
    if (!$post) {
        abort(404);
    }

    $h = static function (?string $value): string {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    };

    $authorName = $post->user?->full_name;
    if (!$authorName) {
        $authorName = $post->user?->username ?? 'Unknown';
    }

    $ogTitle = $authorName . ' on TAJIRI';

    $rawContent = $post->content ?? '';
    $cleanContent = preg_replace('/\s+/', ' ', trim(strip_tags($rawContent)));
    if (mb_strlen($cleanContent) > 200) {
        $cleanContent = mb_substr($cleanContent, 0, 200);
    }
    $ogDescription = $cleanContent;

    $ogUrl = "https://tajiri.app/post/{$id}";

    $ogImageUrl = '';
    $isVideoPost = in_array($post->post_type, [Post::TYPE_VIDEO, Post::TYPE_SHORT_VIDEO], true);

    if ($isVideoPost) {
        $videoMedia = $post->media->firstWhere('media_type', PostMedia::TYPE_VIDEO);
        if ($videoMedia) {
            $ogImageUrl = $videoMedia->thumbnail_url ?: $videoMedia->file_url;
        }

        // Fallback: if no video thumbnail, use first image.
        if (!$ogImageUrl) {
            $imageMedia = $post->media->firstWhere('media_type', PostMedia::TYPE_IMAGE);
            $ogImageUrl = $imageMedia?->file_url ?? '';
        }
    } else {
        $imageMedia = $post->media->firstWhere('media_type', PostMedia::TYPE_IMAGE);
        $ogImageUrl = $imageMedia?->file_url ?? '';

        // Fallback: if this isn't a media-less post, use the first media URL.
        if (!$ogImageUrl) {
            $firstMedia = $post->media->first();
            $ogImageUrl = $firstMedia?->file_url ?? '';
        }
    }

    $deepLink = "tajiri://post/{$id}";

    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{$h($ogTitle)}</title>

  <meta property="og:title" content="{$h($ogTitle)}" />
  <meta property="og:description" content="{$h($ogDescription)}" />
  <meta property="og:image" content="{$h($ogImageUrl)}" />
  <meta property="og:url" content="{$h($ogUrl)}" />
  <meta property="og:type" content="article" />
  <meta property="og:site_name" content="TAJIRI" />

  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:image" content="{$h($ogImageUrl)}" />
</head>
<body>
  <main style="font-family: Arial, Helvetica, sans-serif; padding: 24px;">
    <h1 style="margin: 0 0 12px;">TAJIRI</h1>
    <p style="margin: 0 0 16px;">
      Open this post in the Tajiri app for the best experience.
    </p>
    <p style="margin: 0 0 12px;">
      <a href="{$h($deepLink)}">Open in app</a>
    </p>
    <p style="margin: 0;">
      <a href="https://tajiri.app">Visit TAJIRI</a>
    </p>
  </main>
</body>
</html>
HTML;

    return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
})->whereNumber('id');

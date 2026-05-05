<?php

namespace App\Services\ContentEngine;

use App\Models\Campaign;
use App\Models\Clip;
use App\Models\ContentDocument;
use App\Models\Event;
use App\Models\GossipThread;
use App\Models\Group;
use App\Models\LiveStream;
use App\Models\MusicTrack;
use App\Models\Page;
use App\Models\Post;
use App\Models\Shop\Product;
use App\Models\Story;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Model;

class ContentDocumentFactory
{
    public static function fromModel(Model $model): array
    {
        return match (true) {
            $model instanceof Post => self::fromPost($model),
            $model instanceof Clip => self::fromClip($model),
            $model instanceof Story => self::fromStory($model),
            $model instanceof MusicTrack => self::fromMusic($model),
            $model instanceof LiveStream => self::fromStream($model),
            $model instanceof Event => self::fromEvent($model),
            $model instanceof Campaign => self::fromCampaign($model),
            $model instanceof Product => self::fromProduct($model),
            $model instanceof Group => self::fromGroup($model),
            $model instanceof Page => self::fromPage($model),
            $model instanceof UserProfile => self::fromUserProfile($model),
            $model instanceof GossipThread => self::fromGossipThread($model),
            default => throw new \InvalidArgumentException('Unsupported model: ' . get_class($model)),
        };
    }

    public static function sourceType(Model $model): string
    {
        return match (true) {
            $model instanceof Post => ContentDocument::TYPE_POST,
            $model instanceof Clip => ContentDocument::TYPE_CLIP,
            $model instanceof Story => ContentDocument::TYPE_STORY,
            $model instanceof MusicTrack => ContentDocument::TYPE_MUSIC,
            $model instanceof LiveStream => ContentDocument::TYPE_STREAM,
            $model instanceof Event => ContentDocument::TYPE_EVENT,
            $model instanceof Campaign => ContentDocument::TYPE_CAMPAIGN,
            $model instanceof Product => ContentDocument::TYPE_PRODUCT,
            $model instanceof Group => ContentDocument::TYPE_GROUP,
            $model instanceof Page => ContentDocument::TYPE_PAGE,
            $model instanceof UserProfile => ContentDocument::TYPE_USER_PROFILE,
            $model instanceof GossipThread => ContentDocument::TYPE_GOSSIP_THREAD,
            default => throw new \InvalidArgumentException('Unsupported model: ' . get_class($model)),
        };
    }

    private static function fromPost(Post $post): array
    {
        $mediaTypes = [];
        if ($post->media && $post->media->count() > 0) {
            $mediaTypes = $post->media->pluck('type')->filter(fn($t) => !is_null($t))->unique()->values()->toArray();
        }
        if ($post->audio_path) {
            $mediaTypes[] = 'audio';
        }

        $body = $post->content ?? '';
        $hashtags = self::extractHashtags($body);
        $mentions = self::extractMentions($body);

        // Merge hashtags from the BelongsToMany relation (pluck name strings)
        $relationHashtags = $post->hashtags()->pluck('name')->toArray();
        if (!empty($relationHashtags)) {
            $hashtags = array_unique(array_merge($hashtags, array_map('mb_strtolower', $relationHashtags)));
        }

        return [
            'source_type' => ContentDocument::TYPE_POST,
            'source_id' => $post->id,
            'title' => $body ? mb_substr(strtok($body, "\n"), 0, 100) : null,
            'body' => $body,
            'media_types' => $mediaTypes,
            'hashtags' => array_values($hashtags),
            'mentions' => array_values($mentions),
            'language' => $post->language_code ?? LanguageDetector::detect($body),
            'creator_id' => $post->user_id,
            'privacy' => $post->privacy ?? 'public',
            'region_name' => $post->user?->region_name,
            'district_name' => $post->user?->district_name,
            'category' => $post->content_category,
            'published_at' => $post->published_at ?? $post->created_at,
        ];
    }

    private static function fromClip(Clip $clip): array
    {
        $body = $clip->description ?? $clip->caption ?? '';

        return [
            'source_type' => ContentDocument::TYPE_CLIP,
            'source_id' => $clip->id,
            'title' => $clip->title ?? ($body ? mb_substr($body, 0, 100) : null),
            'body' => $body,
            'media_types' => ['video'],
            'hashtags' => self::extractHashtags($body),
            'mentions' => self::extractMentions($body),
            'language' => LanguageDetector::detect($body),
            'creator_id' => $clip->user_id,
            'privacy' => $clip->privacy ?? 'public',
            'region_name' => $clip->user?->region_name,
            'district_name' => $clip->user?->district_name,
            'category' => null,
            'published_at' => $clip->created_at,
        ];
    }

    private static function fromStory(Story $story): array
    {
        $body = $story->caption ?? '';

        return [
            'source_type' => ContentDocument::TYPE_STORY,
            'source_id' => $story->id,
            'title' => null,
            'body' => $body,
            'media_types' => [$story->media_type ?? 'image'],
            'hashtags' => self::extractHashtags($body),
            'mentions' => self::extractMentions($body),
            'language' => LanguageDetector::detect($body),
            'creator_id' => $story->user_id,
            'privacy' => $story->privacy ?? 'public',
            'region_name' => $story->user?->region_name,
            'district_name' => $story->user?->district_name,
            'category' => null,
            'published_at' => $story->created_at,
        ];
    }

    private static function fromMusic(MusicTrack $track): array
    {
        $body = implode(' ', array_filter([
            $track->artist?->first_name,
            $track->artist?->last_name,
            $track->album,
            $track->lyrics,
        ]));

        return [
            'source_type' => ContentDocument::TYPE_MUSIC,
            'source_id' => $track->id,
            'title' => $track->title,
            'body' => $body,
            'media_types' => ['audio'],
            'hashtags' => [],
            'mentions' => [],
            'language' => LanguageDetector::detect($body),
            'creator_id' => $track->artist_id ?? $track->uploaded_by,
            'privacy' => 'public',
            'region_name' => null,
            'district_name' => null,
            'category' => $track->genre ?? 'music',
            'published_at' => $track->created_at,
        ];
    }

    private static function fromStream(LiveStream $stream): array
    {
        $body = implode(' ', array_filter([$stream->title, $stream->description]));

        return [
            'source_type' => ContentDocument::TYPE_STREAM,
            'source_id' => $stream->id,
            'title' => $stream->title,
            'body' => $body,
            'media_types' => ['video'],
            'hashtags' => self::extractHashtags($body),
            'mentions' => self::extractMentions($body),
            'language' => LanguageDetector::detect($body),
            'creator_id' => $stream->user_id,
            'privacy' => $stream->privacy ?? 'public',
            'region_name' => null,
            'district_name' => null,
            'category' => $stream->category,
            'published_at' => $stream->started_at ?? $stream->created_at,
        ];
    }

    private static function fromEvent(Event $event): array
    {
        $body = implode(' ', array_filter([$event->name, $event->description]));

        return [
            'source_type' => ContentDocument::TYPE_EVENT,
            'source_id' => $event->id,
            'title' => $event->name,
            'body' => $body,
            'media_types' => $event->cover_photo_path ? ['image'] : [],
            'hashtags' => self::extractHashtags($body),
            'mentions' => [],
            'language' => LanguageDetector::detect($body),
            'creator_id' => $event->creator_id,
            'privacy' => $event->privacy ?? 'public',
            'region_name' => null,
            'district_name' => null,
            'category' => $event->category,
            'published_at' => $event->created_at,
        ];
    }

    private static function fromCampaign(Campaign $campaign): array
    {
        $body = implode(' ', array_filter([
            $campaign->title,
            $campaign->story,
            $campaign->short_description,
        ]));

        return [
            'source_type' => ContentDocument::TYPE_CAMPAIGN,
            'source_id' => $campaign->id,
            'title' => $campaign->title,
            'body' => $body,
            'media_types' => $campaign->cover_image_path ? ['image'] : [],
            'hashtags' => self::extractHashtags($body),
            'mentions' => [],
            'language' => LanguageDetector::detect($body),
            'creator_id' => $campaign->user_id,
            'privacy' => 'public',
            'region_name' => null,
            'district_name' => null,
            'category' => $campaign->category,
            'published_at' => $campaign->created_at,
        ];
    }

    private static function fromProduct(Product $product): array
    {
        $body = implode(' ', array_filter([
            $product->name ?? $product->title,
            $product->description,
        ]));

        $mediaTypes = [];
        if ($product->images || $product->cover_image_path) {
            $mediaTypes[] = 'image';
        }

        return [
            'source_type' => ContentDocument::TYPE_PRODUCT,
            'source_id' => $product->id,
            'title' => $product->name ?? $product->title,
            'body' => $body,
            'media_types' => $mediaTypes,
            'hashtags' => self::extractHashtags($body),
            'mentions' => [],
            'language' => LanguageDetector::detect($body),
            'creator_id' => $product->user_id ?? $product->seller_id ?? $product->shop_id,
            'privacy' => 'public',
            'region_name' => null,
            'district_name' => null,
            'category' => $product->category ?? 'other',
            'published_at' => $product->created_at,
        ];
    }

    private static function fromGroup(Group $group): array
    {
        $body = implode(' ', array_filter([$group->name, $group->description]));

        return [
            'source_type' => ContentDocument::TYPE_GROUP,
            'source_id' => $group->id,
            'title' => $group->name,
            'body' => $body,
            'media_types' => $group->cover_photo_path ? ['image'] : [],
            'hashtags' => [],
            'mentions' => [],
            'language' => LanguageDetector::detect($body),
            'creator_id' => $group->creator_id ?? 0,
            'privacy' => $group->privacy ?? 'public',
            'region_name' => null,
            'district_name' => null,
            'category' => null,
            'published_at' => $group->created_at,
        ];
    }

    private static function fromPage(Page $page): array
    {
        $body = implode(' ', array_filter([$page->name, $page->description, $page->category]));

        return [
            'source_type' => ContentDocument::TYPE_PAGE,
            'source_id' => $page->id,
            'title' => $page->name,
            'body' => $body,
            'media_types' => [],
            'hashtags' => [],
            'mentions' => [],
            'language' => LanguageDetector::detect($body),
            'creator_id' => $page->creator_id ?? 0,
            'privacy' => 'public',
            'region_name' => null,
            'district_name' => null,
            'category' => $page->category,
            'published_at' => $page->created_at,
        ];
    }

    private static function fromUserProfile(UserProfile $profile): array
    {
        $body = implode(' ', array_filter([
            $profile->first_name,
            $profile->last_name,
            $profile->username,
            $profile->bio,
        ]));

        return [
            'source_type' => ContentDocument::TYPE_USER_PROFILE,
            'source_id' => $profile->id,
            'title' => trim(($profile->first_name ?? '') . ' ' . ($profile->last_name ?? '')),
            'body' => $body,
            'media_types' => [],
            'hashtags' => [],
            'mentions' => [],
            'language' => LanguageDetector::detect($profile->bio),
            'creator_id' => $profile->id,
            'privacy' => $profile->profile_visibility ?? 'public',
            'region_name' => $profile->region_name,
            'district_name' => $profile->district_name,
            'category' => null,
            'published_at' => $profile->created_at,
        ];
    }

    private static function fromGossipThread(GossipThread $thread): array
    {
        $title = $thread->getResolvedTitleEn() ?? $thread->title_key;
        $body = implode(' ', array_filter([
            $title,
            $thread->getResolvedTitleSw(),
            $thread->category,
        ]));

        return [
            'source_type' => ContentDocument::TYPE_GOSSIP_THREAD,
            'source_id' => $thread->id,
            'title' => $title,
            'body' => $body,
            'media_types' => [],
            'hashtags' => [],
            'mentions' => [],
            'language' => 'sw',
            'creator_id' => $thread->seedPost?->user_id ?? 0,
            'privacy' => 'public',
            'region_name' => null,
            'district_name' => null,
            'category' => $thread->category,
            'published_at' => $thread->created_at,
        ];
    }

    public static function extractHashtags(?string $text): array
    {
        if (empty($text)) {
            return [];
        }
        preg_match_all('/#(\w+)/u', $text, $matches);
        return array_unique(array_map('mb_strtolower', $matches[1] ?? []));
    }

    public static function extractMentions(?string $text): array
    {
        if (empty($text)) {
            return [];
        }
        preg_match_all('/@(\w+)/u', $text, $matches);
        return array_unique($matches[1] ?? []);
    }
}

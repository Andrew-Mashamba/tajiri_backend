<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostView extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'post_id',
        'user_id',
        'session_id',
        'watch_time_seconds',
        'watch_percentage',
        'is_complete_view',
        'is_replay',
        'source',
        'device_type',
        'created_at',
    ];

    protected $casts = [
        'watch_time_seconds' => 'integer',
        'watch_percentage' => 'decimal:2',
        'is_complete_view' => 'boolean',
        'is_replay' => 'boolean',
        'created_at' => 'datetime',
    ];

    const SOURCE_FEED = 'feed';
    const SOURCE_PROFILE = 'profile';
    const SOURCE_DISCOVER = 'discover';
    const SOURCE_SEARCH = 'search';
    const SOURCE_SHARE = 'share';
    const SOURCE_SHORTS = 'shorts';

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    /**
     * Record a view with watch time tracking
     */
    public static function recordView(
        int $postId,
        ?int $userId,
        ?string $sessionId,
        int $watchTimeSeconds = 0,
        float $watchPercentage = 0,
        string $source = self::SOURCE_FEED,
        ?string $deviceType = null
    ): self {
        $post = Post::find($postId);

        // Check if this is a replay
        $isReplay = false;
        if ($userId) {
            $isReplay = static::where('post_id', $postId)
                ->where('user_id', $userId)
                ->exists();
        }

        $view = static::create([
            'post_id' => $postId,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'watch_time_seconds' => $watchTimeSeconds,
            'watch_percentage' => $watchPercentage,
            'is_complete_view' => $watchPercentage >= 95,
            'is_replay' => $isReplay,
            'source' => $source,
            'device_type' => $deviceType,
            'created_at' => now(),
        ]);

        // Update post counters
        if ($post) {
            $post->incrementViews();
            if ($watchTimeSeconds > 0) {
                $post->addWatchTime($watchTimeSeconds);
            }

            // Track reach
            if ($userId && $post->user_id !== $userId) {
                // Check if viewer follows the poster
                $isFollower = Friend::where('user_id', $post->user_id)
                    ->where('friend_id', $userId)
                    ->where('status', 'accepted')
                    ->exists();

                if ($isFollower) {
                    $post->increment('reach_followers');
                } else {
                    $post->increment('reach_non_followers');
                }
            }

            // Update user interests based on view
            if ($userId && $watchPercentage > 50) {
                UserInterest::recordInteraction($userId, 'creator', (string) $post->user_id);

                foreach ($post->hashtags as $hashtag) {
                    UserInterest::recordInteraction($userId, 'hashtag', $hashtag->name_normalized);
                }

                if ($post->content_category) {
                    UserInterest::recordInteraction($userId, 'category', $post->content_category);
                }
            }
        }

        return $view;
    }
}

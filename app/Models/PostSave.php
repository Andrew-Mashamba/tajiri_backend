<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostSave extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'user_id',
        'collection_id',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserProfile::class, 'user_id');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(SaveCollection::class, 'collection_id');
    }

    /**
     * Save a post for a user
     */
    public static function savePost(int $postId, int $userId, ?int $collectionId = null): self
    {
        $save = static::firstOrCreate(
            ['post_id' => $postId, 'user_id' => $userId],
            ['collection_id' => $collectionId]
        );

        if ($save->wasRecentlyCreated) {
            $post = Post::find($postId);
            $post?->incrementSaves();

            if ($collectionId) {
                SaveCollection::where('id', $collectionId)->increment('posts_count');
            }
        }

        return $save;
    }

    /**
     * Unsave a post
     */
    public static function unsavePost(int $postId, int $userId): bool
    {
        $save = static::where('post_id', $postId)->where('user_id', $userId)->first();

        if ($save) {
            $post = Post::find($postId);
            $post?->decrementSaves();

            if ($save->collection_id) {
                SaveCollection::where('id', $save->collection_id)->decrement('posts_count');
            }

            return $save->delete();
        }

        return false;
    }
}

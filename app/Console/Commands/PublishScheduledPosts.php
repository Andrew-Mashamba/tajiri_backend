<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PublishScheduledPosts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'posts:publish-scheduled
                            {--dry-run : Preview which posts would be published without actually publishing}
                            {--limit=100 : Maximum number of posts to publish in one run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish posts that are scheduled for the current time or earlier';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info('Checking for scheduled posts to publish...');

        // Get all posts that are scheduled and ready to publish
        $posts = Post::readyToPublish()
            ->with('user:id,first_name,last_name,username')
            ->limit($limit)
            ->get();

        if ($posts->isEmpty()) {
            $this->info('No scheduled posts ready to publish.');
            return Command::SUCCESS;
        }

        $this->info("Found {$posts->count()} post(s) ready to publish.");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made.');
            $this->newLine();
        }

        $published = 0;
        $failed = 0;

        foreach ($posts as $post) {
            $username = $post->user?->username ?? 'Unknown';
            $scheduledAt = $post->scheduled_at->format('Y-m-d H:i:s');
            $preview = mb_substr($post->content ?? '[No content]', 0, 50);

            $this->line("  [{$post->id}] @{$username} - Scheduled: {$scheduledAt}");
            $this->line("      Type: {$post->post_type} | Preview: {$preview}...");

            if (!$dryRun) {
                try {
                    $post->update([
                        'status' => Post::STATUS_PUBLISHED,
                        'published_at' => now(),
                    ]);

                    // Trigger any post-publish actions
                    $this->triggerPostPublishActions($post);

                    $this->info("      [PUBLISHED]");
                    $published++;

                    Log::info('Scheduled post published', [
                        'post_id' => $post->id,
                        'user_id' => $post->user_id,
                        'scheduled_at' => $scheduledAt,
                        'published_at' => now()->format('Y-m-d H:i:s'),
                    ]);
                } catch (\Exception $e) {
                    $this->error("      [FAILED] {$e->getMessage()}");
                    $failed++;

                    Log::error('Failed to publish scheduled post', [
                        'post_id' => $post->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                $this->info("      [WOULD PUBLISH]");
                $published++;
            }

            $this->newLine();
        }

        // Summary
        $this->newLine();
        $this->info('=== Summary ===');
        $this->info("Published: {$published}");
        if ($failed > 0) {
            $this->error("Failed: {$failed}");
        }

        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to actually publish.');
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Trigger post-publish actions (notifications, hashtag extraction, etc.)
     */
    private function triggerPostPublishActions(Post $post): void
    {
        // Extract and sync hashtags
        $post->extractAndSyncHashtags();

        // Update short video flag if applicable
        if ($post->post_type === Post::TYPE_VIDEO || $post->post_type === Post::TYPE_SHORT_VIDEO) {
            $post->updateShortVideoFlag();
        }

        // TODO: Send notifications to followers
        // TODO: Trigger any webhooks or integrations
    }
}

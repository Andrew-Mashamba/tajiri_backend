<?php

namespace App\Jobs;

use App\Models\UserProfile;
use App\Services\AutoGroupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job: Assign a user to all system/default groups based on their profile.
 *
 * Dispatched after:
 *   - User registration (no old profile)
 *   - Profile update (with old profile snapshot for diff)
 *
 * Runs on the queue so registration/updates stay fast.
 */
class AssignUserToDefaultGroups implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     *
     * @param int        $userId     The user profile ID
     * @param array|null $oldProfile Snapshot of old profile data (for updates), null for new registrations
     */
    public function __construct(
        public int $userId,
        public ?array $oldProfile = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(AutoGroupService $autoGroupService): void
    {
        $user = UserProfile::find($this->userId);

        if (!$user) {
            Log::warning('AssignUserToDefaultGroups: user not found', ['user_id' => $this->userId]);
            return;
        }

        Log::info('AssignUserToDefaultGroups: starting', [
            'user_id' => $this->userId,
            'is_update' => $this->oldProfile !== null,
        ]);

        $result = $autoGroupService->assignUserToGroups($user, $this->oldProfile);

        Log::info('AssignUserToDefaultGroups: completed', [
            'user_id' => $this->userId,
            'assigned_count' => count($result['assigned']),
            'removed_count' => count($result['removed']),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('AssignUserToDefaultGroups: job failed', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}

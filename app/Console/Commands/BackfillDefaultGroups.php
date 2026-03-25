<?php

namespace App\Console\Commands;

use App\Models\UserProfile;
use App\Services\AutoGroupService;
use Illuminate\Console\Command;

/**
 * Backfill all existing users into their system/default groups.
 *
 * Usage:
 *   php artisan groups:backfill                  # Process all users
 *   php artisan groups:backfill --user=42         # Process a single user
 *   php artisan groups:backfill --preview         # Preview without making changes
 *   php artisan groups:backfill --chunk=100       # Process in chunks of 100
 */
class BackfillDefaultGroups extends Command
{
    protected $signature = 'groups:backfill
                            {--user= : Process a single user by ID}
                            {--preview : Preview groups without creating them}
                            {--chunk=200 : Number of users to process per batch}';

    protected $description = 'Assign all existing users to their system/default groups based on profile data';

    public function handle(AutoGroupService $autoGroupService): int
    {
        $userId = $this->option('user');
        $preview = $this->option('preview');
        $chunkSize = (int) $this->option('chunk');

        if ($userId) {
            return $this->processSingleUser($autoGroupService, (int) $userId, $preview);
        }

        return $this->processAllUsers($autoGroupService, $preview, $chunkSize);
    }

    /**
     * Process a single user.
     */
    protected function processSingleUser(AutoGroupService $autoGroupService, int $userId, bool $preview): int
    {
        $user = UserProfile::find($userId);

        if (!$user) {
            $this->error("User {$userId} not found.");
            return self::FAILURE;
        }

        $this->info("Processing user: {$user->full_name} (ID: {$user->id})");

        if ($preview) {
            $groups = $autoGroupService->previewGroupsForUser($user);
            $this->table(
                ['Lookup Key', 'Group Name', 'Type', 'Exists', 'Members'],
                collect($groups)->map(fn($g) => [
                    $g['lookup_key'],
                    $g['name'],
                    $g['type'],
                    $g['exists'] ? 'Yes' : 'No',
                    $g['members_count'],
                ])
            );
            $this->info("Would assign user to " . count($groups) . " groups.");
            return self::SUCCESS;
        }

        $result = $autoGroupService->assignUserToGroups($user);
        $this->info("Assigned to " . count($result['assigned']) . " groups:");

        foreach ($result['assigned'] as $groupName) {
            $this->line("  + {$groupName}");
        }

        if (count($result['removed']) > 0) {
            $this->warn("Removed from " . count($result['removed']) . " groups:");
            foreach ($result['removed'] as $groupName) {
                $this->line("  - {$groupName}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Process all users in chunks.
     */
    protected function processAllUsers(AutoGroupService $autoGroupService, bool $preview, int $chunkSize): int
    {
        $totalUsers = UserProfile::count();

        if ($totalUsers === 0) {
            $this->warn('No users found.');
            return self::SUCCESS;
        }

        $this->info("Processing {$totalUsers} users in chunks of {$chunkSize}...");

        if ($preview) {
            $this->info('[PREVIEW MODE] No changes will be made.');
        }

        $bar = $this->output->createProgressBar($totalUsers);
        $bar->start();

        $totalAssigned = 0;
        $totalGroupsCreated = 0;
        $errors = 0;

        $groupCountBefore = \App\Models\Group::where('is_system', true)->count();

        UserProfile::query()
            ->orderBy('id')
            ->chunk($chunkSize, function ($users) use ($autoGroupService, $preview, &$totalAssigned, &$errors, $bar) {
                foreach ($users as $user) {
                    try {
                        if ($preview) {
                            $groups = $autoGroupService->previewGroupsForUser($user);
                            $totalAssigned += count($groups);
                        } else {
                            $result = $autoGroupService->assignUserToGroups($user);
                            $totalAssigned += count($result['assigned']);
                        }
                    } catch (\Exception $e) {
                        $errors++;
                        $this->newLine();
                        $this->error("  Error for user {$user->id}: {$e->getMessage()}");
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $groupCountAfter = \App\Models\Group::where('is_system', true)->count();
        $totalGroupsCreated = $groupCountAfter - $groupCountBefore;

        $this->info("Done!");
        $this->table(['Metric', 'Value'], [
            ['Users processed', $totalUsers],
            ['Group memberships ' . ($preview ? '(would be) ' : '') . 'assigned', $totalAssigned],
            ['System groups ' . ($preview ? '(would be) ' : '') . 'created', $preview ? 'N/A' : $totalGroupsCreated],
            ['Total system groups', $preview ? $groupCountBefore : $groupCountAfter],
            ['Errors', $errors],
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}

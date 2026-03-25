<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AutoGroupService
 *
 * Assigns users to system groups based on profile characteristics.
 *
 * Group hierarchy (broad → intimate):
 *   Primary School → Primary + Class-of-YYYY / Primary + Intake-YYYY
 *   Secondary School → Secondary + Class-of / Intake
 *   A-Level School → A-Level + Combination → (A-Level + Combo + Class-of / Intake)
 *   Post-Secondary → Dept → Programme → each + Class-of / Intake
 *   University → College → Department → Programme → each + Class-of / Intake
 *   Region → District → Ward → Street
 *   Employer
 *
 * "Class of YYYY" = graduation year (alumni)
 * "Intake YYYY" = starting year (current students)
 */
class AutoGroupService
{
    /**
     * Assign a user to all system groups matching their profile.
     */
    public function assignUserToGroups(UserProfile $user, ?array $oldProfile = null): array
    {
        $assignedGroups = [];
        $removedGroups = [];

        try {
            DB::beginTransaction();

            $targetGroups = $this->buildTargetGroups($user);

            // Remove from stale groups on profile update
            if ($oldProfile) {
                $oldGroups = $this->buildTargetGroupsFromArray($oldProfile);
                $staleKeys = array_diff(array_keys($oldGroups), array_keys($targetGroups));

                foreach ($staleKeys as $key) {
                    $group = Group::findByLookupKey($key);
                    if ($group) {
                        $this->removeFromGroup($group, $user->id);
                        $removedGroups[] = $group->name;
                    }
                }
            }

            // Join each target group (find or create)
            foreach ($targetGroups as $lookupKey => $definition) {
                $group = $this->findOrCreateGroup($lookupKey, $definition);
                if ($this->addToGroup($group, $user->id)) {
                    $assignedGroups[] = $group->name;
                }
            }

            DB::commit();

            Log::info('AutoGroupService: assigned user to groups', [
                'user_id' => $user->id,
                'assigned' => count($assignedGroups),
                'removed' => count($removedGroups),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('AutoGroupService: failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return ['assigned' => $assignedGroups, 'removed' => $removedGroups];
    }

    /**
     * Build the full list of target groups for a user.
     */
    public function buildTargetGroups(UserProfile $user): array
    {
        $groups = [];

        $this->addPrimarySchoolGroups($user, $groups);
        $this->addSecondarySchoolGroups($user, $groups);
        $this->addAlevelGroups($user, $groups);
        $this->addPostSecondaryGroups($user, $groups);
        $this->addUniversityGroups($user, $groups);
        $this->addLocationGroups($user, $groups);
        $this->addEmployerGroups($user, $groups);

        return $groups;
    }

    // ==================== PRIMARY SCHOOL ====================

    protected function addPrimarySchoolGroups(UserProfile $user, array &$groups): void
    {
        if (!$user->primary_school_id && !$user->primary_school_name) return;

        $id = $user->primary_school_id ?? Str::slug($user->primary_school_name);
        $name = $user->primary_school_name;

        // Broad
        $groups["ps:{$id}"] = $this->def($name, "All students and alumni of {$name}.", Group::SYS_PRIMARY_SCHOOL);

        // Class-of (graduation year)
        if ($user->primary_graduation_year) {
            $y = $user->primary_graduation_year;
            $groups["ps:{$id}:y:{$y}"] = $this->def("{$name} - Class of {$y}", "{$name} graduates of {$y}.", Group::SYS_PRIMARY_SCHOOL_YEAR);
        }

        // Intake (starting year — current students)
        if ($user->primary_start_year && !$user->primary_graduation_year) {
            $y = $user->primary_start_year;
            $groups["ps:{$id}:i:{$y}"] = $this->def("{$name} - Intake {$y}", "{$name} students who started in {$y}.", Group::SYS_PRIMARY_SCHOOL_INTAKE);
        }
    }

    // ==================== SECONDARY SCHOOL ====================

    protected function addSecondarySchoolGroups(UserProfile $user, array &$groups): void
    {
        if (!$user->secondary_school_id && !$user->secondary_school_name) return;

        $id = $user->secondary_school_id ?? Str::slug($user->secondary_school_name);
        $name = $user->secondary_school_name;

        $groups["ss:{$id}"] = $this->def($name, "All students and alumni of {$name}.", Group::SYS_SECONDARY_SCHOOL);

        if ($user->secondary_graduation_year) {
            $y = $user->secondary_graduation_year;
            $groups["ss:{$id}:y:{$y}"] = $this->def("{$name} - Class of {$y}", "{$name} graduates of {$y}.", Group::SYS_SECONDARY_SCHOOL_YEAR);
        }

        if ($user->secondary_start_year && !$user->secondary_graduation_year) {
            $y = $user->secondary_start_year;
            $groups["ss:{$id}:i:{$y}"] = $this->def("{$name} - Intake {$y}", "{$name} students who started in {$y}.", Group::SYS_SECONDARY_SCHOOL_INTAKE);
        }
    }

    // ==================== A-LEVEL ====================

    protected function addAlevelGroups(UserProfile $user, array &$groups): void
    {
        if (!$user->alevel_school_id && !$user->alevel_school_name) return;

        $id = $user->alevel_school_id ?? Str::slug($user->alevel_school_name);
        $name = $user->alevel_school_name;
        $combo = $user->alevel_combination_code;
        $gradYear = $user->alevel_graduation_year;
        $startYear = $user->alevel_start_year;

        // School broad
        $groups["al:{$id}"] = $this->def("{$name} (A-Level)", "All A-Level students and alumni of {$name}.", Group::SYS_ALEVEL_SCHOOL);

        // School + Class-of
        if ($gradYear) {
            $groups["al:{$id}:y:{$gradYear}"] = $this->def("{$name} - Class of {$gradYear}", "{$name} A-Level graduates of {$gradYear}.", Group::SYS_ALEVEL_SCHOOL_YEAR);
        }

        // School + Intake
        if ($startYear && !$gradYear) {
            $groups["al:{$id}:i:{$startYear}"] = $this->def("{$name} (A-Level) - Intake {$startYear}", "A-Level students at {$name} who started in {$startYear}.", Group::SYS_ALEVEL_SCHOOL_INTAKE);
        }

        // School + Combination (all years)
        if ($combo) {
            $groups["al:{$id}:cb:{$combo}"] = $this->def("{$name} - {$combo}", "{$combo} students at {$name}, across all years.", Group::SYS_ALEVEL_COMBINATION);

            // INTIMATE: School + Combination + Class-of
            if ($gradYear) {
                $groups["al:{$id}:cb:{$combo}:y:{$gradYear}"] = $this->def(
                    "{$name} - {$combo} - Class of {$gradYear}",
                    "{$combo} Class of {$gradYear} at {$name}. Your classmates!",
                    Group::SYS_ALEVEL_COMBINATION_YEAR
                );
            }

            // INTIMATE: School + Combination + Intake (current)
            if ($startYear && !$gradYear) {
                $groups["al:{$id}:cb:{$combo}:i:{$startYear}"] = $this->def(
                    "{$name} - {$combo} - Intake {$startYear}",
                    "{$combo} students at {$name} who started in {$startYear}. Your classmates!",
                    Group::SYS_ALEVEL_COMBINATION_INTAKE
                );
            }
        }
    }

    // ==================== POST-SECONDARY ====================

    protected function addPostSecondaryGroups(UserProfile $user, array &$groups): void
    {
        if (!$user->postsecondary_id && !$user->postsecondary_name) return;

        $id = $user->postsecondary_id ?? Str::slug($user->postsecondary_name);
        $name = $user->postsecondary_name;
        $gradYear = $user->postsecondary_graduation_year;
        $startYear = $user->postsecondary_start_year;

        // Institution broad
        $groups["pts:{$id}"] = $this->def($name, "All students and alumni of {$name}.", Group::SYS_POSTSECONDARY);

        if ($gradYear) {
            $groups["pts:{$id}:y:{$gradYear}"] = $this->def("{$name} - Class of {$gradYear}", "{$name} graduates of {$gradYear}.", Group::SYS_POSTSECONDARY_YEAR);
        }

        if ($startYear && !$gradYear) {
            $groups["pts:{$id}:i:{$startYear}"] = $this->def("{$name} - Intake {$startYear}", "{$name} students who started in {$startYear}.", Group::SYS_POSTSECONDARY_INTAKE);
        }

        // Post-secondary department + programme groups would require those IDs in the user profile.
        // Currently the profile only stores postsecondary_id/name. If department/programme IDs
        // are added to the profile later, extend this method.
    }

    // ==================== UNIVERSITY (full hierarchy) ====================

    protected function addUniversityGroups(UserProfile $user, array &$groups): void
    {
        if (!$user->university_id && !$user->university_name) return;

        $uniId = $user->university_id ?? Str::slug($user->university_name);
        $uniName = $user->university_name;
        $progId = $user->programme_id;
        $progName = $user->programme_name;
        $gradYear = $user->university_graduation_year;
        $startYear = $user->university_start_year;
        $isCurrent = $user->is_current_student || (!$gradYear);

        // --- University broad ---
        $groups["uni:{$uniId}"] = $this->def($uniName, "All students and alumni of {$uniName}.", Group::SYS_UNIVERSITY);

        if ($gradYear) {
            $groups["uni:{$uniId}:y:{$gradYear}"] = $this->def("{$uniName} - Class of {$gradYear}", "{$uniName} graduates of {$gradYear}.", Group::SYS_UNIVERSITY_YEAR);
        }
        if ($startYear && $isCurrent) {
            $groups["uni:{$uniId}:i:{$startYear}"] = $this->def("{$uniName} - Intake {$startYear}", "Students at {$uniName} who started in {$startYear}.", Group::SYS_UNIVERSITY_INTAKE);
        }

        // --- College/School (if programme is linked to a college) ---
        if ($progId && $user->university_id) {
            $programme = DB::table('university_programmes')
                ->where('id', $progId)
                ->select('college_id', 'department_id')
                ->first();

            if ($programme) {
                // College
                if ($programme->college_id) {
                    $college = DB::table('university_colleges')->find($programme->college_id);
                    if ($college) {
                        $colName = "{$uniName} - {$college->name}";
                        $groups["ucol:{$programme->college_id}"] = $this->def($colName, "All students at {$college->name}, {$uniName}.", Group::SYS_UNI_COLLEGE);

                        if ($gradYear) {
                            $groups["ucol:{$programme->college_id}:y:{$gradYear}"] = $this->def("{$colName} - Class of {$gradYear}", "{$college->name} graduates of {$gradYear}.", Group::SYS_UNI_COLLEGE_YEAR);
                        }
                        if ($startYear && $isCurrent) {
                            $groups["ucol:{$programme->college_id}:i:{$startYear}"] = $this->def("{$colName} - Intake {$startYear}", "Students at {$college->name} who started in {$startYear}.", Group::SYS_UNI_COLLEGE_INTAKE);
                        }
                    }
                }

                // Department
                if ($programme->department_id) {
                    $dept = DB::table('university_departments')->find($programme->department_id);
                    if ($dept) {
                        $deptName = "{$uniName} - {$dept->name}";
                        $groups["udpt:{$programme->department_id}"] = $this->def($deptName, "All students at {$dept->name}, {$uniName}.", Group::SYS_UNI_DEPARTMENT);

                        if ($gradYear) {
                            $groups["udpt:{$programme->department_id}:y:{$gradYear}"] = $this->def("{$deptName} - Class of {$gradYear}", "{$dept->name} graduates of {$gradYear}.", Group::SYS_UNI_DEPARTMENT_YEAR);
                        }
                        if ($startYear && $isCurrent) {
                            $groups["udpt:{$programme->department_id}:i:{$startYear}"] = $this->def("{$deptName} - Intake {$startYear}", "Students at {$dept->name} who started in {$startYear}.", Group::SYS_UNI_DEPARTMENT_INTAKE);
                        }
                    }
                }
            }
        }

        // --- Programme ---
        if ($progId && $progName) {
            $fullProg = "{$uniName} - {$progName}";
            $groups["uprg:{$progId}"] = $this->def($fullProg, "All {$progName} students at {$uniName}.", Group::SYS_UNI_PROGRAMME);

            // INTIMATE: Programme + Class-of
            if ($gradYear) {
                $groups["uprg:{$progId}:y:{$gradYear}"] = $this->def(
                    "{$fullProg} - Class of {$gradYear}",
                    "{$progName} Class of {$gradYear} at {$uniName}. Your classmates!",
                    Group::SYS_UNI_PROGRAMME_YEAR
                );
            }

            // INTIMATE: Programme + Intake (current)
            if ($startYear && $isCurrent) {
                $groups["uprg:{$progId}:i:{$startYear}"] = $this->def(
                    "{$fullProg} - Intake {$startYear}",
                    "{$progName} students at {$uniName} who started in {$startYear}. Your coursemates!",
                    Group::SYS_UNI_PROGRAMME_INTAKE
                );
            }
        }
    }

    // ==================== LOCATION ====================

    protected function addLocationGroups(UserProfile $user, array &$groups): void
    {
        if ($user->region_id) {
            $n = $user->region_name ?: "Region {$user->region_id}";
            $groups["reg:{$user->region_id}"] = $this->def($n, "People from {$n}. Connect with your community!", Group::SYS_REGION);
        }
        if ($user->district_id) {
            $n = $user->district_name ?: "District {$user->district_id}";
            $groups["dst:{$user->district_id}"] = $this->def($n, "People from {$n}. Connect with your neighbors!", Group::SYS_DISTRICT);
        }
        if ($user->ward_id) {
            $n = $user->ward_name ?: "Ward {$user->ward_id}";
            $groups["wrd:{$user->ward_id}"] = $this->def($n, "People from {$n}. Your local community!", Group::SYS_WARD);
        }
        if ($user->street_id) {
            $n = $user->street_name ?: "Street {$user->street_id}";
            $groups["str:{$user->street_id}"] = $this->def($n, "People from {$n}. Your neighbors!", Group::SYS_STREET);
        }
    }

    // ==================== EMPLOYER ====================

    protected function addEmployerGroups(UserProfile $user, array &$groups): void
    {
        if (!$user->employer_id && !$user->employer_name) return;

        $key = $user->employer_id ?? 'custom:' . Str::slug($user->employer_name);
        $name = $user->employer_name;
        $groups["emp:{$key}"] = $this->def($name, "Current and former employees of {$name}.", Group::SYS_EMPLOYER);
    }

    // ==================== HELPERS ====================

    /**
     * Shorthand to build a group definition array.
     */
    protected function def(string $name, string $description, string $type): array
    {
        return compact('name', 'description', 'type');
    }

    /**
     * Build target groups from a raw array (for comparing old profile).
     */
    public function buildTargetGroupsFromArray(array $data): array
    {
        $fakeUser = new UserProfile();
        $fakeUser->forceFill($data);
        return $this->buildTargetGroups($fakeUser);
    }

    /**
     * Find or create a system group by lookup key.
     */
    public function findOrCreateGroup(string $lookupKey, array $definition): Group
    {
        $existing = Group::findByLookupKey($lookupKey);
        if ($existing) return $existing;

        return Group::create([
            'name' => $definition['name'],
            'slug' => str_replace(':', '-', $lookupKey),
            'description' => $definition['description'],
            'privacy' => Group::PRIVACY_PUBLIC,
            'creator_id' => null,
            'is_system' => true,
            'system_group_type' => $definition['type'],
            'system_lookup_key' => $lookupKey,
            'requires_approval' => false,
            'members_count' => 0,
            'posts_count' => 0,
        ]);
    }

    /**
     * Add a user to a group if not already a member.
     * Also ensures the group has a linked conversation and adds the user to it.
     */
    public function addToGroup(Group $group, int $userId): bool
    {
        $exists = GroupMember::where('group_id', $group->id)->where('user_id', $userId)->exists();
        if ($exists) return false;

        GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $userId,
            'role' => Group::ROLE_MEMBER,
            'status' => GroupMember::STATUS_APPROVED,
            'joined_at' => now(),
        ]);

        $group->incrementMembers();

        // Ensure group has a linked conversation and add user to it
        $conversation = $this->ensureGroupConversation($group);
        if ($conversation && !ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $userId)->exists()) {
            ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
                'is_admin' => false,
            ]);
        }

        return true;
    }

    /**
     * Remove a user from a group.
     * Also removes the user from the group's linked conversation.
     */
    public function removeFromGroup(Group $group, int $userId): bool
    {
        $member = GroupMember::where('group_id', $group->id)->where('user_id', $userId)->first();
        if (!$member) return false;

        $member->delete();
        $group->decrementMembers();

        // Remove from linked conversation
        $conversation = Conversation::where('group_id', $group->id)->first();
        if ($conversation) {
            ConversationParticipant::where('conversation_id', $conversation->id)
                ->where('user_id', $userId)
                ->delete();
        }

        return true;
    }

    /**
     * Ensure a group has a linked conversation. Creates one if missing.
     */
    public function ensureGroupConversation(Group $group): Conversation
    {
        $conversation = Conversation::where('group_id', $group->id)->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'type' => Conversation::TYPE_GROUP,
                'group_id' => $group->id,
                'name' => $group->name,
                'avatar_path' => $group->cover_photo_path,
                'created_by' => $group->creator_id,
            ]);
        }

        return $conversation;
    }

    /**
     * Preview groups for a user without creating anything.
     */
    public function previewGroupsForUser(UserProfile $user): array
    {
        $targets = $this->buildTargetGroups($user);

        return collect($targets)->map(fn($def, $key) => [
            'lookup_key' => $key,
            'name' => $def['name'],
            'type' => $def['type'],
            'exists' => Group::findByLookupKey($key) !== null,
        ])->values()->toArray();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupPost;
use App\Models\GroupInvitation;
use App\Models\Post;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GroupController extends Controller
{
    /**
     * Get list of groups (discoverable).
     */
    public function index(Request $request): JsonResponse
    {
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);
        $search = $request->query('search');
        $currentUserId = $request->query('current_user_id');

        $query = Group::discoverable()
            ->with(['creator:id,first_name,last_name,username,profile_photo_path'])
            ->withCount(['approvedMembers']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        $groups = $query->orderBy('members_count', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Add membership status for current user
        if ($currentUserId) {
            foreach ($groups as $group) {
                $member = $group->members()->where('user_profiles.id', $currentUserId)->first();
                $group->membership_status = $member?->pivot?->status;
                $group->user_role = $member?->pivot?->role;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $groups->items(),
            'meta' => [
                'current_page' => $groups->currentPage(),
                'last_page' => $groups->lastPage(),
                'per_page' => $groups->perPage(),
                'total' => $groups->total(),
            ],
        ]);
    }

    /**
     * Get user's groups.
     */
    public function userGroups(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'User ID required'], 400);
        }

        $groups = Group::whereHas('members', function ($q) use ($userId) {
            $q->where('user_id', $userId)->where('status', 'approved');
        })
        ->with(['creator:id,first_name,last_name,username,profile_photo_path'])
        ->withCount(['approvedMembers'])
        ->get();

        foreach ($groups as $group) {
            $group->user_role = $group->getUserRole($userId);
        }

        return response()->json([
            'success' => true,
            'data' => $groups,
        ]);
    }

    /**
     * Create a new group.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'creator_id' => 'required|exists:user_profiles,id',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:5000',
            'privacy' => 'in:public,private,secret',
            'requires_approval' => 'boolean',
            'rules' => 'nullable|array',
            'cover_photo' => 'nullable|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $coverPath = null;
            if ($request->hasFile('cover_photo')) {
                $coverPath = $request->file('cover_photo')->store('groups/covers', 'public');
            }

            $group = Group::create([
                'name' => $request->name,
                'slug' => Str::slug($request->name) . '-' . Str::random(6),
                'description' => $request->description,
                'privacy' => $request->privacy ?? 'public',
                'creator_id' => $request->creator_id,
                'requires_approval' => $request->requires_approval ?? false,
                'rules' => $request->rules,
                'cover_photo_path' => $coverPath,
                'members_count' => 1,
            ]);

            // Add creator as admin
            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $request->creator_id,
                'role' => Group::ROLE_ADMIN,
                'status' => 'approved',
                'joined_at' => now(),
            ]);

            DB::commit();

            $group->load('creator:id,first_name,last_name,username,profile_photo_path');

            return response()->json([
                'success' => true,
                'message' => 'Group created successfully',
                'data' => $group,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create group: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single group.
     */
    public function show(string $identifier, Request $request): JsonResponse
    {
        $group = Group::where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->with(['creator:id,first_name,last_name,username,profile_photo_path'])
            ->withCount(['approvedMembers', 'pendingMembers'])
            ->first();

        if (!$group) {
            return response()->json([
                'success' => false,
                'message' => 'Group not found',
            ], 404);
        }

        $currentUserId = $request->query('current_user_id');
        if ($currentUserId) {
            $member = $group->members()->where('user_profiles.id', $currentUserId)->first();
            $group->membership_status = $member?->pivot?->status;
            $group->user_role = $member?->pivot?->role;
            $group->is_member = $group->isMember($currentUserId);
            $group->is_admin = $group->isAdmin($currentUserId);
        }

        // Check if user can view the group
        if ($group->privacy === 'secret' && !($group->is_member ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Group not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $group,
        ]);
    }

    /**
     * Update a group.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $group = Group::find($id);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:100',
            'description' => 'nullable|string|max:5000',
            'privacy' => 'in:public,private,secret',
            'requires_approval' => 'boolean',
            'rules' => 'nullable|array',
            'cover_photo' => 'nullable|image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->hasFile('cover_photo')) {
            if ($group->cover_photo_path) {
                Storage::disk('public')->delete($group->cover_photo_path);
            }
            $group->cover_photo_path = $request->file('cover_photo')->store('groups/covers', 'public');
        }

        $group->update($request->only(['name', 'description', 'privacy', 'requires_approval', 'rules']));

        return response()->json([
            'success' => true,
            'message' => 'Group updated successfully',
            'data' => $group->fresh(['creator:id,first_name,last_name,username,profile_photo_path']),
        ]);
    }

    /**
     * Delete a group.
     */
    public function destroy(int $id): JsonResponse
    {
        $group = Group::find($id);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        if ($group->cover_photo_path) {
            Storage::disk('public')->delete($group->cover_photo_path);
        }

        $group->delete();

        return response()->json([
            'success' => true,
            'message' => 'Group deleted successfully',
        ]);
    }

    /**
     * Join a group.
     */
    public function join(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $group = Group::find($id);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        // Check if already a member
        $existingMember = GroupMember::where('group_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if ($existingMember) {
            if ($existingMember->status === 'banned') {
                return response()->json(['success' => false, 'message' => 'You are banned from this group'], 403);
            }
            return response()->json(['success' => false, 'message' => 'Already a member or pending'], 400);
        }

        $status = ($group->privacy === 'public' && !$group->requires_approval) ? 'approved' : 'pending';

        GroupMember::create([
            'group_id' => $id,
            'user_id' => $request->user_id,
            'role' => Group::ROLE_MEMBER,
            'status' => $status,
            'joined_at' => $status === 'approved' ? now() : null,
        ]);

        if ($status === 'approved') {
            $group->incrementMembers();
        }

        return response()->json([
            'success' => true,
            'message' => $status === 'approved' ? 'Joined group successfully' : 'Join request sent',
            'data' => ['status' => $status],
        ]);
    }

    /**
     * Leave a group.
     */
    public function leave(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $group = Group::find($id);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        // Creator cannot leave
        if ($group->creator_id === (int)$request->user_id) {
            return response()->json(['success' => false, 'message' => 'Creator cannot leave the group'], 400);
        }

        $member = GroupMember::where('group_id', $id)
            ->where('user_id', $request->user_id)
            ->first();

        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Not a member'], 400);
        }

        $wasApproved = $member->status === 'approved';
        $member->delete();

        if ($wasApproved) {
            $group->decrementMembers();
        }

        return response()->json([
            'success' => true,
            'message' => 'Left group successfully',
        ]);
    }

    /**
     * Get group members.
     */
    public function members(Request $request, int $id): JsonResponse
    {
        $group = Group::find($id);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        $status = $request->query('status', 'approved');
        $role = $request->query('role');

        $query = $group->members()
            ->select('user_profiles.id', 'first_name', 'last_name', 'username', 'profile_photo_path')
            ->wherePivot('status', $status);

        if ($role) {
            $query->wherePivot('role', $role);
        }

        $members = $query->orderByPivot('joined_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $members,
        ]);
    }

    /**
     * Approve or reject a member request.
     */
    public function handleRequest(Request $request, int $groupId, int $userId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approve,reject',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $group = Group::find($groupId);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        $member = GroupMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->first();

        if (!$member) {
            return response()->json(['success' => false, 'message' => 'No pending request found'], 404);
        }

        if ($request->action === 'approve') {
            $member->update([
                'status' => 'approved',
                'joined_at' => now(),
            ]);
            $group->incrementMembers();
            $message = 'Member approved';
        } else {
            $member->delete();
            $message = 'Request rejected';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /**
     * Update member role.
     */
    public function updateRole(Request $request, int $groupId, int $userId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'role' => 'required|in:admin,moderator,member',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $member = GroupMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->first();

        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Member not found'], 404);
        }

        $member->update(['role' => $request->role]);

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
        ]);
    }

    /**
     * Remove a member.
     */
    public function removeMember(Request $request, int $groupId, int $userId): JsonResponse
    {
        $group = Group::find($groupId);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        if ($group->creator_id === $userId) {
            return response()->json(['success' => false, 'message' => 'Cannot remove creator'], 400);
        }

        $member = GroupMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->first();

        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Member not found'], 404);
        }

        $wasApproved = $member->status === 'approved';
        $member->delete();

        if ($wasApproved) {
            $group->decrementMembers();
        }

        return response()->json([
            'success' => true,
            'message' => 'Member removed successfully',
        ]);
    }

    /**
     * Ban a member.
     */
    public function banMember(Request $request, int $groupId, int $userId): JsonResponse
    {
        $group = Group::find($groupId);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        if ($group->creator_id === $userId) {
            return response()->json(['success' => false, 'message' => 'Cannot ban creator'], 400);
        }

        $member = GroupMember::where('group_id', $groupId)
            ->where('user_id', $userId)
            ->first();

        if (!$member) {
            return response()->json(['success' => false, 'message' => 'Member not found'], 404);
        }

        $wasApproved = $member->status === 'approved';
        $member->update(['status' => 'banned', 'role' => 'member']);

        if ($wasApproved) {
            $group->decrementMembers();
        }

        return response()->json([
            'success' => true,
            'message' => 'Member banned successfully',
        ]);
    }

    /**
     * Get group posts.
     */
    public function posts(Request $request, int $id): JsonResponse
    {
        $group = Group::find($id);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 20);

        $posts = $group->posts()
            ->with(['user:id,first_name,last_name,username,profile_photo_path', 'media'])
            ->withCount(['comments', 'likes'])
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $posts->items(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * Create a post in a group.
     */
    public function createPost(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:user_profiles,id',
            'content' => 'nullable|string|max:5000',
            'post_type' => 'in:text,photo,video',
            'media' => 'nullable|array|max:10',
            'media.*' => 'file|mimes:jpg,jpeg,png,gif,mp4,mov|max:51200',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $group = Group::find($id);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        // Check if user is a member
        if (!$group->isMember($request->user_id)) {
            return response()->json(['success' => false, 'message' => 'Must be a member to post'], 403);
        }

        try {
            DB::beginTransaction();

            // Create the post
            $post = Post::create([
                'user_id' => $request->user_id,
                'content' => $request->content,
                'post_type' => $request->post_type ?? 'text',
                'privacy' => 'public', // Group posts are visible to members
            ]);

            // Handle media uploads (simplified - full implementation in PostController)
            if ($request->hasFile('media')) {
                $order = 0;
                foreach ($request->file('media') as $file) {
                    $path = $file->store('posts/' . $post->id, 'public');
                    $post->media()->create([
                        'media_type' => str_starts_with($file->getMimeType(), 'video/') ? 'video' : 'image',
                        'file_path' => $path,
                        'original_filename' => $file->getClientOriginalName(),
                        'file_size' => $file->getSize(),
                        'order' => $order++,
                    ]);
                }
                if ($post->post_type === 'text') {
                    $post->update(['post_type' => 'photo']);
                }
            }

            // Link post to group
            GroupPost::create([
                'group_id' => $id,
                'post_id' => $post->id,
            ]);

            $group->incrementPosts();
            UserProfile::find($request->user_id)->incrementPosts();

            DB::commit();

            $post->load(['user:id,first_name,last_name,username,profile_photo_path', 'media']);

            return response()->json([
                'success' => true,
                'message' => 'Post created successfully',
                'data' => $post,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create post: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Invite users to group.
     */
    public function invite(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'inviter_id' => 'required|exists:user_profiles,id',
            'invitee_ids' => 'required|array',
            'invitee_ids.*' => 'exists:user_profiles,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $group = Group::find($id);
        if (!$group) {
            return response()->json(['success' => false, 'message' => 'Group not found'], 404);
        }

        $invited = 0;
        foreach ($request->invitee_ids as $inviteeId) {
            // Skip if already a member or has pending invitation
            if ($group->isMember($inviteeId)) continue;
            if (GroupInvitation::where('group_id', $id)->where('invitee_id', $inviteeId)->where('status', 'pending')->exists()) continue;

            GroupInvitation::create([
                'group_id' => $id,
                'inviter_id' => $request->inviter_id,
                'invitee_id' => $inviteeId,
                'status' => 'pending',
            ]);
            $invited++;
        }

        return response()->json([
            'success' => true,
            'message' => "{$invited} invitation(s) sent",
        ]);
    }

    /**
     * Respond to invitation.
     */
    public function respondToInvitation(Request $request, int $invitationId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'response' => 'required|in:accept,decline',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $invitation = GroupInvitation::where('id', $invitationId)
            ->where('status', 'pending')
            ->first();

        if (!$invitation) {
            return response()->json(['success' => false, 'message' => 'Invitation not found'], 404);
        }

        $invitation->status = $request->response === 'accept' ? 'accepted' : 'declined';
        $invitation->save();

        if ($request->response === 'accept') {
            GroupMember::create([
                'group_id' => $invitation->group_id,
                'user_id' => $invitation->invitee_id,
                'role' => Group::ROLE_MEMBER,
                'status' => 'approved',
                'joined_at' => now(),
                'invited_by' => $invitation->inviter_id,
            ]);

            $invitation->group->incrementMembers();
        }

        return response()->json([
            'success' => true,
            'message' => $request->response === 'accept' ? 'Joined group' : 'Invitation declined',
        ]);
    }

    /**
     * Get user's pending invitations.
     */
    public function userInvitations(Request $request): JsonResponse
    {
        $userId = $request->query('user_id');
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'User ID required'], 400);
        }

        $invitations = GroupInvitation::where('invitee_id', $userId)
            ->where('status', 'pending')
            ->with([
                'group:id,name,slug,cover_photo_path,members_count',
                'inviter:id,first_name,last_name,username,profile_photo_path'
            ])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $invitations,
        ]);
    }

    /**
     * Search groups.
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->query('q');
        if (!$query) {
            return response()->json(['success' => false, 'message' => 'Query required'], 400);
        }

        $groups = Group::discoverable()
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                  ->orWhere('description', 'LIKE', "%{$query}%");
            })
            ->with(['creator:id,first_name,last_name,username,profile_photo_path'])
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $groups,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserFile;
use App\Models\UserFileShare;
use App\Models\UserStorageQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends Controller
{
    /**
     * Maximum single file size: 100MB
     */
    private const MAX_FILE_SIZE = 104857600;

    /**
     * Maximum batch upload count
     */
    private const MAX_BATCH_SIZE = 10;

    /*
    |--------------------------------------------------------------------------
    | Listing & Search
    |--------------------------------------------------------------------------
    */

    /**
     * GET /files — List user's files with filtering, sorting, pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'folder_id' => 'nullable|integer',
            'path' => 'nullable|string',
            'category' => 'nullable|string|in:document,archive,other',
            'starred' => 'nullable|boolean',
            'offline' => 'nullable|boolean',
            'shared' => 'nullable|boolean',
            'search' => 'nullable|string|max:255',
            'sort_by' => 'nullable|string|in:name,size,created_at,updated_at',
            'sort_order' => 'nullable|string|in:asc,desc',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $userId = $request->input('user_id');
        $perPage = min((int) $request->input('per_page', 50), 100);
        $sortBy = $request->input('sort_by', 'updated_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $query = UserFile::forUser($userId);

        // Search across all files (ignore folder filter)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('display_name', 'ILIKE', "%{$search}%");
            });
        } else {
            // Folder-based filtering (only when not searching)
            $folderId = $request->input('folder_id');
            $path = $request->input('path');

            if ($folderId !== null && $folderId !== '') {
                $query->inFolder((int) $folderId);
            } elseif ($path !== null && $path !== '' && $path !== '/') {
                // Non-root path: find the folder with this path and list its children
                $folder = UserFile::where('user_id', $userId)
                    ->where('path', $path)
                    ->where('is_folder', true)
                    ->first();
                if ($folder) {
                    $query->where('folder_id', $folder->id);
                } else {
                    $query->whereRaw('1 = 0'); // No results for non-existent path
                }
            } else {
                // Default: root level (path=/ or no filter)
                $query->whereNull('folder_id');
            }
        }

        // Category filter
        if ($request->filled('category')) {
            $query->byCategory($request->input('category'));
        }

        // Boolean filters
        if ($request->boolean('starred')) {
            $query->starred();
        }
        if ($request->boolean('offline')) {
            $query->offline();
        }
        if ($request->boolean('shared')) {
            $query->shared();
        }

        // Sort: folders first, then by chosen field
        $query->orderByDesc('is_folder')
              ->orderBy($sortBy, $sortOrder);

        $files = $query->paginate($perPage);

        // Get quota
        $quota = UserStorageQuota::getOrCreate($userId);

        return response()->json([
            'success' => true,
            'data' => $files->items(),
            'quota' => [
                'used' => $quota->used_bytes,
                'total' => $quota->total_bytes,
                'file_count' => $quota->file_count,
                'folder_count' => $quota->folder_count,
            ],
            'meta' => [
                'current_page' => $files->currentPage(),
                'per_page' => $files->perPage(),
                'total' => $files->total(),
            ],
        ]);
    }

    /**
     * GET /files/recent — Recently accessed files.
     */
    public function recent(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $userId = $request->input('user_id');
        $limit = (int) $request->input('limit', 20);

        $files = UserFile::forUser($userId)
            ->where('is_folder', false)
            ->whereNotNull('last_accessed_at')
            ->orderByDesc('last_accessed_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $files,
        ]);
    }

    /**
     * GET /files/quota — Storage quota for user.
     */
    public function quota(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $quota = UserStorageQuota::getOrCreate($request->input('user_id'));

        return response()->json([
            'success' => true,
            'data' => [
                'used' => $quota->used_bytes,
                'total' => $quota->total_bytes,
                'file_count' => $quota->file_count,
                'folder_count' => $quota->folder_count,
            ],
        ]);
    }

    /**
     * GET /files/{id} — Single file details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $file = UserFile::find($id);

        if (!$file) {
            return $this->errorResponse('File not found', 'FILE_NOT_FOUND', 404);
        }

        // Access check: owner or shared-with
        $userId = $request->input('user_id');
        if (!$this->canAccess($file, $userId)) {
            return $this->errorResponse('Access denied', 'ACCESS_DENIED', 403);
        }

        // Update last accessed
        $file->update(['last_accessed_at' => now()]);

        $data = $file->toArray();
        if ($file->is_folder) {
            $data['item_count'] = $file->children()->count();
            $data['total_size'] = $file->children()->where('is_folder', false)->sum('size');
        }
        $data['shared_with_count'] = $file->shares()->count();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Upload
    |--------------------------------------------------------------------------
    */

    /**
     * POST /files — Upload a single file.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'file' => 'required|file|max:102400', // 100MB
            'folder_id' => 'nullable|integer|exists:user_files,id',
            'path' => 'nullable|string',
            'display_name' => 'nullable|string|max:255',
        ]);

        $userId = $request->input('user_id');
        $uploadedFile = $request->file('file');

        // Validate MIME type
        $mimeType = $uploadedFile->getMimeType();
        if (!in_array($mimeType, UserFile::allowedMimeTypes())) {
            return $this->errorResponse(
                'File type not allowed. Only documents and archives are accepted.',
                'INVALID_FILE_TYPE',
                400
            );
        }

        // Check quota
        $quota = UserStorageQuota::getOrCreate($userId);
        $fileSize = $uploadedFile->getSize();
        if (!$quota->hasSpace($fileSize)) {
            return $this->errorResponse(
                'Storage quota exceeded. You have used ' . $this->formatBytes($quota->used_bytes) . ' of ' . $this->formatBytes($quota->total_bytes) . '.',
                'QUOTA_EXCEEDED',
                403
            );
        }

        // Determine parent folder and path
        $folderId = $request->input('folder_id');
        $path = '/';
        $parentPath = null;

        if ($folderId) {
            $folder = UserFile::where('id', $folderId)
                ->where('user_id', $userId)
                ->where('is_folder', true)
                ->first();

            if (!$folder) {
                return $this->errorResponse('Parent folder not found', 'FILE_NOT_FOUND', 404);
            }
            $path = $folder->path;
            $parentPath = $folder->parent_path;
        } elseif ($request->filled('path')) {
            $path = $this->normalizePath($request->input('path'));
        }

        // Check for duplicate name in same folder
        $originalName = $uploadedFile->getClientOriginalName();
        $name = $this->getUniqueName($userId, $folderId, $originalName);

        // Store file
        $storagePath = $uploadedFile->storeAs(
            "user_files/{$userId}",
            Str::uuid() . '.' . $uploadedFile->getClientOriginalExtension(),
            'local'
        );

        $file = UserFile::create([
            'user_id' => $userId,
            'folder_id' => $folderId,
            'name' => $name,
            'display_name' => $request->input('display_name'),
            'path' => $path,
            'parent_path' => $parentPath,
            'storage_path' => $storagePath,
            'mime_type' => $mimeType,
            'size' => $fileSize,
            'is_folder' => false,
            'last_accessed_at' => now(),
        ]);

        // Update quota
        $quota->addUsage($fileSize);

        return response()->json([
            'success' => true,
            'data' => $file,
            'message' => 'File uploaded',
        ], 201);
    }

    /**
     * POST /files/batch — Upload multiple files.
     */
    public function storeBatch(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'files' => 'required|array|min:1|max:' . self::MAX_BATCH_SIZE,
            'files.*' => 'file|max:102400',
            'folder_id' => 'nullable|integer|exists:user_files,id',
            'path' => 'nullable|string',
        ]);

        $userId = $request->input('user_id');
        $uploadedFiles = $request->file('files');
        $quota = UserStorageQuota::getOrCreate($userId);

        // Calculate total size
        $totalSize = 0;
        foreach ($uploadedFiles as $f) {
            $totalSize += $f->getSize();
        }

        if (!$quota->hasSpace($totalSize)) {
            return $this->errorResponse(
                'Storage quota exceeded. Upload requires ' . $this->formatBytes($totalSize) . ' but only ' . $this->formatBytes($quota->total_bytes - $quota->used_bytes) . ' available.',
                'QUOTA_EXCEEDED',
                403
            );
        }

        // Determine parent
        $folderId = $request->input('folder_id');
        $path = '/';
        $parentPath = null;

        if ($folderId) {
            $folder = UserFile::where('id', $folderId)
                ->where('user_id', $userId)
                ->where('is_folder', true)
                ->first();

            if (!$folder) {
                return $this->errorResponse('Parent folder not found', 'FILE_NOT_FOUND', 404);
            }
            $path = $folder->path;
            $parentPath = $folder->parent_path;
        } elseif ($request->filled('path')) {
            $path = $this->normalizePath($request->input('path'));
        }

        $created = [];
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($uploadedFiles as $uploadedFile) {
                $mimeType = $uploadedFile->getMimeType();
                if (!in_array($mimeType, UserFile::allowedMimeTypes())) {
                    $errors[] = $uploadedFile->getClientOriginalName() . ': file type not allowed';
                    continue;
                }

                $originalName = $uploadedFile->getClientOriginalName();
                $name = $this->getUniqueName($userId, $folderId, $originalName);
                $fileSize = $uploadedFile->getSize();

                $storagePath = $uploadedFile->storeAs(
                    "user_files/{$userId}",
                    Str::uuid() . '.' . $uploadedFile->getClientOriginalExtension(),
                    'local'
                );

                $file = UserFile::create([
                    'user_id' => $userId,
                    'folder_id' => $folderId,
                    'name' => $name,
                    'path' => $path,
                    'parent_path' => $parentPath,
                    'storage_path' => $storagePath,
                    'mime_type' => $mimeType,
                    'size' => $fileSize,
                    'is_folder' => false,
                    'last_accessed_at' => now(),
                ]);

                $quota->addUsage($fileSize);
                $created[] = $file;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Upload failed: ' . $e->getMessage(), 'UPLOAD_FAILED', 500);
        }

        $message = count($created) . ' file(s) uploaded';
        if (count($errors) > 0) {
            $message .= ', ' . count($errors) . ' skipped';
        }

        return response()->json([
            'success' => true,
            'data' => $created,
            'errors' => $errors ?: null,
            'message' => $message,
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Folders
    |--------------------------------------------------------------------------
    */

    /**
     * POST /files/folders — Create a folder.
     */
    public function createFolder(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'name' => 'required|string|max:255',
            'parent_folder_id' => 'nullable|integer|exists:user_files,id',
            'path' => 'nullable|string',
        ]);

        $userId = $request->input('user_id');
        $folderName = $request->input('name');
        $parentFolderId = $request->input('parent_folder_id');

        // Determine path
        $parentPath = null;
        if ($parentFolderId) {
            $parentFolder = UserFile::where('id', $parentFolderId)
                ->where('user_id', $userId)
                ->where('is_folder', true)
                ->first();

            if (!$parentFolder) {
                return $this->errorResponse('Parent folder not found', 'FILE_NOT_FOUND', 404);
            }
            $parentPath = $parentFolder->path;
            $folderPath = $parentFolder->path . $folderName . '/';
        } else {
            $folderPath = '/' . $folderName . '/';
        }

        // Check for duplicate
        $exists = UserFile::where('user_id', $userId)
            ->where('folder_id', $parentFolderId)
            ->where('name', $folderName)
            ->where('is_folder', true)
            ->exists();

        if ($exists) {
            return $this->errorResponse(
                'A folder with this name already exists in this location',
                'NAME_EXISTS',
                400
            );
        }

        $folder = UserFile::create([
            'user_id' => $userId,
            'folder_id' => $parentFolderId,
            'name' => $folderName,
            'path' => $folderPath,
            'parent_path' => $parentPath,
            'storage_path' => '',
            'mime_type' => 'folder',
            'size' => 0,
            'is_folder' => true,
        ]);

        // Update quota folder count
        $quota = UserStorageQuota::getOrCreate($userId);
        $quota->increment('folder_count');

        $data = $folder->toArray();
        $data['item_count'] = 0;
        $data['total_size'] = 0;

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => 'Folder created',
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    /**
     * PATCH /files/{id}/rename — Rename a file or folder.
     */
    public function rename(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $file = UserFile::find($id);
        if (!$file) {
            return $this->errorResponse('File not found', 'FILE_NOT_FOUND', 404);
        }

        $userId = $request->input('user_id', $file->user_id);
        if ($file->user_id !== (int) $userId) {
            return $this->errorResponse('Access denied', 'ACCESS_DENIED', 403);
        }

        $newName = $request->input('name');

        // Check for duplicate name in same folder
        $exists = UserFile::where('user_id', $file->user_id)
            ->where('folder_id', $file->folder_id)
            ->where('name', $newName)
            ->where('is_folder', $file->is_folder)
            ->where('id', '!=', $file->id)
            ->exists();

        if ($exists) {
            return $this->errorResponse(
                'A file with this name already exists in this location',
                'NAME_EXISTS',
                400
            );
        }

        $oldName = $file->name;
        $file->update(['name' => $newName]);

        // If it's a folder, update path for self and all descendants
        if ($file->is_folder) {
            $oldPath = $file->path;
            $parentBase = $file->parent_path ?? '/';
            $newPath = $parentBase . $newName . '/';
            $file->update(['path' => $newPath]);

            // Update all descendants whose path starts with oldPath
            UserFile::where('user_id', $file->user_id)
                ->where('path', 'LIKE', $oldPath . '%')
                ->where('id', '!=', $file->id)
                ->each(function ($child) use ($oldPath, $newPath) {
                    $child->update([
                        'path' => str_replace($oldPath, $newPath, $child->path),
                        'parent_path' => str_replace($oldPath, $newPath, $child->parent_path ?? ''),
                    ]);
                });
        }

        return response()->json([
            'success' => true,
            'data' => $file->fresh(),
            'message' => 'Renamed successfully',
        ]);
    }

    /**
     * PATCH /files/{id}/move — Move a file or folder.
     */
    public function move(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'target_folder_id' => 'nullable|integer',
            'target_path' => 'nullable|string',
        ]);

        $file = UserFile::find($id);
        if (!$file) {
            return $this->errorResponse('File not found', 'FILE_NOT_FOUND', 404);
        }

        $userId = $request->input('user_id', $file->user_id);
        if ($file->user_id !== (int) $userId) {
            return $this->errorResponse('Access denied', 'ACCESS_DENIED', 403);
        }

        $targetFolderId = $request->input('target_folder_id');
        $targetPath = '/';
        $targetParentPath = null;

        if ($targetFolderId) {
            // Prevent moving into itself or its children
            if ($file->is_folder && $targetFolderId == $file->id) {
                return $this->errorResponse('Cannot move folder into itself', 'INVALID_PATH', 400);
            }

            $targetFolder = UserFile::where('id', $targetFolderId)
                ->where('user_id', $file->user_id)
                ->where('is_folder', true)
                ->first();

            if (!$targetFolder) {
                return $this->errorResponse('Target folder not found', 'FILE_NOT_FOUND', 404);
            }

            // Prevent moving a folder into its own descendant
            if ($file->is_folder && str_starts_with($targetFolder->path, $file->path)) {
                return $this->errorResponse('Cannot move folder into its own subfolder', 'INVALID_PATH', 400);
            }

            $targetPath = $targetFolder->path;
            $targetParentPath = $targetFolder->parent_path;
        }

        // Check for name conflict in target
        $exists = UserFile::where('user_id', $file->user_id)
            ->where('folder_id', $targetFolderId)
            ->where('name', $file->name)
            ->where('is_folder', $file->is_folder)
            ->where('id', '!=', $file->id)
            ->exists();

        if ($exists) {
            return $this->errorResponse(
                'A file with this name already exists in the target location',
                'NAME_EXISTS',
                400
            );
        }

        $oldPath = $file->path;
        $file->update([
            'folder_id' => $targetFolderId,
            'path' => $file->is_folder ? ($targetPath . $file->name . '/') : $targetPath,
            'parent_path' => $targetFolderId ? $targetPath : null,
        ]);

        // Update descendants if folder
        if ($file->is_folder) {
            $newPath = $file->fresh()->path;
            UserFile::where('user_id', $file->user_id)
                ->where('path', 'LIKE', $oldPath . '%')
                ->where('id', '!=', $file->id)
                ->each(function ($child) use ($oldPath, $newPath) {
                    $child->update([
                        'path' => str_replace($oldPath, $newPath, $child->path),
                        'parent_path' => str_replace($oldPath, $newPath, $child->parent_path ?? ''),
                    ]);
                });
        }

        return response()->json([
            'success' => true,
            'data' => $file->fresh(),
            'message' => 'Moved successfully',
        ]);
    }

    /**
     * POST /files/{id}/copy — Copy a file (not folders).
     */
    public function copy(Request $request, int $id): JsonResponse
    {
        $file = UserFile::find($id);
        if (!$file) {
            return $this->errorResponse('File not found', 'FILE_NOT_FOUND', 404);
        }

        if ($file->is_folder) {
            return $this->errorResponse('Folder copying is not supported', 'INVALID_FILE_TYPE', 400);
        }

        $userId = $request->input('user_id', $file->user_id);
        if ($file->user_id !== (int) $userId) {
            return $this->errorResponse('Access denied', 'ACCESS_DENIED', 403);
        }

        // Check quota
        $quota = UserStorageQuota::getOrCreate($file->user_id);
        if (!$quota->hasSpace($file->size)) {
            return $this->errorResponse('Storage quota exceeded', 'QUOTA_EXCEEDED', 403);
        }

        // Determine target
        $targetFolderId = $request->input('target_folder_id', $file->folder_id);
        $targetPath = $file->path;

        if ($request->filled('target_folder_id')) {
            if ($targetFolderId) {
                $targetFolder = UserFile::where('id', $targetFolderId)
                    ->where('user_id', $file->user_id)
                    ->where('is_folder', true)
                    ->first();

                if (!$targetFolder) {
                    return $this->errorResponse('Target folder not found', 'FILE_NOT_FOUND', 404);
                }
                $targetPath = $targetFolder->path;
            } else {
                $targetPath = '/';
                $targetFolderId = null;
            }
        } elseif ($request->filled('target_path')) {
            $targetPath = $this->normalizePath($request->input('target_path'));
        }

        // Copy the physical file
        $extension = pathinfo($file->storage_path, PATHINFO_EXTENSION);
        $newStoragePath = "user_files/{$file->user_id}/" . Str::uuid() . '.' . $extension;

        Storage::disk('local')->copy($file->storage_path, $newStoragePath);

        // Create new record with unique name
        $name = $this->getUniqueName($file->user_id, $targetFolderId, $file->name);

        $copy = UserFile::create([
            'user_id' => $file->user_id,
            'folder_id' => $targetFolderId,
            'name' => $name,
            'display_name' => $file->display_name ? $file->display_name . ' (copy)' : null,
            'path' => $targetPath,
            'parent_path' => $file->parent_path,
            'storage_path' => $newStoragePath,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'is_folder' => false,
            'last_accessed_at' => now(),
        ]);

        $quota->addUsage($file->size);

        return response()->json([
            'success' => true,
            'data' => $copy,
            'message' => 'File copied',
        ], 201);
    }

    /**
     * DELETE /files/{id} — Delete a file or folder (soft delete).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $file = UserFile::find($id);
        if (!$file) {
            return $this->errorResponse('File not found', 'FILE_NOT_FOUND', 404);
        }

        $userId = $request->input('user_id', $file->user_id);
        if ($file->user_id !== (int) $userId) {
            return $this->errorResponse('Access denied', 'ACCESS_DENIED', 403);
        }

        $quota = UserStorageQuota::getOrCreate($file->user_id);

        DB::beginTransaction();
        try {
            if ($file->is_folder) {
                // Recursively delete folder contents
                $this->deleteFolderContents($file, $quota);
                $quota->decrement('folder_count', min(1, $quota->folder_count));
            } else {
                // Delete physical file
                if ($file->storage_path && Storage::disk('local')->exists($file->storage_path)) {
                    Storage::disk('local')->delete($file->storage_path);
                }
                $quota->removeUsage($file->size);
            }

            // Clean up shares
            $file->shares()->delete();
            $file->delete(); // Soft delete

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Delete failed: ' . $e->getMessage(), 'DELETE_FAILED', 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Deleted successfully',
        ]);
    }

    /**
     * DELETE /files/batch — Delete multiple files.
     */
    public function destroyBatch(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'file_ids' => 'required|array|min:1',
            'file_ids.*' => 'integer',
        ]);

        $userId = (int) $request->input('user_id');
        $fileIds = $request->input('file_ids');

        $files = UserFile::whereIn('id', $fileIds)
            ->where('user_id', $userId)
            ->get();

        if ($files->isEmpty()) {
            return $this->errorResponse('No files found', 'FILE_NOT_FOUND', 404);
        }

        $quota = UserStorageQuota::getOrCreate($userId);
        $deletedCount = 0;

        DB::beginTransaction();
        try {
            foreach ($files as $file) {
                if ($file->is_folder) {
                    $this->deleteFolderContents($file, $quota);
                    $quota->decrement('folder_count', min(1, $quota->folder_count));
                } else {
                    if ($file->storage_path && Storage::disk('local')->exists($file->storage_path)) {
                        Storage::disk('local')->delete($file->storage_path);
                    }
                    $quota->removeUsage($file->size);
                }
                $file->shares()->delete();
                $file->delete();
                $deletedCount++;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Batch delete failed: ' . $e->getMessage(), 'DELETE_FAILED', 500);
        }

        return response()->json([
            'success' => true,
            'message' => $deletedCount . ' item(s) deleted',
        ]);
    }

    /**
     * PATCH /files/{id}/star — Toggle starred status.
     */
    public function toggleStar(Request $request, int $id): JsonResponse
    {
        $file = UserFile::find($id);
        if (!$file) {
            return $this->errorResponse('File not found', 'FILE_NOT_FOUND', 404);
        }

        $userId = $request->input('user_id', $file->user_id);
        if ($file->user_id !== (int) $userId) {
            return $this->errorResponse('Access denied', 'ACCESS_DENIED', 403);
        }

        $file->update(['is_starred' => !$file->is_starred]);

        return response()->json([
            'success' => true,
            'data' => $file->fresh(),
            'message' => $file->is_starred ? 'Starred' : 'Unstarred',
        ]);
    }

    /**
     * PATCH /files/{id}/offline — Toggle offline status.
     */
    public function toggleOffline(Request $request, int $id): JsonResponse
    {
        $file = UserFile::find($id);
        if (!$file) {
            return $this->errorResponse('File not found', 'FILE_NOT_FOUND', 404);
        }

        $userId = $request->input('user_id', $file->user_id);
        if ($file->user_id !== (int) $userId) {
            return $this->errorResponse('Access denied', 'ACCESS_DENIED', 403);
        }

        $file->update(['is_offline' => !$file->is_offline]);

        return response()->json([
            'success' => true,
            'data' => $file->fresh(),
            'message' => $file->is_offline ? 'Marked for offline' : 'Removed from offline',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Sharing
    |--------------------------------------------------------------------------
    */

    /**
     * POST /files/{id}/share — Share a file.
     */
    public function share(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
            'public_link' => 'nullable|boolean',
        ]);

        $file = UserFile::find($id);
        if (!$file) {
            return $this->errorResponse('File not found', 'FILE_NOT_FOUND', 404);
        }

        $userId = $request->input('user_id', $file->user_id);
        if ($file->user_id !== (int) $userId) {
            return $this->errorResponse('Access denied', 'ACCESS_DENIED', 403);
        }

        $response = ['success' => true];

        // Share with specific users
        if ($request->filled('user_ids')) {
            foreach ($request->input('user_ids') as $shareWithId) {
                if ($shareWithId == $file->user_id) {
                    continue; // Skip sharing with self
                }
                UserFileShare::updateOrCreate(
                    [
                        'file_id' => $file->id,
                        'shared_with_user_id' => $shareWithId,
                    ],
                    [
                        'shared_by_user_id' => $file->user_id,
                        'permission' => 'view',
                    ]
                );
            }
            $file->update(['is_shared' => true]);
        }

        // Generate public link
        if ($request->boolean('public_link')) {
            $token = $file->share_token ?? Str::random(64);
            $file->update([
                'share_token' => $token,
                'share_expires_at' => now()->addDays(7),
                'is_shared' => true,
            ]);
            $response['share_link'] = url("/api/files/shared/{$token}");
        }

        $response['data'] = $file->fresh();
        $response['message'] = 'File shared';

        return response()->json($response);
    }

    /**
     * DELETE /files/{id}/share — Revoke sharing.
     */
    public function revokeShare(Request $request, int $id): JsonResponse
    {
        $file = UserFile::find($id);
        if (!$file) {
            return $this->errorResponse('File not found', 'FILE_NOT_FOUND', 404);
        }

        $userId = $request->input('user_id', $file->user_id);
        if ($file->user_id !== (int) $userId) {
            return $this->errorResponse('Access denied', 'ACCESS_DENIED', 403);
        }

        // Revoke specific user shares
        if ($request->filled('user_ids')) {
            UserFileShare::where('file_id', $file->id)
                ->whereIn('shared_with_user_id', $request->input('user_ids'))
                ->delete();
        }

        // Revoke public link
        if ($request->boolean('revoke_public')) {
            $file->update([
                'share_token' => null,
                'share_expires_at' => null,
            ]);
        }

        // Check if still shared
        $stillShared = $file->shares()->exists() || $file->share_token !== null;
        $file->update(['is_shared' => $stillShared]);

        return response()->json([
            'success' => true,
            'data' => $file->fresh(),
            'message' => 'Share revoked',
        ]);
    }

    /**
     * GET /files/shared/{token} — Access shared file (public, no auth).
     */
    public function accessSharedFile(string $token): JsonResponse
    {
        $file = UserFile::where('share_token', $token)->first();

        if (!$file) {
            return $this->errorResponse('Shared file not found', 'FILE_NOT_FOUND', 404);
        }

        if ($file->share_expires_at && $file->share_expires_at->isPast()) {
            return $this->errorResponse('Share link has expired', 'SHARE_EXPIRED', 410);
        }

        $data = $file->only([
            'id', 'name', 'display_name', 'mime_type', 'size',
            'is_folder', 'created_at', 'updated_at',
        ]);
        $data['download_url'] = $file->download_url;
        $data['thumbnail_url'] = $file->thumbnail_url;
        $data['preview_url'] = $file->preview_url;

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Download
    |--------------------------------------------------------------------------
    */

    /**
     * GET /files/{id}/download — Download a file.
     */
    public function download(Request $request, int $id)
    {
        $file = UserFile::find($id);
        if (!$file) {
            return $this->errorResponse('File not found', 'FILE_NOT_FOUND', 404);
        }

        if ($file->is_folder) {
            return $this->errorResponse('Cannot download a folder', 'INVALID_FILE_TYPE', 400);
        }

        // Access check: owner, shared-with, or valid share token
        $userId = $request->input('user_id');
        if (!$this->canAccess($file, $userId)) {
            return $this->errorResponse('Access denied', 'ACCESS_DENIED', 403);
        }

        // Update last accessed
        $file->update(['last_accessed_at' => now()]);

        if (!Storage::disk('local')->exists($file->storage_path)) {
            return $this->errorResponse('File not found on storage', 'FILE_NOT_FOUND', 404);
        }

        return Storage::disk('local')->download(
            $file->storage_path,
            $file->display_name ?? $file->name,
            ['Content-Type' => $file->mime_type]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function canAccess(UserFile $file, ?int $userId): bool
    {
        if (!$userId) {
            return false;
        }

        // Owner
        if ($file->user_id === (int) $userId) {
            return true;
        }

        // Shared with user
        return UserFileShare::where('file_id', $file->id)
            ->where('shared_with_user_id', $userId)
            ->exists();
    }

    private function deleteFolderContents(UserFile $folder, UserStorageQuota $quota): void
    {
        $children = UserFile::where('folder_id', $folder->id)->get();

        foreach ($children as $child) {
            if ($child->is_folder) {
                $this->deleteFolderContents($child, $quota);
                $quota->decrement('folder_count', min(1, $quota->folder_count));
            } else {
                if ($child->storage_path && Storage::disk('local')->exists($child->storage_path)) {
                    Storage::disk('local')->delete($child->storage_path);
                }
                $quota->removeUsage($child->size);
            }
            $child->shares()->delete();
            $child->delete();
        }
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path .= '/';
        }
        return $path;
    }

    private function getUniqueName(int $userId, ?int $folderId, string $name): string
    {
        $exists = UserFile::where('user_id', $userId)
            ->where('folder_id', $folderId)
            ->where('name', $name)
            ->exists();

        if (!$exists) {
            return $name;
        }

        $pathInfo = pathinfo($name);
        $baseName = $pathInfo['filename'];
        $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
        $counter = 1;

        do {
            $newName = $baseName . " ({$counter})" . $extension;
            $counter++;
        } while (UserFile::where('user_id', $userId)
            ->where('folder_id', $folderId)
            ->where('name', $newName)
            ->exists());

        return $newName;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 2) . ' ' . $units[$i];
    }

    private function errorResponse(string $message, string $errorCode, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
        ], $status);
    }
}

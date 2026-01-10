<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PostsecondaryInstitution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PostsecondaryController extends Controller
{
    public function categories()
    {
        return response()->json([
            'success' => true,
            'data' => PostsecondaryInstitution::categoryLabels(),
        ]);
    }

    public function byCategory(string $category)
    {
        $institutions = Cache::remember("postsecondary_{$category}", 3600, function () use ($category) {
            return PostsecondaryInstitution::where('category', $category)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'acronym', 'type', 'category', 'region']);
        });

        return response()->json([
            'success' => true,
            'count' => $institutions->count(),
            'data' => $institutions,
        ]);
    }

    public function search(Request $request)
    {
        $query = $request->get('q', '');
        $category = $request->get('category');
        $limit = min($request->get('limit', 20), 100);

        if (strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Search query must be at least 2 characters',
                'data' => [],
            ]);
        }

        $institutions = PostsecondaryInstitution::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                    ->orWhere('code', 'ilike', "%{$query}%");
            })
            ->when($category, fn($q) => $q->where('category', $category))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'acronym', 'type', 'category', 'region']);

        return response()->json([
            'success' => true,
            'count' => $institutions->count(),
            'data' => $institutions,
        ]);
    }

    public function show(string $identifier)
    {
        $institution = PostsecondaryInstitution::where('id', $identifier)
            ->orWhere('code', strtoupper($identifier))
            ->first();

        if (!$institution) {
            return response()->json([
                'success' => false,
                'message' => 'Institution not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $institution,
        ]);
    }

    public function stats()
    {
        $stats = Cache::remember('postsecondary_stats', 3600, function () {
            $byCategory = PostsecondaryInstitution::selectRaw('category, COUNT(*) as count')
                ->groupBy('category')
                ->pluck('count', 'category')
                ->toArray();

            return [
                'total_institutions' => PostsecondaryInstitution::count(),
                'government' => PostsecondaryInstitution::where('type', 'government')->count(),
                'private' => PostsecondaryInstitution::where('type', 'private')->count(),
                'by_category' => $byCategory,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}

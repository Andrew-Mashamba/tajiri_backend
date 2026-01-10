<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\University;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UniversityController extends Controller
{
    public function index(Request $request)
    {
        $ownership = $request->get('ownership');
        $category = $request->get('category');

        $universities = University::query()
            ->when($ownership, fn($q) => $q->where('ownership', $ownership))
            ->when($category, fn($q) => $q->where('category', $category))
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'count' => $universities->count(),
            'data' => $universities,
        ]);
    }

    public function categories()
    {
        return response()->json([
            'success' => true,
            'data' => University::categoryLabels(),
        ]);
    }

    public function byCategory(string $category)
    {
        $universities = Cache::remember("universities_{$category}", 3600, function () use ($category) {
            return University::where('category', $category)
                ->orderBy('name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'count' => $universities->count(),
            'data' => $universities,
        ]);
    }

    public function search(Request $request)
    {
        $query = $request->get('q', '');
        $ownership = $request->get('ownership');
        $limit = min($request->get('limit', 20), 100);

        if (strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Search query must be at least 2 characters',
                'data' => [],
            ]);
        }

        $universities = University::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                    ->orWhere('acronym', 'ilike', "%{$query}%")
                    ->orWhere('code', 'ilike', "%{$query}%");
            })
            ->when($ownership, fn($q) => $q->where('ownership', $ownership))
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'count' => $universities->count(),
            'data' => $universities,
        ]);
    }

    public function show(string $identifier)
    {
        $university = University::where('id', $identifier)
            ->orWhere('code', strtoupper($identifier))
            ->orWhere('acronym', strtoupper($identifier))
            ->first();

        if (!$university) {
            return response()->json([
                'success' => false,
                'message' => 'University not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $university,
        ]);
    }

    public function stats()
    {
        $stats = Cache::remember('university_stats', 3600, function () {
            $byCategory = University::selectRaw('category, COUNT(*) as count')
                ->groupBy('category')
                ->pluck('count', 'category')
                ->toArray();

            $byRegion = University::selectRaw('region, COUNT(*) as count')
                ->groupBy('region')
                ->orderByDesc('count')
                ->pluck('count', 'region')
                ->toArray();

            return [
                'total' => University::count(),
                'public' => University::where('ownership', 'public')->count(),
                'private' => University::where('ownership', 'private')->count(),
                'by_category' => $byCategory,
                'by_region' => $byRegion,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}

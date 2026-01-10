<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BusinessController extends Controller
{
    public function index(Request $request)
    {
        $ownership = $request->get('ownership');
        $category = $request->get('category');
        $sector = $request->get('sector');
        $parentOnly = $request->boolean('parent_only', false);

        $businesses = Business::query()
            ->when($ownership, fn($q) => $q->where('ownership', $ownership))
            ->when($category, fn($q) => $q->where('category', $category))
            ->when($sector, fn($q) => $q->where('sector', $sector))
            ->when($parentOnly, fn($q) => $q->whereNull('parent'))
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'count' => $businesses->count(),
            'data' => $businesses,
        ]);
    }

    public function stats()
    {
        $stats = Cache::remember('business_stats', 3600, function () {
            $byOwnership = Business::selectRaw('ownership, COUNT(*) as count')
                ->groupBy('ownership')
                ->pluck('count', 'ownership')
                ->toArray();

            $byCategory = Business::selectRaw('category, COUNT(*) as count')
                ->groupBy('category')
                ->pluck('count', 'category')
                ->toArray();

            $bySector = Business::selectRaw('sector, COUNT(*) as count')
                ->groupBy('sector')
                ->orderByDesc('count')
                ->limit(20)
                ->pluck('count', 'sector')
                ->toArray();

            return [
                'total' => Business::count(),
                'by_ownership' => $byOwnership,
                'by_category' => $byCategory,
                'by_sector' => $bySector,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    public function sectors()
    {
        $sectors = Cache::remember('business_sectors', 3600, function () {
            return Business::selectRaw('sector, COUNT(*) as count')
                ->groupBy('sector')
                ->orderBy('sector')
                ->get()
                ->map(fn($s) => [
                    'code' => $s->sector,
                    'label' => ucwords(str_replace('_', ' ', $s->sector)),
                    'count' => $s->count,
                ]);
        });

        return response()->json([
            'success' => true,
            'data' => $sectors,
        ]);
    }

    public function categories()
    {
        return response()->json([
            'success' => true,
            'data' => Business::categoryLabels(),
        ]);
    }

    public function ownershipTypes()
    {
        return response()->json([
            'success' => true,
            'data' => Business::ownershipLabels(),
        ]);
    }

    public function byOwnership(string $ownership)
    {
        $businesses = Cache::remember("businesses_ownership_{$ownership}", 3600, function () use ($ownership) {
            return Business::where('ownership', $ownership)
                ->orderBy('name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'count' => $businesses->count(),
            'data' => $businesses,
        ]);
    }

    public function byCategory(string $category)
    {
        $businesses = Cache::remember("businesses_category_{$category}", 3600, function () use ($category) {
            return Business::where('category', $category)
                ->orderBy('name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'count' => $businesses->count(),
            'data' => $businesses,
        ]);
    }

    public function bySector(string $sector)
    {
        $businesses = Cache::remember("businesses_sector_{$sector}", 3600, function () use ($sector) {
            return Business::where('sector', $sector)
                ->orderBy('name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'count' => $businesses->count(),
            'data' => $businesses,
        ]);
    }

    public function conglomerates()
    {
        $conglomerates = Cache::remember('business_conglomerates', 3600, function () {
            return Business::where('category', 'conglomerate')
                ->with('subsidiaries')
                ->orderBy('name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'count' => $conglomerates->count(),
            'data' => $conglomerates,
        ]);
    }

    public function parastatals()
    {
        $parastatals = Cache::remember('business_parastatals', 3600, function () {
            return Business::where('category', 'parastatal')
                ->orderBy('ministry')
                ->orderBy('name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'count' => $parastatals->count(),
            'data' => $parastatals,
        ]);
    }

    public function dseCompanies()
    {
        $companies = Cache::remember('business_dse', 3600, function () {
            return Business::whereIn('category', ['dse_listed', 'dse_cross_listed'])
                ->orderBy('name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'count' => $companies->count(),
            'data' => $companies,
        ]);
    }

    public function search(Request $request)
    {
        $query = $request->get('q', '');
        $ownership = $request->get('ownership');
        $category = $request->get('category');
        $sector = $request->get('sector');
        $limit = min($request->get('limit', 20), 100);

        if (strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Search query must be at least 2 characters',
                'data' => [],
            ]);
        }

        $businesses = Business::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                    ->orWhere('acronym', 'ilike', "%{$query}%")
                    ->orWhere('code', 'ilike', "%{$query}%");
            })
            ->when($ownership, fn($q) => $q->where('ownership', $ownership))
            ->when($category, fn($q) => $q->where('category', $category))
            ->when($sector, fn($q) => $q->where('sector', $sector))
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'count' => $businesses->count(),
            'data' => $businesses,
        ]);
    }

    public function show(string $identifier)
    {
        $business = Business::where('id', $identifier)
            ->orWhere('code', strtoupper($identifier))
            ->orWhere('acronym', strtoupper($identifier))
            ->first();

        if (!$business) {
            return response()->json([
                'success' => false,
                'message' => 'Business not found',
            ], 404);
        }

        // Load subsidiaries if this is a parent company
        if ($business->category === 'conglomerate') {
            $business->load('subsidiaries');
        }

        // Load parent if this is a subsidiary
        if ($business->parent) {
            $business->load('parentCompany');
        }

        return response()->json([
            'success' => true,
            'data' => $business,
        ]);
    }
}

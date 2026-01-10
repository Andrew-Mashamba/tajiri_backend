<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SchoolController extends Controller
{
    /**
     * Get all unique regions with school counts
     */
    public function regions()
    {
        $regions = Cache::remember('school_regions', 3600, function () {
            return School::selectRaw('region, region_code, COUNT(*) as school_count')
                ->groupBy('region', 'region_code')
                ->orderBy('region')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => $regions,
        ]);
    }

    /**
     * Get districts in a region with school counts
     */
    public function districts(string $regionCode)
    {
        $districts = Cache::remember("school_districts_{$regionCode}", 3600, function () use ($regionCode) {
            return School::where('region_code', $regionCode)
                ->selectRaw('district, district_code, COUNT(*) as school_count')
                ->groupBy('district', 'district_code')
                ->orderBy('district')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => $districts,
        ]);
    }

    /**
     * Get schools in a district
     */
    public function schoolsInDistrict(string $districtCode)
    {
        $schools = Cache::remember("schools_in_district_{$districtCode}", 3600, function () use ($districtCode) {
            return School::where('district_code', $districtCode)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'type']);
        });

        return response()->json([
            'success' => true,
            'data' => $schools,
        ]);
    }

    /**
     * Search schools by name or code
     */
    public function search(Request $request)
    {
        $query = $request->get('q', '');
        $regionCode = $request->get('region_code');
        $districtCode = $request->get('district_code');
        $limit = min($request->get('limit', 20), 100);

        if (strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Search query must be at least 2 characters',
                'data' => [],
            ]);
        }

        $schools = School::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                    ->orWhere('code', 'ilike', "%{$query}%");
            })
            ->when($regionCode, fn($q) => $q->where('region_code', $regionCode))
            ->when($districtCode, fn($q) => $q->where('district_code', $districtCode))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'type', 'region', 'district']);

        return response()->json([
            'success' => true,
            'count' => $schools->count(),
            'data' => $schools,
        ]);
    }

    /**
     * Get a single school by ID or code
     */
    public function show(string $identifier)
    {
        $school = School::where('id', $identifier)
            ->orWhere('code', strtoupper($identifier))
            ->first();

        if (!$school) {
            return response()->json([
                'success' => false,
                'message' => 'School not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $school,
        ]);
    }

    /**
     * Get statistics
     */
    public function stats()
    {
        $stats = Cache::remember('school_stats', 3600, function () {
            return [
                'total_schools' => School::count(),
                'government_schools' => School::where('type', 'government')->count(),
                'private_schools' => School::where('type', 'private')->count(),
                'regions_count' => School::distinct('region_code')->count(),
                'districts_count' => School::distinct('district_code')->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}

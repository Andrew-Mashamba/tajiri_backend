<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\Region;
use App\Models\Street;
use App\Models\Ward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LocationController extends Controller
{
    /**
     * Get all regions
     */
    public function regions(): JsonResponse
    {
        $regions = Cache::remember('locations.regions', 86400, function () {
            return Region::orderBy('name')->get(['id', 'name', 'post_code']);
        });

        return response()->json([
            'success' => true,
            'data' => $regions,
        ]);
    }

    /**
     * Get districts by region
     */
    public function districts(Region $region): JsonResponse
    {
        $cacheKey = "locations.districts.{$region->id}";

        $districts = Cache::remember($cacheKey, 86400, function () use ($region) {
            return $region->districts()->orderBy('name')->get(['id', 'region_id', 'name', 'post_code']);
        });

        return response()->json([
            'success' => true,
            'data' => $districts,
        ]);
    }

    /**
     * Get wards by district
     */
    public function wards(District $district): JsonResponse
    {
        $cacheKey = "locations.wards.{$district->id}";

        $wards = Cache::remember($cacheKey, 86400, function () use ($district) {
            return $district->wards()->orderBy('name')->get(['id', 'district_id', 'name', 'post_code']);
        });

        return response()->json([
            'success' => true,
            'data' => $wards,
        ]);
    }

    /**
     * Get streets by ward
     */
    public function streets(Ward $ward): JsonResponse
    {
        $cacheKey = "locations.streets.{$ward->id}";

        $streets = Cache::remember($cacheKey, 86400, function () use ($ward) {
            return $ward->streets()->orderBy('name')->get(['id', 'ward_id', 'name']);
        });

        return response()->json([
            'success' => true,
            'data' => $streets,
        ]);
    }

    /**
     * Search locations across all levels
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->get('q', '');

        if (strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Search query must be at least 2 characters',
            ], 422);
        }

        $results = [
            'regions' => Region::where('name', 'ilike', "%{$query}%")
                ->limit(5)
                ->get(['id', 'name', 'post_code']),
            'districts' => District::with('region:id,name')
                ->where('name', 'ilike', "%{$query}%")
                ->limit(10)
                ->get(['id', 'region_id', 'name', 'post_code']),
            'wards' => Ward::with(['district:id,name,region_id', 'district.region:id,name'])
                ->where('name', 'ilike', "%{$query}%")
                ->limit(10)
                ->get(['id', 'district_id', 'name', 'post_code']),
            'streets' => Street::with([
                'ward:id,name,district_id',
                'ward.district:id,name,region_id',
                'ward.district.region:id,name'
            ])
                ->where('name', 'ilike', "%{$query}%")
                ->limit(15)
                ->get(['id', 'ward_id', 'name']),
        ];

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }

    /**
     * Get full location hierarchy (for offline caching)
     */
    public function hierarchy(): JsonResponse
    {
        $data = Cache::remember('locations.hierarchy', 86400, function () {
            return Region::with([
                'districts' => function ($q) {
                    $q->orderBy('name')->select(['id', 'region_id', 'name', 'post_code']);
                },
                'districts.wards' => function ($q) {
                    $q->orderBy('name')->select(['id', 'district_id', 'name', 'post_code']);
                },
                'districts.wards.streets' => function ($q) {
                    $q->orderBy('name')->select(['id', 'ward_id', 'name']);
                },
            ])
                ->orderBy('name')
                ->get(['id', 'name', 'post_code']);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get location by street ID (reverse lookup)
     */
    public function getFullLocation(Street $street): JsonResponse
    {
        $street->load([
            'ward:id,name,post_code,district_id',
            'ward.district:id,name,post_code,region_id',
            'ward.district.region:id,name,post_code'
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'street' => [
                    'id' => $street->id,
                    'name' => $street->name,
                ],
                'ward' => $street->ward ? [
                    'id' => $street->ward->id,
                    'name' => $street->ward->name,
                    'post_code' => $street->ward->post_code,
                ] : null,
                'district' => $street->ward?->district ? [
                    'id' => $street->ward->district->id,
                    'name' => $street->ward->district->name,
                    'post_code' => $street->ward->district->post_code,
                ] : null,
                'region' => $street->ward?->district?->region ? [
                    'id' => $street->ward->district->region->id,
                    'name' => $street->ward->district->region->name,
                    'post_code' => $street->ward->district->region->post_code,
                ] : null,
            ],
        ]);
    }
}

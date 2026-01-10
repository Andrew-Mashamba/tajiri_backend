<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SecondarySchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SecondarySchoolController extends Controller
{
    /**
     * Get all secondary schools (paginated)
     */
    public function index(Request $request)
    {
        $perPage = min($request->get('per_page', 50), 100);

        $schools = SecondarySchool::orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $schools,
        ]);
    }

    /**
     * Get regions with secondary school counts
     */
    public function regions()
    {
        $regionNames = $this->getRegionNames();

        $regions = Cache::remember('secondary_school_regions', 3600, function () use ($regionNames) {
            return SecondarySchool::select('region_code', DB::raw('COUNT(*) as school_count'))
                ->whereNotNull('region_code')
                ->groupBy('region_code')
                ->get()
                ->map(function ($item) use ($regionNames) {
                    return [
                        'region' => $regionNames[$item->region_code] ?? "Region {$item->region_code}",
                        'region_code' => $item->region_code,
                        'school_count' => $item->school_count,
                    ];
                })
                ->sortBy('region')
                ->values();
        });

        return response()->json([
            'success' => true,
            'data' => $regions,
        ]);
    }

    /**
     * Get districts in a region with secondary school counts
     */
    public function districts(string $regionCode)
    {
        // Get schools with known districts
        $knownDistricts = SecondarySchool::select('district', 'district_code', DB::raw('COUNT(*) as school_count'))
            ->where('region_code', $regionCode)
            ->whereNotNull('district')
            ->groupBy('district', 'district_code')
            ->orderBy('district')
            ->get()
            ->map(function ($item) {
                return [
                    'district' => $item->district,
                    'district_code' => $item->district_code,
                    'school_count' => $item->school_count,
                ];
            });

        // Count schools without district info
        $unknownCount = SecondarySchool::where('region_code', $regionCode)
            ->whereNull('district')
            ->count();

        // Add "Nyingine" category if there are schools without district
        if ($unknownCount > 0) {
            $knownDistricts->push([
                'district' => 'Nyingine',
                'district_code' => 'OTHER',
                'school_count' => $unknownCount,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $knownDistricts->values(),
        ]);
    }

    private function getRegionNames(): array
    {
        return [
            '01' => 'Arusha', '02' => 'Dar es Salaam', '03' => 'Dodoma',
            '04' => 'Iringa', '05' => 'Kagera', '06' => 'Kaskazini Pemba',
            '07' => 'Kaskazini Unguja', '08' => 'Kigoma', '09' => 'Kilimanjaro',
            '10' => 'Kusini Pemba', '11' => 'Kusini Unguja', '12' => 'Lindi',
            '13' => 'Mara', '14' => 'Mbeya', '15' => 'Mjini Magharibi',
            '16' => 'Morogoro', '17' => 'Mtwara', '18' => 'Mwanza',
            '19' => 'Pwani', '20' => 'Rukwa', '21' => 'Ruvuma',
            '22' => 'Shinyanga', '23' => 'Singida', '24' => 'Tabora',
            '25' => 'Tanga', '26' => 'Manyara', '27' => 'Geita',
            '28' => 'Katavi', '29' => 'Njombe', '30' => 'Simiyu', '31' => 'Songwe',
        ];
    }

    /**
     * Get schools in a district
     */
    public function schoolsInDistrict(string $districtCode)
    {
        $query = SecondarySchool::query();

        if ($districtCode === 'OTHER') {
            // Get schools without district info in the region
            // Region code should be passed as query param
            $regionCode = request()->get('region_code');
            if ($regionCode) {
                $query->where('region_code', $regionCode)->whereNull('district');
            } else {
                $query->whereNull('district');
            }
        } else {
            $query->where('district_code', $districtCode);
        }

        $schools = $query->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'region_code', 'district_code', 'region', 'district']);

        return response()->json([
            'success' => true,
            'count' => $schools->count(),
            'data' => $schools,
        ]);
    }

    /**
     * Search secondary schools by name or code
     */
    public function search(Request $request)
    {
        $query = $request->get('q', '');
        $limit = min($request->get('limit', 20), 100);

        if (strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Search query must be at least 2 characters',
                'data' => [],
            ]);
        }

        $schools = SecondarySchool::query()
            ->where(function ($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                    ->orWhere('code', 'ilike', "%{$query}%");
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'type', 'region_code', 'district_code']);

        return response()->json([
            'success' => true,
            'count' => $schools->count(),
            'data' => $schools,
        ]);
    }

    /**
     * Get a single secondary school by ID or code
     */
    public function show(string $identifier)
    {
        $school = SecondarySchool::where('id', $identifier)
            ->orWhere('code', strtoupper($identifier))
            ->first();

        if (!$school) {
            return response()->json([
                'success' => false,
                'message' => 'Secondary school not found',
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
        $stats = Cache::remember('secondary_school_stats', 3600, function () {
            return [
                'total_schools' => SecondarySchool::count(),
                'government_schools' => SecondarySchool::where('type', 'government')->count(),
                'private_schools' => SecondarySchool::where('type', 'private')->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlevelSchool;
use App\Models\AlevelCombination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AlevelSchoolController extends Controller
{
    /**
     * Get regions with A-Level school counts
     */
    public function regions()
    {
        $regionNames = $this->getRegionNames();

        $regions = Cache::remember('alevel_school_regions', 3600, function () use ($regionNames) {
            return AlevelSchool::select('region_code', DB::raw('COUNT(*) as school_count'))
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
     * Get districts in a region with A-Level school counts
     */
    public function districts(string $regionCode)
    {
        // Get schools with known districts
        $knownDistricts = AlevelSchool::select('district', 'district_code', DB::raw('COUNT(*) as school_count'))
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
        $unknownCount = AlevelSchool::where('region_code', $regionCode)
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
        $query = AlevelSchool::query();

        if ($districtCode === 'OTHER') {
            // Get schools without district info in the region
            $regionCode = request()->get('region_code');
            if ($regionCode) {
                $query->where('region_code', $regionCode)->whereNull('district');
            } else {
                $query->whereNull('district');
            }
        } else {
            $query->where('district_code', $districtCode);
        }

        $schools = $query->with('combinations:id,code,name,category')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'region_code', 'district_code', 'region', 'district']);

        return response()->json([
            'success' => true,
            'count' => $schools->count(),
            'data' => $schools->map(function ($school) {
                return [
                    'id' => $school->id,
                    'code' => $school->code,
                    'name' => $school->name,
                    'type' => $school->type,
                    'region_code' => $school->region_code,
                    'district_code' => $school->district_code,
                    'region' => $school->region,
                    'district' => $school->district,
                    'combinations' => $school->combinations->pluck('code'),
                ];
            }),
        ]);
    }

    /**
     * Get all A-Level combinations
     */
    public function combinations()
    {
        $combinations = Cache::remember('alevel_combinations', 3600, function () {
            return AlevelCombination::with('subjects:id,code,name')
                ->orderBy('code')
                ->get()
                ->map(function ($combo) {
                    return [
                        'id' => $combo->id,
                        'code' => $combo->code,
                        'name' => $combo->name,
                        'category' => $combo->category,
                        'popularity' => $combo->popularity,
                        'subjects' => $combo->subjects->pluck('code'),
                        'careers' => $combo->careers,
                    ];
                });
        });

        return response()->json([
            'success' => true,
            'count' => $combinations->count(),
            'data' => $combinations,
        ]);
    }

    /**
     * Get combinations offered by a specific school
     */
    public function schoolCombinations(int $schoolId)
    {
        $school = AlevelSchool::with('combinations.subjects:id,code,name')->find($schoolId);

        if (!$school) {
            return response()->json([
                'success' => false,
                'message' => 'School not found',
            ], 404);
        }

        $combinations = $school->combinations->map(function ($combo) {
            return [
                'id' => $combo->id,
                'code' => $combo->code,
                'name' => $combo->name,
                'category' => $combo->category,
                'popularity' => $combo->popularity,
                'subjects' => $combo->subjects->pluck('code'),
                'careers' => $combo->careers,
            ];
        });

        return response()->json([
            'success' => true,
            'school' => $school->name,
            'count' => $combinations->count(),
            'data' => $combinations,
        ]);
    }

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

        $schools = AlevelSchool::search($query)
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'type', 'region', 'district']);

        return response()->json([
            'success' => true,
            'count' => $schools->count(),
            'data' => $schools,
        ]);
    }

    public function show(string $identifier)
    {
        $school = AlevelSchool::where('id', $identifier)
            ->orWhere('code', strtoupper($identifier))
            ->first();

        if (!$school) {
            return response()->json([
                'success' => false,
                'message' => 'A-Level school not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $school,
        ]);
    }

    public function stats()
    {
        $stats = Cache::remember('alevel_school_stats', 3600, function () {
            return [
                'total_schools' => AlevelSchool::count(),
                'government_schools' => AlevelSchool::where('type', 'government')->count(),
                'private_schools' => AlevelSchool::where('type', 'private')->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}

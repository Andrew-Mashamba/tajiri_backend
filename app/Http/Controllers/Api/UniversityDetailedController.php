<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UniversityDetailed;
use App\Models\UniversityCollege;
use App\Models\UniversityDepartment;
use App\Models\UniversityProgramme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UniversityDetailedController extends Controller
{
    /**
     * List all universities
     */
    public function index(Request $request)
    {
        $type = $request->get('type');

        $universities = Cache::remember("universities_detailed_{$type}", 3600, function () use ($type) {
            return UniversityDetailed::query()
                ->when($type, fn($q) => $q->where('type', $type))
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'acronym', 'type', 'region']);
        });

        return response()->json([
            'success' => true,
            'count' => $universities->count(),
            'data' => $universities,
        ]);
    }

    /**
     * Get university types
     */
    public function types()
    {
        return response()->json([
            'success' => true,
            'data' => UniversityDetailed::typeLabels(),
        ]);
    }

    /**
     * Get a single university with all details
     */
    public function show(int $id)
    {
        $university = UniversityDetailed::with(['campuses', 'colleges'])
            ->find($id);

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

    /**
     * Get colleges/schools for a university
     */
    public function colleges(int $universityId)
    {
        $colleges = UniversityCollege::where('university_id', $universityId)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'university_id']);

        return response()->json([
            'success' => true,
            'count' => $colleges->count(),
            'data' => $colleges,
        ]);
    }

    /**
     * Get departments for a college
     */
    public function departments(int $collegeId)
    {
        $departments = UniversityDepartment::where('college_id', $collegeId)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'college_id']);

        return response()->json([
            'success' => true,
            'count' => $departments->count(),
            'data' => $departments,
        ]);
    }

    /**
     * Get programmes for a department
     */
    public function programmesByDepartment(int $departmentId)
    {
        $programmes = UniversityProgramme::where('department_id', $departmentId)
            ->orderBy('level_code')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'level_code', 'duration', 'department_id', 'university_id']);

        return response()->json([
            'success' => true,
            'count' => $programmes->count(),
            'data' => $programmes,
        ]);
    }

    /**
     * Get programmes for a college (direct, not through department)
     */
    public function programmesByCollege(int $collegeId)
    {
        $programmes = UniversityProgramme::where('college_id', $collegeId)
            ->whereNull('department_id')
            ->orderBy('level_code')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'level_code', 'duration', 'college_id', 'university_id']);

        return response()->json([
            'success' => true,
            'count' => $programmes->count(),
            'data' => $programmes,
        ]);
    }

    /**
     * Get all programmes for a university (flat list)
     */
    public function programmesByUniversity(int $universityId)
    {
        $programmes = UniversityProgramme::where('university_id', $universityId)
            ->with(['department:id,name,college_id', 'department.college:id,name'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'count' => $programmes->count(),
            'data' => $programmes->map(function ($p) {
                return [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'level_code' => $p->level_code,
                    'duration' => $p->duration,
                    'department' => $p->department?->name,
                    'college' => $p->department?->college?->name ?? $p->college?->name,
                ];
            }),
        ]);
    }

    /**
     * Search universities
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

        $universities = UniversityDetailed::search($query)
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'acronym', 'type', 'region']);

        return response()->json([
            'success' => true,
            'count' => $universities->count(),
            'data' => $universities,
        ]);
    }

    /**
     * Search programmes across all universities
     */
    public function searchProgrammes(Request $request)
    {
        $query = $request->get('q', '');
        $level = $request->get('level');
        $limit = min($request->get('limit', 30), 100);

        if (strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Search query must be at least 2 characters',
                'data' => [],
            ]);
        }

        $programmes = UniversityProgramme::query()
            ->where('name', 'ilike', "%{$query}%")
            ->when($level, fn($q) => $q->where('level_code', $level))
            ->with(['university:id,code,name,acronym', 'department:id,name,college_id', 'department.college:id,name'])
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'count' => $programmes->count(),
            'data' => $programmes->map(function ($p) {
                return [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'level_code' => $p->level_code,
                    'duration' => $p->duration,
                    'university_id' => $p->university_id,
                    'university' => $p->university?->acronym ?? $p->university?->name,
                    'department' => $p->department?->name,
                    'college' => $p->department?->college?->name,
                ];
            }),
        ]);
    }

    /**
     * Get degree levels
     */
    public function degreeLevels()
    {
        return response()->json([
            'success' => true,
            'data' => [
                ['code' => 'CERT', 'name' => 'Certificate', 'duration' => 1],
                ['code' => 'DIP', 'name' => 'Diploma', 'duration' => 2],
                ['code' => 'ADV_DIP', 'name' => 'Advanced Diploma', 'duration' => 3],
                ['code' => 'BSC', 'name' => "Bachelor's Degree", 'duration' => 3],
                ['code' => 'BENG', 'name' => 'Bachelor of Engineering', 'duration' => 4],
                ['code' => 'MD', 'name' => 'Doctor of Medicine', 'duration' => 5],
                ['code' => 'PGD', 'name' => 'Postgraduate Diploma', 'duration' => 1],
                ['code' => 'MSC', 'name' => "Master's Degree", 'duration' => 2],
                ['code' => 'PHD', 'name' => 'Doctor of Philosophy', 'duration' => 3],
            ],
        ]);
    }

    /**
     * Stats
     */
    public function stats()
    {
        $stats = Cache::remember('universities_detailed_stats', 3600, function () {
            return [
                'universities' => UniversityDetailed::count(),
                'colleges' => UniversityCollege::count(),
                'departments' => UniversityDepartment::count(),
                'programmes' => UniversityProgramme::count(),
                'by_type' => UniversityDetailed::selectRaw('type, COUNT(*) as count')
                    ->groupBy('type')
                    ->pluck('count', 'type'),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}

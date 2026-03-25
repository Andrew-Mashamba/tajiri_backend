<?php

namespace App\Console\Commands;

use App\Models\Group;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pre-create ALL system groups from the institution & location databases.
 *
 * Creates broad + "Class of YYYY" + "Intake YYYY" groups for every entity
 * at every hierarchy level.
 *
 * Usage:
 *   php artisan groups:seed --all                    # Everything
 *   php artisan groups:seed --category=primary       # Just primary schools
 *   php artisan groups:seed --category=university    # Just university hierarchy
 *   php artisan groups:seed --dry-run                # Count without creating
 *   php artisan groups:seed --clean --all            # Wipe and re-create
 */
class SeedDefaultGroups extends Command
{
    protected $signature = 'groups:seed
        {--all : Seed all categories}
        {--category= : primary|secondary|alevel|postsecondary|university|location}
        {--clean : Delete ALL existing system groups before seeding}
        {--dry-run : Show counts without inserting}
        {--class-from=1950 : Start year for Class-of groups}
        {--class-to=2050 : End year for Class-of groups}
        {--intake-from=2015 : Start year for Intake groups}
        {--intake-to=2050 : End year for Intake groups}
        {--batch=2000 : Rows per INSERT batch}';

    protected $description = 'Pre-create system groups from school, university, and location databases';

    protected int $batchSize;
    protected array $classYears;
    protected array $intakeYears;
    protected bool $dryRun;
    protected string $now;

    // Reusable batch buffer
    protected array $batch = [];
    protected int $insertedCount = 0;

    public function handle(): int
    {
        $this->batchSize = (int) $this->option('batch');
        $this->dryRun = (bool) $this->option('dry-run');
        $this->now = now()->toDateTimeString();

        $classFrom = (int) $this->option('class-from');
        $classTo = (int) $this->option('class-to');
        $intakeFrom = (int) $this->option('intake-from');
        $intakeTo = (int) $this->option('intake-to');

        $this->classYears = range($classFrom, $classTo);
        $this->intakeYears = range($intakeFrom, $intakeTo);

        $category = $this->option('category');
        $all = $this->option('all');

        if (!$all && !$category) {
            $this->error('Specify --all or --category=<name>');
            return self::FAILURE;
        }

        // Clean if requested
        if ($this->option('clean') && !$this->dryRun) {
            $this->clean($category ?: 'all');
        }

        $categories = $all
            ? ['primary', 'secondary', 'alevel', 'postsecondary', 'university', 'location']
            : [$category];

        $totalGroups = 0;

        foreach ($categories as $cat) {
            $count = match ($cat) {
                'primary'       => $this->seedPrimary(),
                'secondary'     => $this->seedSecondary(),
                'alevel'        => $this->seedAlevel(),
                'postsecondary' => $this->seedPostSecondary(),
                'university'    => $this->seedUniversity(),
                'location'      => $this->seedLocation(),
                default         => $this->error("Unknown category: {$cat}") ?? 0,
            };
            $totalGroups += $count;
        }

        $this->newLine();
        $this->info($this->dryRun ? "DRY RUN — would create {$totalGroups} groups." : "Done! Created {$totalGroups} groups.");

        $total = Group::where('is_system', true)->count();
        $this->info("Total system groups in database: {$total}");

        return self::SUCCESS;
    }

    // ====================================================================
    //  CLEAN
    // ====================================================================

    protected function clean(string $category): void
    {
        $this->warn('Cleaning existing system groups...');

        if ($category === 'all') {
            $memberCount = DB::table('group_members')
                ->whereIn('group_id', DB::table('groups')->where('is_system', true)->select('id'))
                ->delete();
            $groupCount = DB::table('groups')->where('is_system', true)->delete();
        } else {
            $prefix = $this->getCategoryPrefix($category);
            $groupIds = DB::table('groups')
                ->where('is_system', true)
                ->where('system_lookup_key', 'LIKE', "{$prefix}%")
                ->pluck('id');
            $memberCount = DB::table('group_members')->whereIn('group_id', $groupIds)->delete();
            $groupCount = DB::table('groups')->whereIn('id', $groupIds)->delete();
        }

        $this->info("  Removed {$groupCount} groups, {$memberCount} memberships.");
    }

    protected function getCategoryPrefix(string $category): string
    {
        return match ($category) {
            'primary'       => 'ps:',
            'secondary'     => 'ss:',
            'alevel'        => 'al:',
            'postsecondary' => 'pts:',
            'university'    => 'uni:,ucol:,udpt:,uprg:', // handled specially
            'location'      => 'reg:,dst:,wrd:,str:',
            default         => '',
        };
    }

    // ====================================================================
    //  PRIMARY SCHOOLS
    // ====================================================================

    protected function seedPrimary(): int
    {
        $this->insertedCount = 0;
        $this->batch = [];

        $schools = DB::table('schools')->select('id', 'name')->orderBy('id')->get();
        $total = $schools->count();

        $this->info("Primary Schools: {$total} schools × " . (1 + count($this->classYears) + count($this->intakeYears)) . " groups each");
        $bar = $this->output->createProgressBar($total);

        foreach ($schools as $school) {
            $this->addEntityGroups(
                "ps:{$school->id}",
                $school->name,
                Group::SYS_PRIMARY_SCHOOL,
                Group::SYS_PRIMARY_SCHOOL_YEAR,
                Group::SYS_PRIMARY_SCHOOL_INTAKE,
            );
            $bar->advance();
        }

        $this->flushBatch();
        $bar->finish();
        $this->newLine();
        $this->info("  → {$this->insertedCount} groups");
        return $this->insertedCount;
    }

    // ====================================================================
    //  SECONDARY SCHOOLS
    // ====================================================================

    protected function seedSecondary(): int
    {
        $this->insertedCount = 0;
        $this->batch = [];

        $schools = DB::table('secondary_schools')->select('id', 'name')->orderBy('id')->get();
        $total = $schools->count();

        $this->info("Secondary Schools: {$total} schools × " . (1 + count($this->classYears) + count($this->intakeYears)) . " groups each");
        $bar = $this->output->createProgressBar($total);

        foreach ($schools as $school) {
            $this->addEntityGroups(
                "ss:{$school->id}",
                $school->name,
                Group::SYS_SECONDARY_SCHOOL,
                Group::SYS_SECONDARY_SCHOOL_YEAR,
                Group::SYS_SECONDARY_SCHOOL_INTAKE,
            );
            $bar->advance();
        }

        $this->flushBatch();
        $bar->finish();
        $this->newLine();
        $this->info("  → {$this->insertedCount} groups");
        return $this->insertedCount;
    }

    // ====================================================================
    //  A-LEVEL SCHOOLS + COMBINATIONS
    // ====================================================================

    protected function seedAlevel(): int
    {
        $this->insertedCount = 0;
        $this->batch = [];

        // --- School-level groups ---
        $schools = DB::table('alevel_schools')->select('id', 'name')->orderBy('id')->get();
        $this->info("A-Level Schools: {$schools->count()} schools");
        $bar = $this->output->createProgressBar($schools->count());

        foreach ($schools as $school) {
            $this->addEntityGroups(
                "al:{$school->id}",
                "{$school->name} (A-Level)",
                Group::SYS_ALEVEL_SCHOOL,
                Group::SYS_ALEVEL_SCHOOL_YEAR,
                Group::SYS_ALEVEL_SCHOOL_INTAKE,
            );
            $bar->advance();
        }
        $this->flushBatch();
        $bar->finish();
        $this->newLine();

        // --- School + Combination groups ---
        $combos = DB::table('alevel_school_combinations as asc2')
            ->join('alevel_schools as s', 's.id', '=', 'asc2.school_id')
            ->join('alevel_combinations as c', 'c.id', '=', 'asc2.combination_id')
            ->select('asc2.school_id', 's.name as school_name', 'c.code as combo_code')
            ->orderBy('asc2.school_id')
            ->get();

        $this->info("A-Level School+Combination: {$combos->count()} links");
        $bar = $this->output->createProgressBar($combos->count());

        foreach ($combos as $sc) {
            $this->addEntityGroups(
                "al:{$sc->school_id}:cb:{$sc->combo_code}",
                "{$sc->school_name} - {$sc->combo_code}",
                Group::SYS_ALEVEL_COMBINATION,
                Group::SYS_ALEVEL_COMBINATION_YEAR,
                Group::SYS_ALEVEL_COMBINATION_INTAKE,
            );
            $bar->advance();
        }

        $this->flushBatch();
        $bar->finish();
        $this->newLine();
        $this->info("  → {$this->insertedCount} groups");
        return $this->insertedCount;
    }

    // ====================================================================
    //  POST-SECONDARY
    // ====================================================================

    protected function seedPostSecondary(): int
    {
        $this->insertedCount = 0;
        $this->batch = [];

        // --- Institution-level ---
        $institutions = DB::table('postsecondary_institutions')->select('id', 'name')->orderBy('id')->get();
        $this->info("Post-Secondary Institutions: {$institutions->count()}");
        $bar = $this->output->createProgressBar($institutions->count());

        foreach ($institutions as $inst) {
            $this->addEntityGroups(
                "pts:{$inst->id}",
                $inst->name,
                Group::SYS_POSTSECONDARY,
                Group::SYS_POSTSECONDARY_YEAR,
                Group::SYS_POSTSECONDARY_INTAKE,
            );
            $bar->advance();
        }
        $this->flushBatch();
        $bar->finish();
        $this->newLine();

        // --- Department-level ---
        $departments = DB::table('postsecondary_departments as d')
            ->join('postsecondary_institutions as i', 'i.id', '=', 'd.institution_id')
            ->select('d.id', 'd.name', 'i.name as inst_name')
            ->orderBy('d.id')
            ->get();

        $this->info("Post-Secondary Departments: {$departments->count()}");
        $bar = $this->output->createProgressBar($departments->count());

        foreach ($departments as $dept) {
            $this->addEntityGroups(
                "ptsd:{$dept->id}",
                "{$dept->inst_name} - {$dept->name}",
                Group::SYS_POSTSEC_DEPT,
                Group::SYS_POSTSEC_DEPT_YEAR,
                Group::SYS_POSTSEC_DEPT_INTAKE,
            );
            $bar->advance();
        }
        $this->flushBatch();
        $bar->finish();
        $this->newLine();

        // --- Programme-level ---
        $programmes = DB::table('postsecondary_programmes as p')
            ->join('postsecondary_institutions as i', 'i.id', '=', 'p.institution_id')
            ->select('p.id', 'p.name', 'i.name as inst_name')
            ->orderBy('p.id')
            ->get();

        $this->info("Post-Secondary Programmes: {$programmes->count()}");
        $bar = $this->output->createProgressBar($programmes->count());

        foreach ($programmes as $prog) {
            $this->addEntityGroups(
                "ptsp:{$prog->id}",
                "{$prog->inst_name} - {$prog->name}",
                Group::SYS_POSTSEC_PROG,
                Group::SYS_POSTSEC_PROG_YEAR,
                Group::SYS_POSTSEC_PROG_INTAKE,
            );
            $bar->advance();
        }
        $this->flushBatch();
        $bar->finish();
        $this->newLine();
        $this->info("  → {$this->insertedCount} groups");
        return $this->insertedCount;
    }

    // ====================================================================
    //  UNIVERSITY (full hierarchy: University → College → Department → Programme)
    // ====================================================================

    protected function seedUniversity(): int
    {
        $this->insertedCount = 0;
        $this->batch = [];

        // --- University-level ---
        $universities = DB::table('universities')->select('id', 'name', 'acronym')->orderBy('id')->get();
        $this->info("Universities: {$universities->count()}");
        $bar = $this->output->createProgressBar($universities->count());

        foreach ($universities as $uni) {
            $this->addEntityGroups(
                "uni:{$uni->id}",
                $uni->name,
                Group::SYS_UNIVERSITY,
                Group::SYS_UNIVERSITY_YEAR,
                Group::SYS_UNIVERSITY_INTAKE,
            );
            $bar->advance();
        }
        $this->flushBatch();
        $bar->finish();
        $this->newLine();

        // Build uni name lookup
        $uniNames = $universities->pluck('name', 'id')->toArray();
        $uniAcronyms = $universities->pluck('acronym', 'id')->toArray();

        // --- College-level ---
        $colleges = DB::table('university_colleges')->select('id', 'name', 'university_id')->orderBy('id')->get();
        $this->info("University Colleges: {$colleges->count()}");
        $bar = $this->output->createProgressBar($colleges->count());

        foreach ($colleges as $col) {
            $uniLabel = $uniAcronyms[$col->university_id] ?? $uniNames[$col->university_id] ?? "Uni {$col->university_id}";
            $this->addEntityGroups(
                "ucol:{$col->id}",
                "{$uniLabel} - {$col->name}",
                Group::SYS_UNI_COLLEGE,
                Group::SYS_UNI_COLLEGE_YEAR,
                Group::SYS_UNI_COLLEGE_INTAKE,
            );
            $bar->advance();
        }
        $this->flushBatch();
        $bar->finish();
        $this->newLine();

        // --- Department-level ---
        // Departments reference college_id; get uni via college
        $colUniMap = $colleges->pluck('university_id', 'id')->toArray();

        $departments = DB::table('university_departments')->select('id', 'name', 'college_id')->orderBy('id')->get();
        $this->info("University Departments: {$departments->count()}");
        $bar = $this->output->createProgressBar($departments->count());

        foreach ($departments as $dept) {
            $uniId = $colUniMap[$dept->college_id] ?? null;
            $uniLabel = $uniId ? ($uniAcronyms[$uniId] ?? $uniNames[$uniId] ?? "Uni") : "Uni";
            $this->addEntityGroups(
                "udpt:{$dept->id}",
                "{$uniLabel} - {$dept->name}",
                Group::SYS_UNI_DEPARTMENT,
                Group::SYS_UNI_DEPARTMENT_YEAR,
                Group::SYS_UNI_DEPARTMENT_INTAKE,
            );
            $bar->advance();
        }
        $this->flushBatch();
        $bar->finish();
        $this->newLine();

        // --- Programme-level ---
        $programmes = DB::table('university_programmes as p')
            ->join('universities as u', 'u.id', '=', 'p.university_id')
            ->select('p.id', 'p.name', 'u.name as uni_name', 'u.acronym')
            ->orderBy('p.id')
            ->get();

        $this->info("University Programmes: {$programmes->count()}");
        $bar = $this->output->createProgressBar($programmes->count());

        foreach ($programmes as $prog) {
            $uniLabel = $prog->acronym ?: $prog->uni_name;
            $this->addEntityGroups(
                "uprg:{$prog->id}",
                "{$uniLabel} - {$prog->name}",
                Group::SYS_UNI_PROGRAMME,
                Group::SYS_UNI_PROGRAMME_YEAR,
                Group::SYS_UNI_PROGRAMME_INTAKE,
            );
            $bar->advance();
        }
        $this->flushBatch();
        $bar->finish();
        $this->newLine();
        $this->info("  → {$this->insertedCount} groups");
        return $this->insertedCount;
    }

    // ====================================================================
    //  LOCATION (no year variants)
    // ====================================================================

    protected function seedLocation(): int
    {
        $this->insertedCount = 0;
        $this->batch = [];

        // Regions
        $regions = DB::table('regions')->select('id', 'name')->orderBy('id')->get();
        $this->info("Regions: {$regions->count()}");
        foreach ($regions as $r) {
            $this->addRow("reg:{$r->id}", $r->name, "People from {$r->name}.", Group::SYS_REGION);
        }
        $this->flushBatch();

        // Districts
        $districts = DB::table('districts')->select('id', 'name')->orderBy('id')->get();
        $this->info("Districts: {$districts->count()}");
        foreach ($districts as $d) {
            $this->addRow("dst:{$d->id}", $d->name, "People from {$d->name}.", Group::SYS_DISTRICT);
        }
        $this->flushBatch();

        // Wards
        $wards = DB::table('wards')->select('id', 'name')->orderBy('id')->get();
        $this->info("Wards: {$wards->count()}");
        foreach ($wards as $w) {
            $this->addRow("wrd:{$w->id}", $w->name, "People from {$w->name}.", Group::SYS_WARD);
        }
        $this->flushBatch();

        // Streets
        $streets = DB::table('streets')->select('id', 'name')->orderBy('id')->get();
        $this->info("Streets: {$streets->count()}");
        foreach ($streets as $s) {
            $this->addRow("str:{$s->id}", $s->name, "People from {$s->name}.", Group::SYS_STREET);
        }
        $this->flushBatch();

        $this->info("  → {$this->insertedCount} groups");
        return $this->insertedCount;
    }

    // ====================================================================
    //  CORE HELPERS
    // ====================================================================

    /**
     * Add broad + class-of + intake groups for one entity.
     */
    protected function addEntityGroups(
        string $baseKey,
        string $name,
        string $broadType,
        string $yearType,
        string $intakeType,
    ): void {
        // Broad group
        $this->addRow($baseKey, $name, "All students and alumni of {$name}.", $broadType);

        // Class-of groups
        foreach ($this->classYears as $year) {
            $this->addRow(
                "{$baseKey}:y:{$year}",
                "{$name} - Class of {$year}",
                "{$name} graduates of {$year}.",
                $yearType,
            );
        }

        // Intake groups
        foreach ($this->intakeYears as $year) {
            $this->addRow(
                "{$baseKey}:i:{$year}",
                "{$name} - Intake {$year}",
                "{$name} students who started in {$year}.",
                $intakeType,
            );
        }
    }

    /**
     * Add one group row to the batch buffer.
     */
    protected function addRow(string $lookupKey, string $name, string $description, string $type): void
    {
        $this->batch[] = [
            'name'              => Str::limit($name, 250, ''),
            'slug'              => str_replace(':', '-', $lookupKey),
            'description'       => Str::limit($description, 500, ''),
            'privacy'           => 'public',
            'creator_id'        => null,
            'members_count'     => 0,
            'posts_count'       => 0,
            'requires_approval' => false,
            'is_system'         => true,
            'system_group_type' => $type,
            'system_lookup_key' => $lookupKey,
            'created_at'        => $this->now,
            'updated_at'        => $this->now,
        ];

        if (count($this->batch) >= $this->batchSize) {
            $this->flushBatch();
        }
    }

    /**
     * Flush the batch buffer to the database.
     */
    protected function flushBatch(): void
    {
        if (empty($this->batch)) return;

        if (!$this->dryRun) {
            // Use upsert to skip existing groups (by unique system_lookup_key)
            DB::table('groups')->upsert(
                $this->batch,
                ['system_lookup_key'],
                ['system_lookup_key'] // no-op update — just skip duplicates
            );
        }

        $this->insertedCount += count($this->batch);
        $this->batch = [];
    }
}

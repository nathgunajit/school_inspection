<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\Notice;
use App\Models\School;
use App\Models\User;
use App\Services\TestDataCleaner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** 25-11-2026: the State Administrator cleans the test inspection data (tables + SQL shown first). */
class TestDataCleanerTest extends TestCase
{
    /** An inspection with an answer and a photo file, a plan with a stop and a Today's Schools row. */
    private function seedTestData(): array
    {
        Storage::fake('private');
        $cluster = Cluster::orderBy('id')->first();
        $crcc = $this->officer(UserLevel::Cluster, $cluster);
        $school = $this->schoolIn($cluster);
        $insp = $this->startInspection($crcc, $school);
        $now = now();

        DB::table('inspection_answers')->insert(['inspection_id' => $insp->id, 'step' => 1, 'question_code' => 'CND_01', 'value' => json_encode('yes'), 'created_at' => $now, 'updated_at' => $now]);
        Storage::disk('private')->put('evidence/test-photo.jpg', 'jpeg');
        DB::table('inspection_evidence')->insert(['evidence_uid' => (string) Str::uuid(), 'inspection_id' => $insp->id, 'storage_path' => 'evidence/test-photo.jpg',
            'status' => 'uploaded', 'uploaded_by' => $crcc->id, 'created_at' => $now, 'updated_at' => $now]);
        $sid = DB::table('inspection_schedules')->insertGetId(['officer_id' => $crcc->id, 'schedule_date' => today()->addDay()->toDateString(), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('inspection_schedule_items')->insert(['schedule_id' => $sid, 'school_id' => $school->id, 'schedule_date' => today()->addDay()->toDateString(), 'sequence' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('daily_school_list_days')->insertOrIgnore(['officer_id' => $crcc->id, 'list_date' => today()->addDays(2)->toDateString(), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('daily_school_lists')->insert(['officer_id' => $crcc->id, 'list_date' => today()->addDays(2)->toDateString(), 'school_id' => $school->id,
            'reason' => 'manual', 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now]);

        return [$crcc, $insp];
    }

    public function test_state_admin_sees_the_tables_and_sql_then_cleans_everything(): void
    {
        [$crcc] = $this->seedTestData();
        $admin = $this->admin();
        Notice::create(['type' => 'order', 'title' => 'Kept notice', 'body' => 'Body', 'notice_date' => today()->toDateString(), 'levels' => ['cluster'],
            'status' => 'published', 'created_by' => $admin->id]);
        $users = User::count();
        $schools = School::count();

        $this->actingAs($admin)->get(route('dashboard'))->assertSee(route('admin.test-data.index'), false);
        $page = $this->get(route('admin.test-data.index'))->assertOk()
            ->assertSee('DELETE FROM inspection_answers;')->assertSee(TestDataCleaner::PRE_SQL)->assertSee('Clean test data');
        foreach (array_keys(TestDataCleaner::TABLES) as $table) {
            $page->assertSee($table);
        }

        // a wrong confirmation deletes nothing
        $this->post(route('admin.test-data.clean'), ['confirm' => 'delete'])->assertSessionHasErrors('confirm');
        $this->assertSame(1, DB::table('inspections')->count());

        $this->post(route('admin.test-data.clean'), ['confirm' => 'DELETE'])->assertRedirect(route('admin.test-data.index'));
        foreach (array_keys(TestDataCleaner::TABLES) as $table) {
            $this->assertSame(0, DB::table($table)->count(), "$table is empty");
        }
        Storage::disk('private')->assertMissing('evidence/test-photo.jpg');
        $this->assertSame($users, User::count());
        $this->assertSame($schools, School::count());
        $this->assertSame(1, Notice::count());
        $log = AuditLog::where('action', 'test_data_cleaned')->firstOrFail();
        $this->assertSame(1, $log->meta['tables']['inspections'] ?? null);

        $this->get(route('admin.test-data.index'))->assertOk()->assertSee('Cleaned')->assertSee('There is no inspection test data to clean.');
    }

    public function test_only_the_state_admin_and_never_in_production(): void
    {
        $this->seedTestData();
        $dpo = $this->officer(UserLevel::District, Cluster::orderBy('id')->first());
        $this->actingAs($dpo)->get(route('admin.test-data.index'))->assertForbidden();
        $this->post(route('admin.test-data.clean'), ['confirm' => 'DELETE'])->assertForbidden();

        $admin = $this->admin();
        $this->app['env'] = 'production';
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertDontSee(route('admin.test-data.index'), false);
        $this->get(route('admin.test-data.index'))->assertOk()->assertSee('test data cannot be cleaned here')->assertDontSee('Type <b>DELETE</b>', false);
        // CSRF is enforced outside the testing env; the refusal under test is the production check
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post(route('admin.test-data.clean'), ['confirm' => 'DELETE'])->assertForbidden();
        $this->assertSame(1, DB::table('inspections')->count());
        $this->artisan('inspections:clean-test-data', ['--force' => true])->assertFailed();
        $this->app['env'] = 'testing';
    }

    public function test_artisan_command_shows_the_tables_and_cleans(): void
    {
        $this->seedTestData();
        $this->artisan('inspections:clean-test-data', ['--force' => true])
            ->expectsOutputToContain('UPDATE inspections SET prefilled_from_id = NULL;')
            ->expectsOutputToContain('Cleaned:')->assertSuccessful();
        $this->assertSame(0, DB::table('inspections')->count());
        $this->assertSame(0, DB::table('daily_school_lists')->count());
    }
}

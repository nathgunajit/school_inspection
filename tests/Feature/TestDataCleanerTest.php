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

/** 25-11-2026: clean test data by group (inspections, schedules, login log, audit log), local server only. */
class TestDataCleanerTest extends TestCase
{
    /** An inspection with an answer and a photo file, a plan with a stop, a Today's Schools row, a login row and old audit rows. */
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
        Storage::disk('private')->put('schedule-orders/order.pdf', 'pdf');
        $sid = DB::table('inspection_schedules')->insertGetId(['officer_id' => $crcc->id, 'schedule_date' => today()->addDay()->toDateString(),
            'attachment_path' => 'schedule-orders/order.pdf', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('inspection_schedule_items')->insert(['schedule_id' => $sid, 'school_id' => $school->id, 'schedule_date' => today()->addDay()->toDateString(), 'sequence' => 1,
            'inspection_id' => $insp->id, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('daily_school_list_days')->insertOrIgnore(['officer_id' => $crcc->id, 'list_date' => today()->addDays(2)->toDateString(), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('daily_school_lists')->insert(['officer_id' => $crcc->id, 'list_date' => today()->addDays(2)->toDateString(), 'school_id' => $school->id,
            'reason' => 'manual', 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('user_login_logs')->insert(['user_id' => $crcc->id, 'mobile' => $crcc->mobile, 'status' => 'success', 'login_at' => $now]);

        return [$crcc, $insp];
    }

    private function clean(string $group)
    {
        return $this->post(route('admin.test-data.clean'), ['group' => $group, 'confirm' => 'DELETE']);
    }

    public function test_page_shows_each_group_with_its_tables_and_sql(): void
    {
        $this->seedTestData();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('dashboard'))->assertSee(route('admin.test-data.index'), false);
        $page = $this->get(route('admin.test-data.index'))->assertOk()
            ->assertSee('DELETE FROM inspection_answers;')->assertSee('UPDATE inspections SET prefilled_from_id = NULL;')
            ->assertSee('DELETE FROM user_login_logs;')->assertSee('DELETE FROM audit_logs WHERE id &lt;=', false)
            ->assertSeeInOrder(['id="clean-inspections"', 'id="clean-schedules"', 'id="clean-login_logs"', 'id="clean-audit_logs"'], false);
        foreach (TestDataCleaner::GROUPS as $g) {
            foreach (array_keys($g['tables']) as $table) {
                $page->assertSee($table);
            }
        }
        $this->post(route('admin.test-data.clean'), ['group' => 'inspections', 'confirm' => 'delete'])->assertSessionHasErrors('confirm');
        $this->post(route('admin.test-data.clean'), ['group' => 'users', 'confirm' => 'DELETE'])->assertSessionHasErrors('group');
        $this->assertSame(1, DB::table('inspections')->count());
    }

    public function test_each_group_is_cleaned_on_its_own(): void
    {
        $this->seedTestData();
        $admin = $this->admin();
        Notice::create(['type' => 'order', 'title' => 'Kept notice', 'body' => 'Body', 'notice_date' => today()->toDateString(), 'levels' => ['cluster'],
            'status' => 'published', 'created_by' => $admin->id]);
        [$users, $schools] = [User::count(), School::count()];
        $this->actingAs($admin);

        // schedules only: inspections stay
        $this->clean('schedules')->assertRedirect();
        $this->assertSame(0, DB::table('inspection_schedules')->count());
        $this->assertSame(0, DB::table('inspection_schedule_items')->count());
        Storage::disk('private')->assertMissing('schedule-orders/order.pdf');
        $this->assertSame(1, DB::table('inspections')->count());
        $this->assertSame(1, DB::table('user_login_logs')->count());

        // inspections: their tables and the photo file; login log stays
        $this->clean('inspections')->assertRedirect();
        foreach (array_keys(TestDataCleaner::GROUPS['inspections']['tables']) as $table) {
            $this->assertSame(0, DB::table($table)->count(), "$table is empty");
        }
        Storage::disk('private')->assertMissing('evidence/test-photo.jpg');
        $this->assertSame(1, DB::table('user_login_logs')->count());

        $this->clean('login_logs')->assertRedirect();
        $this->assertSame(0, DB::table('user_login_logs')->count());

        // audit log: archived to Excel first, then only the new purge / clean entries remain
        $before = DB::table('audit_logs')->count();
        $this->assertGreaterThan(0, $before);
        $this->clean('audit_logs')->assertRedirect();
        $this->assertSame(['audit_purged', 'test_data_cleaned'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertNotEmpty(Storage::disk('private')->files('audit-archive'));
        $this->get(route('admin.test-data.index'))->assertOk()->assertSee('Cleaned: Audit log');

        $this->assertSame([$users, $schools, 1], [User::count(), School::count(), Notice::count()]);
    }

    public function test_hidden_when_switched_off_or_in_production(): void
    {
        $this->seedTestData();
        $dpo = $this->officer(UserLevel::District, Cluster::orderBy('id')->first());
        $this->actingAs($dpo)->get(route('admin.test-data.index'))->assertForbidden(); // not the State Admin

        $admin = $this->admin();
        config(['inspection.test_data_cleanup' => false]);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertDontSee(route('admin.test-data.index'), false);
        $this->get(route('admin.test-data.index'))->assertNotFound();
        $this->clean('inspections')->assertNotFound();
        $this->artisan('data:clean-test', ['group' => 'all', '--force' => true])->assertFailed();

        // production: off even with the flag on (CSRF is enforced outside the testing env, so skip it for the POST)
        config(['inspection.test_data_cleanup' => true]);
        $this->app['env'] = 'production';
        $this->get(route('admin.test-data.index'))->assertNotFound();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)->clean('inspections')->assertNotFound();
        $this->artisan('data:clean-test', ['group' => 'inspections', '--force' => true])->assertFailed();
        $this->app['env'] = 'testing';
        $this->assertSame(1, DB::table('inspections')->count());
    }

    public function test_artisan_command_cleans_a_group_or_all(): void
    {
        $this->seedTestData();
        $this->artisan('data:clean-test', ['group' => 'schedules', '--force' => true])->expectsOutputToContain('DELETE FROM inspection_schedules;')->assertSuccessful();
        $this->assertSame(1, DB::table('inspections')->count());
        $this->artisan('data:clean-test', ['group' => 'nope', '--force' => true])->assertFailed();
        $this->artisan('data:clean-test', ['group' => 'all', '--force' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('inspections')->count());
        $this->assertSame(0, DB::table('user_login_logs')->count());
        $this->artisan('inspections:clean-test-data', ['--force' => true])->assertSuccessful(); // old name
    }
}

<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\AssemblyConstituency;
use App\Models\AuditLog;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\ParliamentaryConstituency;
use App\Models\School;
use App\Models\TlmItem;
use App\Models\User;
use App\Services\MasterImportService;
use App\Support\UrlId;
use Database\Seeders\ConstituencySeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** Modification V13: demo users, School Master list, master delete, audit / cache clean-up, import limits, rating map, default photo. */
class ModificationV13Test extends TestCase
{
    /* ---------- item 1: demo users ---------- */

    public function test_reset_keeps_only_the_state_admin_and_creates_demo_officers_per_level(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $old = $this->officer(UserLevel::Cluster);
        $this->startInspection($old, $this->schoolIn(Cluster::orderBy('id')->first()));

        $this->artisan('users:reset-demo', ['--force' => true])->assertSuccessful();

        $this->assertNotNull($admin->fresh(), 'the State Administrator is kept');
        $this->assertNull(User::withTrashed()->find($old->id));
        $this->assertSame(0, DB::table('inspections')->count(), 'their inspections go with them');
        // 4 per level, plus (27-11-2026) a District Programmer and a Block MIS
        foreach ([UserLevel::State->value => 4, UserLevel::District->value => 5, UserLevel::Block->value => 5, UserLevel::Cluster->value => 4] as $level => $count) {
            $this->assertSame($count, User::where('level', $level)->where('status', UserStatus::Active)->count(), $level);
        }
        $this->assertSame('District Programmer', User::where('mobile', '9000000015')->first()->designation_label);
        $this->assertSame(UserLevel::District, User::where('mobile', '9000000015')->first()->level);
        $this->assertSame('Block MIS', User::where('mobile', '9000000025')->first()->designation_label);
        $this->assertSame(UserLevel::Block, User::where('mobile', '9000000025')->first()->level);
        $this->app['auth']->forgetGuards(); // signed out, without the logout event
        $login = $this->get(route('login'))->assertOk(); // listed on the sign-in page's Demo accounts panel
        foreach (['Pranjal Bhuyan', 'District Programmer', '9000000015', 'Nisha Talukdar', 'Block MIS', '9000000025'] as $text) {
            $login->assertSee($text);
        }
        $this->assertSame(1, User::where('status', UserStatus::Pending)->count());
        $this->assertSame(1, User::where('status', UserStatus::Rejected)->count());
        // demo officers sit on real locations: one cluster officer per cluster, genders set
        $clusterIds = User::where('level', UserLevel::Cluster)->where('status', UserStatus::Active)->with('currentJurisdiction')->get()->pluck('currentJurisdiction.cluster_id');
        $this->assertCount(4, $clusterIds->unique()->filter());
        $this->assertSame(0, User::where('level', '!=', UserLevel::StateAdmin)->whereNull('gender')->count());
        $this->assertTrue(AuditLog::where('action', 'demo_users_reset')->exists());
    }

    public function test_reset_is_refused_in_production(): void
    {
        $this->admin();
        $this->officer(UserLevel::Cluster);
        $this->app['env'] = 'production';
        $this->artisan('users:reset-demo', ['--force' => true])->assertFailed();
        $this->app['env'] = 'testing';
        $this->assertSame(2, User::count());
    }

    /* ---------- item 7: default photo by gender ---------- */

    public function test_avatar_shows_a_gender_image_when_no_photo_is_uploaded(): void
    {
        $render = fn (User $u) => Blade::render('<x-user-avatar :user="$u" />', ['u' => $u]);
        $this->assertStringContainsString('img/avatar-female.svg', $render(User::factory()->create(['gender' => 'female', 'name' => 'Asha Devi'])));
        $this->assertStringContainsString('img/avatar-male.svg', $render(User::factory()->create(['gender' => 'male'])));
        $this->assertStringContainsString('>AD<', $render(User::factory()->create(['gender' => null, 'name' => 'Amit Das'])));
        $this->assertFileExists(public_path('img/avatar-female.svg'));
        $this->assertFileExists(public_path('img/avatar-male.svg'));
    }

    public function test_gender_is_asked_at_registration_and_editable_by_the_admin(): void
    {
        $this->get(route('register'))->assertOk()->assertSee('name="gender"', false);
        $this->post(route('register'), ['name' => 'Asha Devi'])->assertSessionHasErrors('gender');

        $admin = $this->admin();
        $u = $this->officer(UserLevel::Cluster);
        $this->actingAs($admin)->put(route('admin.users.update', $u), ['name' => $u->name, 'gender' => 'female', 'designation_id' => \App\Models\MasterValue::idFor('designation', 'cluster_resource_coordinator')])
            ->assertSessionHasNoErrors();
        $this->assertSame('female', $u->fresh()->gender);
    }

    /* ---------- item 2: School Master list ---------- */

    public function test_school_list_filters_counts_and_exports_in_upload_order(): void
    {
        $this->seed(ConstituencySeeder::class);
        $admin = $this->admin();
        $ac = AssemblyConstituency::orderBy('code')->first();
        $s = School::orderBy('id')->first();
        $s->update(['ac_id' => $ac->id, 'pc_id' => $ac->pc_id, 'area_type' => 'urban']);
        $other = School::orderByDesc('id')->first();

        $this->actingAs($admin)->get(route('admin.schools.index'))->assertOk()->assertSee(number_format(School::count()).'</strong> schools in the School Master', false);
        $this->get(route('admin.schools.index', ['ac_id' => $ac->id]))->assertOk()->assertSee($s->name)->assertDontSee($other->name)->assertSee('<strong class="text-body">1</strong> of', false);
        $this->get(route('admin.schools.index', ['pc_id' => $ac->pc_id, 'area_type' => 'urban']))->assertOk()->assertSee($s->name)->assertDontSee($other->name);
        $this->get(route('admin.schools.index', ['block_id' => $other->block_id, 'district_id' => $other->district_id]))->assertOk()->assertSee($other->name);

        Excel::fake();
        $this->get(route('admin.schools.export', ['ac_id' => $ac->id]))->assertOk();
        Excel::assertDownloaded('school_master_'.now()->format('Y-m-d').'.xlsx', function ($export) use ($s, $ac) {
            $rows = $export->query()->get()->map(fn ($x) => $export->map($x));

            return $export->headings() === MasterImportService::SCHOOL_COLUMNS && $rows->count() === 1
                && $rows[0][0] === $s->udise_code && in_array($ac->code, $rows[0], true) && array_slice($export->headings(), -1)[0] === 'longitude';
        });
    }

    /* ---------- item 3: delete unused master data ---------- */

    public function test_unused_master_records_are_deleted_and_used_ones_are_refused(): void
    {
        $this->seed(ConstituencySeeder::class);
        $admin = $this->admin();
        $this->actingAs($admin);
        $block = Block::orderBy('id')->first();

        // an unused cluster goes; a cluster with schools is kept with the reason
        $empty = Cluster::create(['block_id' => $block->id, 'code' => '99999999', 'name' => 'Empty Cluster', 'is_active' => true]);
        $this->delete(route('admin.locations.destroy', ['clusters', UrlId::encode(Cluster::class, $empty->id)]))->assertSessionHas('success');
        $this->assertDatabaseMissing('clusters', ['id' => $empty->id]);
        $this->assertTrue(AuditLog::where('action', 'location_deleted')->where('meta->code', '99999999')->exists());

        $used = School::orderBy('id')->first()->cluster;
        $this->delete(route('admin.locations.destroy', ['clusters', UrlId::encode(Cluster::class, $used->id)]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'cannot be deleted') && str_contains($m, 'school'));
        $this->assertDatabaseHas('clusters', ['id' => $used->id]);

        // a school with no inspection is removed for good; one with an inspection is kept
        $school = School::orderByDesc('id')->first();
        $this->delete(route('admin.schools.destroy', $school))->assertSessionHas('success');
        $this->assertNull(School::withTrashed()->find($school->id));
        $busy = $this->schoolIn(Cluster::orderBy('id')->first());
        $this->startInspection($this->officer(UserLevel::Cluster), $busy);
        $this->actingAs($admin)->delete(route('admin.schools.destroy', $busy))->assertSessionHas('error', fn ($m) => str_contains($m, '1 inspection'));
        $this->assertNotNull($busy->fresh());

        // TLM item, AC, PC
        $tlm = TlmItem::create(['list' => 'fln', 'class' => 1, 'name' => 'Unused card set', 'sort' => 1, 'is_active' => true]);
        $this->delete(route('admin.tlm.destroy', $tlm))->assertSessionHas('success');
        $this->assertDatabaseMissing('tlm_items', ['id' => $tlm->id]);
        $ac = AssemblyConstituency::orderByDesc('code')->first();
        $this->delete(route('admin.constituencies.ac.destroy', $ac))->assertSessionHas('success');
        $this->assertDatabaseMissing('assembly_constituencies', ['id' => $ac->id]);
        $pc = ParliamentaryConstituency::has('acs')->first();
        $this->delete(route('admin.constituencies.pc.destroy', $pc))->assertSessionHas('error', fn ($m) => str_contains($m, 'AC'));

        // the menus offer Delete; officers cannot delete
        $this->get(route('admin.locations.index', 'clusters'))->assertOk()->assertSee('bi-trash', false);
        $district = $this->officer(UserLevel::District);
        $this->actingAs($district)->delete(route('admin.tlm.destroy', TlmItem::first() ?? $tlm))->assertForbidden();
    }

    public function test_remove_a_districts_schools_needs_the_typed_name(): void
    {
        $admin = $this->admin();
        $north = District::where('code', '1801')->firstOrFail();
        $south = District::where('code', '1802')->firstOrFail();
        $southCount = School::where('district_id', $south->id)->count();

        $this->actingAs($admin)->post(route('admin.schools.remove-district'), ['district_id' => $north->id, 'confirm_name' => 'wrong'])
            ->assertRedirect(route('admin.imports.index', ['tab' => 'report']))->assertSessionHasErrors('confirm_name', null, 'remove');
        $this->assertGreaterThan(0, School::where('district_id', $north->id)->count());

        $this->post(route('admin.schools.remove-district'), ['district_id' => $north->id, 'confirm_name' => strtolower($north->name)])->assertSessionHas('success');
        $this->assertSame(0, School::withTrashed()->where('district_id', $north->id)->count());
        $this->assertSame($southCount, School::where('district_id', $south->id)->count());
        $this->assertTrue(AuditLog::where('action', 'schools_removed')->exists());
        $this->get(route('admin.imports.index', ['tab' => 'report']))->assertOk()->assertSee('Remove schools of a district');
    }

    /* ---------- item 4: audit and cache clean-up ---------- */

    public function test_audit_purge_archives_then_deletes_only_old_entries(): void
    {
        Storage::fake('private');
        $old = DB::table('audit_logs')->insertGetId(['action' => 'login', 'created_at' => now()->subDays(45)]);
        $recent = DB::table('audit_logs')->insertGetId(['action' => 'login', 'created_at' => now()->subDays(5)]);

        $this->artisan('audit:purge')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $old]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent]);
        $files = Storage::disk('private')->files('audit-archive');
        $this->assertCount(1, $files);
        $log = AuditLog::where('action', 'audit_purged')->latest('id')->first();
        $this->assertSame(1, $log->meta['count']);
        $this->assertSame(basename($files[0]), $log->meta['file']);

        // the model still refuses any other delete
        $this->expectException(\LogicException::class);
        AuditLog::find($recent)->delete();
    }

    public function test_cleanup_is_scheduled_monthly_and_on_the_audit_page_for_the_state_admin(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.' '.$e->expression);
        $this->assertTrue($events->contains(fn ($c) => str_contains($c, 'audit:purge') && str_ends_with($c, '0 2 1 * *')));
        $this->assertTrue($events->contains(fn ($c) => str_contains($c, 'cache:purge')));
        $this->assertSame(30, config('inspection.audit_retention_days'));

        Storage::fake('private');
        $admin = $this->admin();
        DB::table('audit_logs')->insert(['action' => 'login', 'created_at' => now()->subDays(40)]);
        $this->actingAs($admin)->get(route('admin.audit.index'))->assertOk()->assertSee('Archive and delete')->assertSee('Clear cache');
        $this->post(route('admin.audit.purge'), ['days' => 30])->assertSessionHas('success');
        $file = basename(Storage::disk('private')->files('audit-archive')[0]);
        $this->get(route('admin.audit.archive', $file))->assertOk()->assertDownload($file);
        $this->get(route('admin.audit.archive', '..%2F.env'))->assertNotFound();
        $this->post(route('admin.audit.cache'))->assertSessionHas('success', 'Cache cleared.');
        $this->assertTrue(AuditLog::where('action', 'cache_cleared')->exists());

        $officer = $this->officer(UserLevel::District);
        $this->actingAs($officer)->post(route('admin.audit.purge'), ['days' => 0])->assertForbidden();
        $this->actingAs($officer)->post(route('admin.audit.cache'))->assertForbidden();
    }

    /* ---------- items 5 and 6 ---------- */

    public function test_import_limits_are_configurable(): void
    {
        $this->assertSame(0, config('inspection.import_timeout'));
        $this->assertSame('2048M', config('inspection.import_memory'));
        $this->actingAs($this->admin())->get(route('admin.imports.index'))->assertOk()->assertSee('Uploading the file…'); // 26-11-2026: the check and save run on the queue, the page need not stay open
    }

    public function test_rating_report_links_to_the_rating_map_for_the_state_admin(): void
    {
        $this->assertFalse(config('inspection.rating.display'));
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('reports.ratings'))->assertOk()->assertSee(route('map.index', ['colour' => 'rating']), false);
        $page = $this->get(route('map.index', ['colour' => 'rating']))->assertOk();
        $page->assertSee('id="colourRating" value="rating" checked', false);
        $this->assertStringContainsString('"showRating":true', $page->getContent());
    }
}

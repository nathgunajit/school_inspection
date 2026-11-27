<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\Cluster;
use App\Models\MasterValue;
use App\Models\RegistrationRequest;
use App\Models\State;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Modification V7: registration form changes. */
class RegistrationV7Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        // many registrations per test: the throttle would answer 429, which carries no session errors either
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    /** A registration that must succeed: no errors and back to the login page. */
    private function registers(array $over = []): void
    {
        $this->post(route('register'), $this->payload($over))->assertSessionHasNoErrors()->assertRedirect(route('login'));
    }

    private function payload(array $over = []): array
    {
        $cluster = Cluster::orderBy('id')->first();

        return array_merge([
            'gender' => 'female',
            'name' => 'Asha Devi',
            'designation_id' => MasterValue::idFor('designation', 'cluster_resource_coordinator'),
            'photo' => UploadedFile::fake()->image('photo.jpg', 300, 400),
            'level' => 'cluster',
            'state_id' => State::first()->id,
            'district_id' => $cluster->block->district_id,
            'block_id' => $cluster->block_id,
            'cluster_id' => $cluster->id,
            'mobile' => '9876543210',
            'password' => 'Strong@123',
            'password_confirmation' => 'Strong@123',
            'privacy' => '1',
        ], $over);
    }

    /** V7-1: Employee ID and identity proof are optional. */
    public function test_employee_id_and_identity_proof_are_optional(): void
    {
        $this->registers();
        $this->registers(['mobile' => '8876543210', 'employee_id' => '']);

        $a = User::where('mobile', '9876543210')->firstOrFail();
        $this->assertNull($a->employee_id);
        $this->assertNull($a->id_proof_path);
        $this->assertSame(2, User::whereNull('employee_id')->count(), 'several officers may have no Employee ID');

        // when given, the Employee ID is still unique and checked
        $this->registers(['mobile' => '7876543210', 'employee_id' => 'EMP-9']);
        $this->post(route('register'), $this->payload(['mobile' => '6876543210', 'employee_id' => 'EMP-9']))->assertSessionHasErrors('employee_id');
        $this->post(route('register'), $this->payload(['mobile' => '6876543210', 'employee_id' => 'EMP 9 !']))->assertSessionHasErrors('employee_id');

        // the approver sees "Not provided" and no ID-proof viewer
        $approver = $this->admin();
        $reg = RegistrationRequest::where('user_id', $a->id)->firstOrFail();
        $this->actingAs($approver)->get(route('admin.approvals.show', $reg))->assertOk()
            ->assertSee('Not provided')->assertSee('No identity proof was uploaded');
        $this->actingAs($approver)->get(route('admin.users.id-proof', $a))->assertNotFound();
    }

    /** V7-1: a re-application without a new file keeps the earlier identity proof. */
    public function test_reapplication_keeps_the_earlier_identity_proof(): void
    {
        $this->post(route('register'), $this->payload(['id_proof' => UploadedFile::fake()->create('order.pdf', 100, 'application/pdf')]));
        $user = User::where('mobile', '9876543210')->firstOrFail();
        $proof = $user->id_proof_path;
        $user->update(['status' => UserStatus::Rejected]);

        $this->registers(['name' => 'Asha Devi B']);
        $this->assertSame($proof, $user->fresh()->id_proof_path);
        Storage::disk('private')->assertExists($proof);
    }

    /** 27-11-2026: an email address belongs to one user (case and spaces ignored); none is fine for many. */
    public function test_email_address_is_not_duplicated(): void
    {
        $this->registers(['email' => ' Asha@X.org ']);
        $user = User::where('mobile', '9876543210')->firstOrFail();
        $this->assertSame('asha@x.org', $user->email);

        $this->post(route('register'), $this->payload(['mobile' => '8876543210', 'email' => 'ASHA@x.org']))
            ->assertSessionHasErrors(['email' => 'This email address is already registered.']);

        // no email: any number of officers
        $this->registers(['mobile' => '8876543210']);
        $this->registers(['mobile' => '7876543210', 'email' => '']);
        $this->assertSame(2, User::whereNull('email')->count());

        // a rejected officer re-applies with their own address
        $user->update(['status' => UserStatus::Rejected]);
        $this->registers(['email' => 'asha@x.org', 'name' => 'Asha Devi B']);

        // the admin cannot give another user that address
        $other = User::where('mobile', '8876543210')->firstOrFail();
        $this->actingAs($this->admin())->put(route('admin.users.update', $other), [
            'name' => $other->name, 'designation_id' => $other->designation_id, 'email' => 'Asha@x.org',
        ])->assertSessionHasErrors(['email' => 'This email address is already registered.']);
        $this->actingAs($this->admin())->put(route('admin.users.update', $user), [
            'name' => $user->name, 'designation_id' => $user->designation_id, 'email' => 'ASHA@X.ORG',
        ])->assertSessionHasNoErrors();
    }

    /** 27-11-2026: every User level tile has its icon (and the icon is in the subset). */
    public function test_every_level_tile_has_an_icon(): void
    {
        $page = $this->get(route('register'))->assertOk();
        $icons = file_get_contents(resource_path('scss/_icons.scss'));
        foreach (['bi-globe2', 'bi-buildings', 'bi-grid-3x3-gap', 'bi-pin-map'] as $icon) {
            $page->assertSee('<i class="bi '.$icon.'"', false);
            $this->assertStringContainsString('.'.$icon.'::before', $icons);
        }
    }

    /** V7-1: the State Admin may clear the Employee ID. */
    public function test_admin_can_clear_the_employee_id(): void
    {
        $officer = $this->officer(UserLevel::Cluster, Cluster::orderBy('id')->first());
        $this->actingAs($this->admin())->put(route('admin.users.update', $officer), [
            'name' => $officer->name, 'designation_id' => MasterValue::idFor('designation', 'cluster_resource_coordinator'), 'employee_id' => '',
        ])->assertSessionHasNoErrors();
        $this->assertNull($officer->fresh()->employee_id);
    }

    /** V7-2: a mobile number must start with 6, 7, 8 or 9 everywhere it is entered. */
    public function test_mobile_must_start_with_six_to_nine(): void
    {
        foreach (['5876543210', '0876543210', '987654321', '98765432a0'] as $bad) {
            $this->post(route('register'), $this->payload(['mobile' => $bad]))->assertSessionHasErrors('mobile');
        }
        $this->post(route('register'), $this->payload(['mobile' => '5876543210']))
            ->assertSessionHasErrors(['mobile' => 'Mobile number must be 10 digits starting with 6, 7, 8 or 9.']);
        foreach (['6876543210', '7876543210', '8876543210', '9876543210'] as $good) {
            $this->registers(['mobile' => $good]);
        }

        $this->post(route('login'), ['mobile' => '5876543210', 'password' => 'Whatever@1'])->assertSessionHasErrors('mobile');
        $this->post(route('password.email'), ['mobile' => '5876543210'])->assertSessionHasErrors('mobile');

        $officer = $this->officer(UserLevel::Cluster);
        $this->actingAs($officer)->post(route('profile.mobile'), ['current' => $officer->mobile, 'new_mobile' => '5876543210', 'reason' => 'New SIM'])
            ->assertSessionHasErrors('new_mobile');
    }

    /** V7-2: the other registration fields are checked on the server as well as in the form. */
    public function test_name_email_and_password_limits(): void
    {
        $this->post(route('register'), $this->payload(['name' => 'Asha 123']))->assertSessionHasErrors('name');
        $this->post(route('register'), $this->payload(['name' => 'A']))->assertSessionHasErrors('name');
        $this->post(route('register'), $this->payload(['email' => str_repeat('a', 145).'@x.org']))->assertSessionHasErrors('email');
        $long = 'Aa1@'.str_repeat('x', 61);
        $this->post(route('register'), $this->payload(['password' => $long, 'password_confirmation' => $long]))->assertSessionHasErrors('password');
        $this->registers(['name' => "Rina D'Souza-Bora Jr."]);
    }

    /** V7-3: choosing "Other" requires the designation to be typed; it is shown wherever the designation is. */
    public function test_other_designation_is_typed_and_shown(): void
    {
        $other = MasterValue::idFor('designation', User::OTHER_DESIGNATION);
        $this->assertNotNull($other);
        $this->get(route('register'))->assertOk()->assertSee('data-other-value="'.$other.'"', false)->assertSee('Type your designation');

        $this->post(route('register'), $this->payload(['designation_id' => $other]))
            ->assertSessionHasErrors(['designation_other' => 'Please enter your designation.']);
        $this->post(route('register'), $this->payload(['designation_id' => $other, 'designation_other' => '<script>']))
            ->assertSessionHasErrors('designation_other');
        $this->registers(['designation_id' => $other, 'designation_other' => '  Academic Counsellor (SSA) ']);

        // a normal designation ignores any typed text
        $this->registers(['mobile' => '8876543210', 'designation_other' => 'Ignored']);
        $plain = User::where('mobile', '8876543210')->firstOrFail();
        $this->assertNull($plain->designation_other);
        $this->assertSame('Cluster Resource Centre Coordinator', $plain->designation_label);

        // 27-11-2026: the typed designation is added to the Designation master and the officer points at it
        $user = User::where('mobile', '9876543210')->firstOrFail();
        $added = MasterValue::where('type', 'designation')->where('label', 'Academic Counsellor (SSA)')->firstOrFail();
        $this->assertSame($added->id, $user->designation_id);
        $this->assertNull($user->designation_other);
        $this->assertSame('Academic Counsellor (SSA)', $user->designation_label);
        $this->assertSame(MasterValue::where('code', 'other')->value('sort') - 1, $added->sort);
        $this->assertDatabaseHas('audit_logs', ['action' => 'designation_added']);
        $reg = RegistrationRequest::where('user_id', $user->id)->firstOrFail();
        $this->actingAs($this->admin())->get(route('admin.approvals.show', $reg))->assertSee('Academic Counsellor (SSA)');

        // the admin switching away from Other clears the text
        $this->actingAs($this->admin())->put(route('admin.users.update', $user), [
            'name' => $user->name, 'designation_id' => MasterValue::idFor('designation', 'director'), 'designation_other' => 'x',
        ])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->designation_other);
    }

    /** 27-11-2026: only a new typed designation is added to the master; a known one is reused. */
    public function test_other_designation_adds_only_new_master_values(): void
    {
        $other = MasterValue::idFor('designation', User::OTHER_DESIGNATION);
        $count = fn () => MasterValue::where('type', 'designation')->count();
        $before = $count();

        $this->post(route('register'), $this->payload(['designation_id' => $other, 'designation_other' => ' other ']))
            ->assertSessionHasErrors(['designation_other' => 'Please type your actual designation.']);

        // a seeded label, typed in another case with extra spaces, uses the seeded row
        $this->registers(['designation_id' => $other, 'designation_other' => 'block   education officer']);
        $this->assertSame($before, $count());
        $this->assertSame(MasterValue::idFor('designation', 'block_education_officer'), User::where('mobile', '9876543210')->value('designation_id'));

        // a new label is added once, then reused
        $this->registers(['mobile' => '8876543210', 'designation_other' => 'Assistant Mission Coordinator', 'designation_id' => $other]);
        $this->registers(['mobile' => '7876543210', 'designation_other' => 'assistant  mission coordinator', 'designation_id' => $other]);
        $this->assertSame($before + 1, $count());
        $added = MasterValue::where('label', 'Assistant Mission Coordinator')->firstOrFail();
        $this->assertSame('assistant_mission_coordinator', $added->code);
        $this->assertSame(2, User::where('designation_id', $added->id)->count());

        // the dropdown offers it, above Other
        auth()->logout();
        $this->get(route('register'))->assertOk()->assertSeeInOrder(['Assistant Mission Coordinator', '>Other<'], false);

        // the admin typing a new designation adds it as well
        $user = User::where('mobile', '7876543210')->firstOrFail();
        $this->actingAs($this->admin())->put(route('admin.users.update', $user), [
            'name' => $user->name, 'designation_id' => $other, 'designation_other' => 'State Consultant',
        ])->assertSessionHasNoErrors();
        $this->assertSame('State Consultant', $user->fresh()->designation_label);
        $this->assertSame($before + 2, $count());
    }

    /** V7-3: file sizes are written one standard way, from config. */
    public function test_file_sizes_are_shown_from_config(): void
    {
        $this->assertSame('1 MB', \App\Support\FileSize::human(1024));
        $this->assertSame('1.5 MB', \App\Support\FileSize::human(1536));
        $this->assertSame('500 KB', \App\Support\FileSize::human(500));
        $this->assertSame('JPG / PNG', \App\Support\FileSize::types(['jpg', 'jpeg', 'png']));

        config(['inspection.employee_photo.max_kb' => 1536]);
        $this->get(route('register'))->assertSee('Max 1.5 MB')->assertSee('Max 2 MB')->assertSee('reg-consent', false);
        $this->post(route('register'), $this->payload(['photo' => UploadedFile::fake()->image('p.jpg', 300, 400)->size(1600)]))
            ->assertSessionHasErrors(['photo' => 'The photograph is larger than 1.5 MB. Please choose a photo of 1.5 MB or less.']);
    }

    /** V7-4: valid files survive a failed submit and are used by the next one. */
    public function test_uploads_are_kept_after_a_validation_error(): void
    {
        User::factory()->create(['mobile' => '9876543210']);
        $this->post(route('register'), $this->payload([
            'id_proof' => UploadedFile::fake()->create('order.pdf', 100, 'application/pdf'),
        ]))->assertSessionHasErrors('mobile');

        $disk = Storage::disk('private');
        $this->assertCount(2, $disk->files('register-tmp'));
        $page = $this->get(route('register'))->assertOk()
            ->assertSee('name="keep_photo" value="1"', false)->assertSee('name="keep_id_proof" value="1"', false)
            ->assertSee('data-kept-name="photo.jpg"', false)->assertSee('data-kept-pdf="1"', false);
        $this->get(route('register.kept', 'photo'))->assertOk();
        $this->get(route('register.kept', 'id_proof'))->assertOk();

        // corrected mobile, no files chosen again
        $this->registers(['mobile' => '8876543210', 'photo' => null, 'keep_photo' => '1', 'keep_id_proof' => '1']);
        $user = User::where('mobile', '8876543210')->firstOrFail();
        $this->assertStringStartsWith('employee-photos/', $user->photo_path);
        $this->assertStringStartsWith('id-proofs/', $user->id_proof_path);
        $this->assertSame('order.pdf', $user->id_proof_original_name);
        $disk->assertExists([$user->photo_path, $user->id_proof_path]);
        $this->assertSame([], $disk->files('register-tmp'));
        $this->assertNull(session('register_uploads.photo'));
        $this->get(route('register.kept', 'photo'))->assertNotFound();
    }

    /** V7-4: a removed, invalid or foreign kept file is never used. */
    public function test_kept_uploads_can_be_removed_and_are_private(): void
    {
        $this->post(route('register'), $this->payload(['mobile' => '5876543210']))->assertSessionHasErrors('mobile');
        $this->assertNotNull(session('register_uploads.photo'));

        // the officer pressed Remove: the photo is required again and the kept file is deleted
        $this->post(route('register'), $this->payload(['photo' => null, 'keep_photo' => '0']))->assertSessionHasErrors('photo');
        $this->assertNull(session('register_uploads.photo'));
        $this->assertSame([], Storage::disk('private')->files('register-tmp'));

        // a file that failed its own rules is not kept
        $this->post(route('register'), $this->payload(['mobile' => '5876543210', 'photo' => UploadedFile::fake()->create('p.pdf', 50, 'application/pdf')]));
        $this->assertNull(session('register_uploads.photo'));

        // another visitor (no session entry) cannot open a kept file; an unknown field is not routed
        $this->post(route('register'), $this->payload(['mobile' => '5876543210']));
        $this->assertNotNull(session('register_uploads.photo'));
        $this->flushSession();
        $this->get(route('register.kept', 'photo'))->assertNotFound();
        $this->get('/register/kept/password')->assertNotFound();
    }

    /** V7-4: kept files of abandoned forms are purged. */
    public function test_abandoned_kept_files_are_purged(): void
    {
        $disk = Storage::disk('private');
        $disk->put('register-tmp/old.jpg', 'x');
        $disk->put('register-tmp/new.jpg', 'x');
        touch($disk->path('register-tmp/old.jpg'), now()->subHours(30)->getTimestamp());

        $this->artisan('register:purge-tmp')->expectsOutputToContain('Removed 1')->assertSuccessful();
        $disk->assertMissing('register-tmp/old.jpg');
        $disk->assertExists('register-tmp/new.jpg');
    }

    /** 27-11-2026: the officer sees their own identity proof in a popup on My Profile; others in the area cannot open it. */
    public function test_identity_proof_opens_in_a_popup_on_both_profile_pages(): void
    {
        $this->registers(['id_proof' => UploadedFile::fake()->image('voter-card.jpg', 400, 300)]);
        $u = User::where('mobile', '9876543210')->firstOrFail();
        $u->forceFill(['status' => UserStatus::Active, 'must_change_password' => false])->save();

        $this->actingAs($u)->get(route('profile'))->assertOk()
            ->assertSee('id="id-proof-modal"', false)->assertSee('data-lazy-src="'.route('admin.users.id-proof', $u).'"', false)
            ->assertSee('voter-card.jpg')
            // shown on the page itself, tap to enlarge; no separate button in the hero
            ->assertSee('data-proof-preview', false)->assertSee('src="'.route('admin.users.id-proof', $u).'"', false)
            ->assertSee('Tap to enlarge')->assertDontSee('View identity proof');
        // the officer's own views are not audited (the preview loads on every visit)
        $this->actingAs($u)->get(route('admin.users.id-proof', $u))->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'id_proof_viewed', 'user_id' => $u->id]);

        $other = $this->officer(UserLevel::Cluster, $this->otherCluster(Cluster::orderBy('id')->first()));
        $this->actingAs($other)->get(route('admin.users.id-proof', $u))->assertNotFound();
        $this->actingAs($other)->get(route('profile'))->assertOk()->assertDontSee('id="id-proof-modal"', false)
            ->assertDontSee('data-proof-preview', false)->assertSee('No identity proof uploaded (optional).');

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.users.show', $u))->assertOk()
            ->assertSee('id="id-proof-modal"', false)->assertSee('data-bs-target="#id-proof-modal"', false)->assertSee('View identity proof');
        $this->actingAs($admin)->get(route('admin.users.id-proof', $u))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'id_proof_viewed', 'user_id' => $admin->id]);
    }

    /** 27-11-2026: a PDF identity proof shows as a document tile with View, not as an image. */
    public function test_pdf_identity_proof_shows_a_document_tile_on_my_profile(): void
    {
        $this->registers(['id_proof' => UploadedFile::fake()->create('posting-order.pdf', 100, 'application/pdf')]);
        $u = User::where('mobile', '9876543210')->firstOrFail();
        $u->forceFill(['status' => UserStatus::Active, 'must_change_password' => false])->save();

        $this->actingAs($u)->get(route('profile'))->assertOk()
            ->assertSee('pf-proof-doc', false)->assertSee('posting-order.pdf')->assertSee('PDF document')
            ->assertDontSee('data-proof-preview', false)->assertSee('id="id-proof-modal"', false);
    }
}

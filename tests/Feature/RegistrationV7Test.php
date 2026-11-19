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
    }

    private function payload(array $over = []): array
    {
        $cluster = Cluster::orderBy('id')->first();

        return array_merge([
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
        $this->post(route('register'), $this->payload())->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $this->post(route('register'), $this->payload(['mobile' => '8876543210', 'employee_id' => '']))->assertSessionHasNoErrors();

        $a = User::where('mobile', '9876543210')->firstOrFail();
        $this->assertNull($a->employee_id);
        $this->assertNull($a->id_proof_path);
        $this->assertSame(2, User::whereNull('employee_id')->count(), 'several officers may have no Employee ID');

        // when given, the Employee ID is still unique and checked
        $this->post(route('register'), $this->payload(['mobile' => '7876543210', 'employee_id' => 'EMP-9']))->assertSessionHasNoErrors();
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

        $this->post(route('register'), $this->payload(['name' => 'Asha Devi B']))->assertSessionHasNoErrors();
        $this->assertSame($proof, $user->fresh()->id_proof_path);
        Storage::disk('private')->assertExists($proof);
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
            $this->post(route('register'), $this->payload(['mobile' => $good]))->assertSessionHasNoErrors();
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
        $this->post(route('register'), $this->payload(['name' => "Rina D'Souza-Bora Jr."]))->assertSessionHasNoErrors();
    }
}

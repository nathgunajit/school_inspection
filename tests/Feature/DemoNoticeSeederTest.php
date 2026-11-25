<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Notice;
use App\Models\NoticeRead;
use App\Models\User;
use App\Services\NoticeService;
use Database\Seeders\DemoNoticeSeeder;
use Database\Seeders\DemoUserSeeder;
use Database\Seeders\StateAdminSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 25-11-2026: demo Notices & Orders, and the State Administrator's male default photo. */
class DemoNoticeSeederTest extends TestCase
{
    public function test_demo_notices_are_seeded_once_with_audiences_states_and_an_attachment(): void
    {
        Storage::fake('private');
        $admin = $this->admin();
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoNoticeSeeder::class);
        $this->assertSame(10, Notice::count());
        $this->seed(DemoNoticeSeeder::class);
        $this->assertSame(10, Notice::count(), 're-runnable');

        $order = Notice::where('ref_no', DemoNoticeSeeder::ATTACHMENT_REF)->firstOrFail();
        Storage::disk('private')->assertExists($order->attachment_path);
        $this->assertSame('application/pdf', $order->attachment_mime);
        $this->assertSame(2, NoticeRead::where('notice_id', $order->id)->whereNotNull('acknowledged_at')->count());

        // a Cluster officer of the first district: state-wide + their district's notice; not the other district's, the expired or the withdrawn one
        $crcc = User::where('mobile', '9000000031')->firstOrFail();
        $inbox = Notice::query()->for($crcc)->pluck('ref_no')->all();
        foreach ([DemoNoticeSeeder::ATTACHMENT_REF, 'SSA/INSP/2026/098', 'SSA/GEN/2026/045', 'DPO/KJR/2026/312', 'BEEO/DTM/2026/054', 'SSA/PUB/2026/011', 'DPO/KJR/2026/MM-07'] as $ref) {
            $this->assertContains($ref, $inbox);
        }
        foreach (['DPO/DHB/2026/207', 'SSA/GEN/2026/039', 'SSA/INSP/2026/090'] as $ref) {
            $this->assertNotContains($ref, $inbox);
        }
        $this->assertGreaterThan(0, app(NoticeService::class)->unreadCount($crcc));
        $this->actingAs($crcc)->get(route('notices.index'))->assertOk()->assertSee('Monthly school inspection drive');

        $this->actingAs($admin)->get(route('notices.index', ['tab' => 'sent']))->assertOk()
            ->assertSee('Training on the School Inspection application')->assertSee('Inspection format for the second quarter');
    }

    public function test_reset_demo_still_works_after_demo_officers_sent_notices(): void
    {
        Storage::fake('private');
        $this->admin();
        $this->seed(DemoUserSeeder::class);
        $this->seed(DemoNoticeSeeder::class);

        $this->artisan('users:reset-demo', ['--force' => true])->assertSuccessful();
        $this->assertSame(10, Notice::count(), 'the officers\' notices are made again for the new demo accounts');
        $this->assertSame(0, Notice::whereNotIn('created_by', User::pluck('id'))->count());
    }

    public function test_state_administrator_gets_the_male_default_photo(): void
    {
        $admin = $this->admin();
        // the admin's mobile was changed after installation: the seeder must reuse them, not create a second admin
        $admin->forceFill(['gender' => null, 'mobile' => '9123456789', 'employee_id' => env('ADMIN_EMPLOYEE_ID', 'ADMIN001')])->save();
        $this->seed(StateAdminSeeder::class);
        $this->assertSame('male', $admin->fresh()->gender);
        $this->assertSame(1, User::where('level', UserLevel::StateAdmin)->count());
        $this->actingAs($admin->fresh())->get(route('dashboard'))->assertOk()->assertSee('avatar-male.svg', false);
    }
}

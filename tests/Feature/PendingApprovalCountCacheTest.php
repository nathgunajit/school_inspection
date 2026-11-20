<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Enums\UserStatus;
use App\Models\RegistrationRequest;
use App\Services\ApproverResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Audit P1: the pending-approvals badge is cached per approver and refreshed on every change. */
class PendingApprovalCountCacheTest extends TestCase
{
    private function pendingApplicant(): RegistrationRequest
    {
        $u = $this->officer(UserLevel::Cluster);
        $u->update(['status' => UserStatus::Pending]);

        return RegistrationRequest::create(['user_id' => $u->id, 'status' => 'pending']);
    }

    public function test_count_is_cached_between_calls(): void
    {
        $this->pendingApplicant();
        $admin = $this->admin();
        $resolver = app(ApproverResolver::class);

        $this->assertSame(1, $resolver->pendingCountFor($admin));

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame(1, $resolver->pendingCountFor($admin));
        $this->assertCount(0, DB::getQueryLog(), 'second call is served from the cache');
    }

    public function test_new_registration_refreshes_the_count(): void
    {
        $this->pendingApplicant();
        $admin = $this->admin();
        $resolver = app(ApproverResolver::class);
        $this->assertSame(1, $resolver->pendingCountFor($admin));

        $this->pendingApplicant();
        $this->assertSame(2, $resolver->pendingCountFor($admin));
    }

    public function test_approve_and_reject_refresh_the_count(): void
    {
        $a = $this->pendingApplicant();
        $b = $this->pendingApplicant();
        $admin = $this->admin();
        $resolver = app(ApproverResolver::class);
        $this->assertSame(2, $resolver->pendingCountFor($admin));

        $a->update(['status' => 'approved', 'decided_at' => now()]);
        $this->assertSame(1, $resolver->pendingCountFor($admin));

        $b->update(['status' => 'rejected', 'reason' => 'x', 'decided_at' => now()]);
        $this->assertSame(0, $resolver->pendingCountFor($admin));
    }

    public function test_layout_badge_shows_the_count(): void
    {
        $this->pendingApplicant();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('1 pending approval', false);
    }
}

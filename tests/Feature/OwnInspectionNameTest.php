<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\Cluster;
use Tests\TestCase;

/** 25-11-2026: an officer's own name is not shown on their own inspections ("You"); higher officers see who inspected. */
class OwnInspectionNameTest extends TestCase
{
    /** The page without the top bar account menu (which always names the signed-in user). */
    private function body(\App\Models\User $user, string $url): string
    {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

        return preg_replace('~<header.*?</header>~s', '', $html);
    }

    public function test_owner_sees_you_and_higher_officer_sees_the_name(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $school = $this->schoolIn($cluster);
        $crcc = $this->officer(UserLevel::Cluster, $cluster, ['name' => 'Zebulon Uniquename']);
        $i = $this->startInspection($crcc, $school);
        $i->update(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'SI/OWN/'.$i->id]);

        $report = $this->body($crcc, route('inspections.show', $i));
        $this->assertStringNotContainsString('Zebulon Uniquename', $report);
        $this->assertMatchesRegularExpression('~Inspected by</span><b[^>]*>You</b>~', $report);
        $this->assertMatchesRegularExpression('~Inspecting officer</span><b>You</b>~', $report);
        $page = $this->body($crcc, route('schools.show', $school));
        $this->assertStringNotContainsString('Zebulon Uniquename', $page);
        $this->assertStringContainsString('You · SI/OWN/'.$i->id, $page);

        $district = $this->officer(UserLevel::District, $cluster);
        $this->assertStringContainsString('Zebulon Uniquename', $this->body($district, route('inspections.show', $i)));
        $this->assertStringContainsString('Zebulon Uniquename · SI/OWN/'.$i->id, $this->body($district, route('schools.show', $school)));
    }
}

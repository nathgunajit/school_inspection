<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use Tests\TestCase;

/** Rating Report, Inspections in My Area and My Inspections use small, standard filter inputs. */
class SmallFiltersTest extends TestCase
{
    public function test_filter_inputs_are_small_on_the_three_pages(): void
    {
        config(['inspection.rating.display' => true]); // Modification V9: ratings are hidden by default
        $admin = $this->admin();
        $officer = $this->officer(UserLevel::Cluster);

        foreach ([[$admin, route('reports.ratings')], [$admin, route('inspections.area')], [$officer, route('inspections.mine')]] as [$user, $url]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('filter-bar filter-sm', $html, $url);
            $this->assertStringContainsString('form-control form-control-sm', $html, $url);
            $this->assertStringContainsString('btn btn-primary btn-filter', $html, $url); // 23-11-2026: one fixed Apply size (<x-filter-buttons>)
            preg_match('/<form method="GET"[^>]*class="filter-bar filter-sm.*?<\/form>/s', $html, $m);
            $this->assertDoesNotMatchRegularExpression('/class="form-(control|select)"/', $m[0], "$url: every filter field is small");
        }
    }
}

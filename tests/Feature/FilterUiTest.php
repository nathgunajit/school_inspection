<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use Tests\TestCase;

/**
 * 23-11-2026: every filter bar has the same fixed-size Apply / Reset (<x-filter-buttons>), and filtered lists and
 * reports load inside their results section (resources/js/filter-partial.js; the server returns the full page).
 */
class FilterUiTest extends TestCase
{
    /** Pages with section loading: the filter form and the results are regions the script swaps. */
    public function test_report_and_list_pages_mark_their_filter_form_and_results(): void
    {
        $admin = $this->admin();
        $pages = [
            route('inspections.area'), route('reports.coverage'), route('reports.ratings'), route('reports.network'),
            route('schools.mine'), route('admin.schools.index'), route('admin.users.index'), route('admin.audit.index'),
            route('admin.login-report.index'), route('admin.login-report.daily'),
        ];
        foreach ($pages as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('btn-filter', $html, "$url has the standard Apply button");
            $this->assertMatchesRegularExpression('/<form[^>]*data-filter[^>]*data-filter-region|<form[^>]*data-filter-region[^>]*data-filter/', $html, "$url filter form");
            $this->assertMatchesRegularExpression('/id="[^"]+"[^>]*data-filter-region data-filter-results/', $html, "$url results region");
            $this->assertStringNotContainsString('data-loader="heavy" data-loader-text="Loading', $html, "$url no full-page splash on filter");
        }
    }

    public function test_master_and_schedule_filters_use_the_same_buttons(): void
    {
        $admin = $this->admin();
        foreach ([route('admin.locations.index', 'districts'), route('admin.tlm.index'), route('admin.constituencies.index')] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee('btn-filter', false)->assertSee('Reset filters', false);
        }
        $this->actingAs($this->officer(UserLevel::Block))->get(route('schedules.index'))->assertOk()->assertSee('btn-filter', false);
        $this->get(route('inspections.mine'))->assertOk()->assertSee('btn-filter', false);
    }

    public function test_a_filtered_page_still_loads_in_full(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $this->actingAs($officer)->withHeader('X-Filter-Partial', '1')->get(route('schools.mine', ['inspected' => 'no']))
            ->assertOk()->assertSee('id="ms-results"', false)->assertSee('id="my-schools-filter"', false);
    }
}

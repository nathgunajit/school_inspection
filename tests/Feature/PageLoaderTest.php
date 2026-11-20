<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Branded loader and slow/offline banner are on public and signed-in pages. */
class PageLoaderTest extends TestCase
{
    public function test_login_page_has_loader_and_network_banner(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('id="si-loader"', false)->assertSee('School Inspection')
            ->assertSee('SSA LOGO.png', false)->assertSee('id="si-net-banner"', false);
    }

    public function test_dashboard_has_loader_and_network_banner(): void
    {
        $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()
            ->assertSee('id="si-loader"', false)->assertSee('Samagra Shiksha, Assam')->assertSee('id="si-net-banner"', false);
    }

    public function test_heavy_actions_are_marked_for_the_full_loader(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.imports.index'))->assertSee('data-loader="heavy"', false)->assertSee('Checking the file');
        $this->get(route('admin.rating.index'))->assertSee('Re-rating all inspections');
    }

    public function test_login_button_has_processing_text(): void
    {
        $this->get(route('login'))->assertSee('data-loading-text="Signing in…"', false);
    }
}

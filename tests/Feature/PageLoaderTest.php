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
}

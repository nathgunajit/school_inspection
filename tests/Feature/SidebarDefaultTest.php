<?php

namespace Tests\Feature;

use Tests\TestCase;

/** 23-11-2026: the desktop sidebar opens as the icon rail unless the user chose to keep it open. */
class SidebarDefaultTest extends TestCase
{
    public function test_sidebar_starts_collapsed_unless_kept_open(): void
    {
        $html = $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString("localStorage.getItem('si-sidebar') !== 'full'", $html);
        $this->assertStringContainsString("classList.add('sidebar-mini')", $html);
        $this->assertMatchesRegularExpression('/class="sidebar-toggle[^"]*" data-sidebar-mini data-mini-tip/', $html);
        $this->assertGreaterThan(3, substr_count($html, 'data-mini-tip'), 'menu links carry the name popup hook');
    }
}

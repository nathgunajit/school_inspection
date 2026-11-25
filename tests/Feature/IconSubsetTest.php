<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 25-11-2026, faster loading: the app ships only the Bootstrap Icons it uses (resources/scss/_icons.scss,
 * written by tools/build-icons.cjs on every `npm run build`). A new icon in a view must be in it:
 * run `npm run icons` (or `npm run build`) after adding one.
 */
class IconSubsetTest extends TestCase
{
    public function test_every_icon_used_in_the_views_and_scripts_is_in_the_subset(): void
    {
        $all = json_decode(file_get_contents(base_path('node_modules/bootstrap-icons/font/bootstrap-icons.json')) ?: '{}', true);
        if (! $all) {
            $this->markTestSkipped('node_modules is not installed');
        }
        $subset = file_get_contents(resource_path('scss/_icons.scss'));
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path()));
        foreach ($files as $file) {
            if (! $file->isFile() || ! preg_match('/\.(php|js)$/', $file->getFilename())) {
                continue;
            }
            preg_match_all('/\bbi-([a-z0-9]+(?:-[a-z0-9]+)*)\b/', file_get_contents($file->getPathname()), $m);
            foreach (array_unique($m[1]) as $name) {
                // the rule must carry the icon's real glyph code (a broken generator once wrote "${hex(n)}")
                if (isset($all[$name]) && ! str_contains($subset, '.bi-'.$name.'::before { content: "\\'.dechex($all[$name]).'"; }')) {
                    $missing[] = $name.' ('.$file->getFilename().')';
                }
            }
        }
        $this->assertSame([], $missing, 'Run `npm run icons`: these icons are not in resources/scss/_icons.scss');
        $this->assertStringNotContainsString('${', $subset, 'the generator wrote a template placeholder instead of a glyph code');
        $this->assertMatchesRegularExpression('~\.bi-house::before \{ content: "\\\\f425"; \}~', $subset);
    }

    public function test_compact_html_leaves_the_icon_classes_alone(): void
    {
        $page = $this->actingAs($this->admin())->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('<i class="bi bi-grid-1x2"></i>', $page);
    }
}

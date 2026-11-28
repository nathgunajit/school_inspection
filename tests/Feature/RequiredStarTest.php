<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 28-11-2026: every mandatory field shows a red * in its label. resources/js/required-star.js finds the label of each
 * required input / select / textarea by label[for=id], a wrapping <label>, or the nearest .form-label before it in the
 * same parent. This test renders the main forms and checks, with the same rules, that every required field has a label
 * the script can find (so a new form without a label is caught).
 */
class RequiredStarTest extends TestCase
{
    private const SKIP_TYPES = ['hidden', 'radio', 'checkbox', 'submit', 'button', 'reset', 'image'];

    /** @return list<string> descriptions of required fields whose label cannot be found */
    private function unlabelled(string $html): array
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $xp = new \DOMXPath($doc);
        $isField = fn (\DOMNode $n) => $n instanceof \DOMElement && in_array($n->nodeName, ['input', 'select', 'textarea'], true) && $n->hasAttribute('required');
        $hasClass = fn (\DOMNode $n, string $c) => $n instanceof \DOMElement && in_array($c, preg_split('/\s+/', (string) $n->getAttribute('class')), true);
        $containsField = fn (\DOMNode $n) => $n instanceof \DOMElement && $xp->query('.//input[@required]|.//select[@required]|.//textarea[@required]', $n)->length > 0;

        $missing = [];
        foreach ($xp->query('//input[@required]|//select[@required]|//textarea[@required]') as $f) {
            if (in_array(strtolower($f->getAttribute('type')), self::SKIP_TYPES, true)) {
                continue;
            }
            // skipped like the script: [data-no-star], wizard questions (.q-block has its own star)
            $skip = false;
            for ($p = $f->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
                if ($p->hasAttribute('data-no-star') || $hasClass($p, 'q-block')) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $found = $f->getAttribute('id') !== '' && $xp->query('//label[@for="'.$f->getAttribute('id').'"]')->length > 0;
            for ($p = $f->parentNode; ! $found && $p instanceof \DOMElement; $p = $p->parentNode) {
                if ($p->nodeName === 'label' && ! $hasClass($p, 'btn')) {
                    $found = true;
                }
            }
            // a field inside an input group / number stepper is looked up from that group (as in the script)
            $anchor = $f;
            for ($p = $f->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) {
                if ($hasClass($p, 'input-group') || $hasClass($p, 'num-stepper')) {
                    $anchor = $p;
                    break;
                }
            }
            for ($s = $anchor->previousSibling; ! $found && $s; $s = $s->previousSibling) {
                if ($s instanceof \DOMElement) {
                    if ($hasClass($s, 'form-label')) {
                        $found = true;
                    } elseif ($isField($s) || $containsField($s)) {
                        break;
                    }
                }
            }
            if (! $found && $anchor->parentNode instanceof \DOMElement) {
                foreach ($anchor->parentNode->childNodes as $c) {
                    if ($hasClass($c, 'form-label') && $xp->query('.//input[@required]|.//select[@required]|.//textarea[@required]', $anchor->parentNode)->length === 1) {
                        $found = true;
                    }
                }
            }
            if (! $found) {
                $missing[] = $f->nodeName.' name="'.$f->getAttribute('name').'"';
            }
        }

        return $missing;
    }

    public function test_required_fields_of_the_main_forms_have_a_label_for_the_star(): void
    {
        foreach (['login', 'register'] as $route) {
            $this->assertSame([], $this->unlabelled($this->get(route($route))->assertOk()->getContent()), $route);
        }

        $this->actingAs($this->admin());
        $pages = [
            route('admin.tlm.index'), route('admin.schools.create'), route('admin.locations.index'), route('admin.constituencies.index'),
            route('admin.notice-types.index'), route('holidays.index'), route('notices.create'), route('reports.coverage.settings'),
            route('today.settings'), route('profile'),
        ];
        foreach ($pages as $url) {
            $this->assertSame([], $this->unlabelled($this->get($url)->assertOk()->getContent()), $url);
        }
    }

    public function test_the_script_is_loaded_and_red_stars_share_one_style(): void
    {
        $this->assertStringContainsString("import './required-star'", file_get_contents(resource_path('js/app.js')));
        $this->assertSame(0, preg_match_all('#<span class="text-danger">\*</span>#', implode('', array_map('file_get_contents',
            glob(resource_path('views/{,*/,*/*/,*/*/*/}*.blade.php'), GLOB_BRACE)))), 'use <span class="req">*</span>');
    }
}

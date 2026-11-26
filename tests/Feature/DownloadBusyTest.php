<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\ExportJob;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 26-11-2026: download links stay busy until the file arrives. The page sends `_dl=<token>`; a file answer carries
 * the readable cookie si_dl=<token>, and the token never reaches the controller or a queued export's URL.
 */
class DownloadBusyTest extends TestCase
{
    public function test_a_file_answer_sets_the_download_cookie(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $school = $this->schoolIn($officer->currentJurisdiction->cluster);

        $res = $this->actingAs($officer)->get(route('schools.blank-form', $school).'?_dl=abc12345xyz')->assertOk();
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === 'si_dl');
        $this->assertNotNull($cookie);
        $this->assertSame('abc12345xyz', $cookie->getValue(), 'not encrypted: the page script reads it');
        $this->assertFalse($cookie->isHttpOnly());

        foreach (['', '?_dl=%3Cx%3E', '?_dl=short'] as $q) {
            $res = $this->get(route('schools.blank-form', $school).$q)->assertOk();
            $this->assertFalse(collect($res->headers->getCookies())->contains(fn ($c) => $c->getName() === 'si_dl'), $q);
        }

        // a page (not a file) gets no cookie
        $res = $this->get(route('schools.show', $school).'?_dl=abc12345xyz')->assertOk();
        $this->assertFalse(collect($res->headers->getCookies())->contains(fn ($c) => $c->getName() === 'si_dl'));
    }

    public function test_a_queued_export_does_not_keep_the_token_in_its_url(): void
    {
        config(['inspection.export_queue.enabled' => true]);
        Storage::fake('private');

        $this->actingAs($this->admin())->from(route('admin.schools.index'))
            ->get(route('admin.schools.export-pdf', ['status' => 'active']).'&_dl=abc12345xyz')
            ->assertRedirect(route('admin.schools.index'));

        $url = ExportJob::sole()->url;
        $this->assertStringNotContainsString('_dl', $url);
        $this->assertStringContainsString('status=active', $url);
    }
}

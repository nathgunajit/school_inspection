<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Cluster;
use App\Models\District;
use App\Models\Inspection;
use App\Models\School;
use App\Support\UrlId;
use Tests\TestCase;

/** V6 Module 4: record IDs in URLs are encrypted, tamper-proof tokens. */
class UrlEncryptionTest extends TestCase
{
    public function test_token_round_trips_and_is_typed_and_tamper_proof(): void
    {
        $token = UrlId::encode(School::class, 42);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        $this->assertSame($token, UrlId::encode(School::class, 42), 'deterministic');
        $this->assertNotSame($token, UrlId::encode(School::class, 43));
        $this->assertSame(42, UrlId::decode(School::class, $token));
        $this->assertNull(UrlId::decode(Inspection::class, $token), 'a School token does not open an Inspection');

        $tampered = substr($token, 0, 20).($token[20] === 'A' ? 'B' : 'A').substr($token, 21);
        $this->assertNull(UrlId::decode(School::class, $tampered));
        foreach (['42', '', 'abc', str_repeat('A', 43), $token.'x'] as $bad) {
            $this->assertNull(UrlId::decode(School::class, $bad));
        }
    }

    public function test_urls_carry_tokens_and_numeric_or_edited_ids_return_404(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $school = $this->schoolIn($cluster);
        $inspection = $this->startInspection($officer, $school);

        $url = route('schools.show', $school);
        $this->assertStringNotContainsString('/schools/'.$school->id, $url);
        $this->assertStringEndsWith('/schools/'.UrlId::encode(School::class, $school->id), $url);

        $this->actingAs($officer)->get($url)->assertOk();
        $this->actingAs($officer)->get('/schools/'.$school->id)->assertNotFound();
        $this->actingAs($officer)->get(route('inspections.step', [$inspection, 1]))->assertOk();
        $this->actingAs($officer)->get('/inspections/'.$inspection->id.'/step/1')->assertNotFound();

        // the inspection token used as a school token is refused
        $this->actingAs($officer)->get('/schools/'.$inspection->getRouteKey())->assertNotFound();
    }

    public function test_pages_do_not_expose_numeric_record_urls(): void
    {
        $cluster = Cluster::orderBy('id')->first();
        $officer = $this->officer(UserLevel::Cluster, $cluster);
        $school = $this->schoolIn($cluster);
        $this->startInspection($officer, $school);

        foreach ([route('schools.index'), route('schools.show', $school), route('inspections.mine'), route('dashboard')] as $page) {
            $html = $this->actingAs($officer)->get($page)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('#/(schools|inspections|evidence|schedules)/\d+(?:["/?]|$)#', $html, "numeric ID URL on $page");
        }

        $admin = $this->admin();
        foreach (['admin.users.index', 'admin.approvals.index', 'admin.schools.index', 'admin.imports.index', 'admin.mobile-requests.index', 'inspections.area', 'schedules.index'] as $name) {
            $html = $this->actingAs($admin)->get(route($name))->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('#/(schools|inspections|evidence|schedules|users|approvals|imports|mobile-requests)/\d+(?:["/?]|$)#', $html, "numeric ID URL on $name");
        }

        $rows = $this->actingAs($officer)->getJson(route('map.schools', ['cluster' => $cluster->id]))->assertOk()->json('rows');
        $row = collect($rows)->firstWhere('id', $school->id);
        $this->assertSame($school->getRouteKey(), $row['key']);
    }

    public function test_location_master_update_uses_a_token(): void
    {
        $district = District::orderBy('id')->first();
        $this->actingAs($this->admin())->put('/admin/locations/districts/'.$district->id, ['toggle' => 1])->assertNotFound();
        $this->actingAs($this->admin())->put(route('admin.locations.update', ['districts', UrlId::encode(District::class, $district->id)]), ['toggle' => 1])->assertRedirect();
        $this->assertNotSame($district->is_active, $district->fresh()->is_active);
    }
}

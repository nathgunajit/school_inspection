<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\InspectionClassroomObservation;
use App\Models\TlmItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** FLN TLM master (Documents/FLN TLM list_2026-27.docx): TLM items ticked on a classroom observation. */
class TlmItemsTest extends TestCase
{
    private function draft(): Inspection
    {
        $officer = $this->officer(UserLevel::Cluster);
        $i = $this->startInspection($officer, $this->schoolIn($officer->currentJurisdiction->cluster));
        $this->actingAs($officer);

        return $i;
    }

    private function classroom(Inspection $i, array $over = [])
    {
        return $this->postJson(route('inspections.classrooms.store', $i), $over + [
            'teacher_name' => 'B. Devi', 'class' => 'I', 'subject' => 'Mathematics', 'tlm_used' => 'yes', 'participation' => 'satisfactory',
        ]);
    }

    private function ids(string $class): array
    {
        return TlmItem::where('class', $class)->orderBy('sort')->pluck('id')->all();
    }

    public function test_the_list_from_the_document_is_seeded(): void
    {
        $this->assertSame(6, TlmItem::where('class', 'I')->count());
        $this->assertSame(5, TlmItem::where('class', 'II')->count());
        $this->assertTrue(TlmItem::where('class', 'I')->where('code', 'NIPUN AXOM G1L4')->where('name', 'Karjyapatra (Literacy)')->exists());
        $this->assertSame(['I'], TlmItem::forClass('I')->pluck('class')->unique()->values()->all());
        $this->assertSame(0, TlmItem::forClass('V')->count());
    }

    public function test_tlm_yes_needs_at_least_one_item_from_the_whole_list(): void
    {
        $i = $this->draft();
        $this->classroom($i)->assertStatus(422)->assertJsonPath('errors.tlm_items.0', 'Tick at least one TLM item used.');
        // every active item is offered, whatever the class observed (grouped by class on the form)
        $this->classroom($i, ['class' => 'V'])->assertStatus(422)->assertJsonValidationErrors('tlm_items');
        $this->classroom($i, ['class' => 'V', 'tlm_items' => [$this->ids('II')[0]]])->assertOk();
        $this->classroom($i, ['tlm_items' => [999999]])->assertStatus(422)->assertJsonValidationErrors('tlm_items.0');

        [$a, $b] = $this->ids('I');
        $r = $this->classroom($i, ['tlm_items' => [$a, $b]])->assertOk();
        $row = InspectionClassroomObservation::where('inspection_id', $i->id)->latest('id')->first();
        $this->assertEqualsCanonicalizing([$a, $b], $row->tlmItems()->pluck('tlm_items.id')->all());
        $this->assertSame('Yes · 2 items', $r->json('classrooms.1.tlm'));
        $this->assertCount(2, $r->json('classrooms.1.tlm_items'));
        $this->assertEqualsCanonicalizing([$a, $b], $r->json('classrooms.1.values.tlm_items'));
    }

    public function test_tlm_no_needs_no_items_and_clears_them(): void
    {
        $i = $this->draft();
        $this->classroom($i, ['class' => 'V', 'tlm_used' => 'no'])->assertOk();
        $this->classroom($i, ['tlm_used' => 'no', 'tlm_items' => [$this->ids('I')[0]]])->assertOk();
        $this->assertSame(0, DB::table('inspection_classroom_tlm')->count());
    }

    public function test_update_resyncs_and_no_clears_and_replay_does_not_duplicate(): void
    {
        $i = $this->draft();
        $ids = $this->ids('I');
        $uid = (string) Str::uuid();
        $this->classroom($i, ['tlm_items' => [$ids[0]], 'row_uid' => $uid])->assertOk();
        $this->classroom($i, ['tlm_items' => [$ids[0]], 'row_uid' => $uid])->assertOk(); // offline replay
        $this->assertSame(1, $i->classroomObservations()->count());
        $row = $i->classroomObservations()->first();
        $this->assertSame(1, $row->tlmItems()->count());

        $base = ['teacher_name' => 'B. Devi', 'class' => 'I', 'subject' => 'Maths', 'participation' => 'satisfactory'];
        $this->putJson(route('inspections.classrooms.update', [$i, $row]), $base + ['tlm_used' => 'yes', 'tlm_items' => [$ids[1], $ids[2]]])->assertOk();
        $this->assertEqualsCanonicalizing([$ids[1], $ids[2]], $row->tlmItems()->pluck('tlm_items.id')->all());

        $this->putJson(route('inspections.classrooms.update', [$i, $row]), $base + ['tlm_used' => 'no'])->assertOk();
        $this->assertSame(0, $row->tlmItems()->count());
    }

    public function test_inactive_item_is_not_offered_but_old_rows_keep_it(): void
    {
        $i = $this->draft();
        $item = TlmItem::where('class', 'I')->orderBy('sort')->first();
        $this->classroom($i, ['tlm_items' => [$item->id]])->assertOk();
        $row = $i->classroomObservations()->first();

        $item->update(['is_active' => false]);
        $this->classroom($i, ['tlm_items' => [$item->id]])->assertStatus(422);
        // editing the old row keeps the item
        $this->putJson(route('inspections.classrooms.update', [$i, $row]), ['teacher_name' => 'B. Devi', 'class' => 'I', 'subject' => 'Maths',
            'tlm_used' => 'yes', 'tlm_items' => [$item->id], 'participation' => 'satisfactory'])->assertOk();
        $this->assertSame(1, $row->tlmItems()->count());

        $this->get(route('inspections.step', [$i, 4]))->assertOk()->assertSee('id="tlm-items"', false)
            ->assertViewHas('tlmItems', fn ($t) => count($t) === 10 && ! in_array($item->id, array_column($t, 'id'), true));
    }

    public function test_report_and_pdf_show_the_items(): void
    {
        $i = $this->draft();
        $this->classroom($i, ['tlm_items' => [$this->ids('I')[1]]])->assertOk();
        $this->get(route('inspections.review', $i))->assertOk()->assertSee('Karjyapatra (Literacy) · NIPUN AXOM G1L4');
        $row = $i->classroomObservations()->first();
        $this->assertSame('Yes: Karjyapatra (Literacy) · NIPUN AXOM G1L4', $row->tlmText());
        $this->get(route('inspections.pdf', $i))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_admin_can_add_edit_and_deactivate_items(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.tlm.index'))->assertOk()->assertSee('Karjyapatra (Vidya Pravesh)')->assertSee('TLM Master');
        $this->actingAs($admin)->get(route('admin.tlm.index', ['class' => 'II']))->assertOk()->assertDontSee('Vidya Pravesh');

        $this->actingAs($admin)->post(route('admin.tlm.store'), ['class' => 'III', 'name' => 'Number cards', 'code' => 'G3N1', 'variety' => 2])->assertRedirect();
        $new = TlmItem::where('name', 'Number cards')->firstOrFail();
        $this->assertSame('III', $new->class);
        $this->actingAs($admin)->post(route('admin.tlm.store'), ['class' => 'III', 'name' => 'Number cards'])->assertSessionHasErrors('name');
        $this->actingAs($admin)->post(route('admin.tlm.store'), ['class' => 'all', 'name' => 'Story charts'])->assertRedirect();
        $this->assertNull(TlmItem::where('name', 'Story charts')->value('class'));
        $this->assertContains(TlmItem::where('name', 'Story charts')->value('id'), TlmItem::forClass('VII')->pluck('id')->all());

        $this->actingAs($admin)->put(route('admin.tlm.update', $new), ['class' => 'IV', 'name' => 'Number cards (big)', 'variety' => 1])->assertRedirect();
        $this->assertSame('IV', $new->fresh()->class);
        $this->actingAs($admin)->put(route('admin.tlm.update', $new), ['toggle' => 1])->assertRedirect();
        $this->assertFalse($new->fresh()->is_active);
        $this->assertSame(1, AuditLog::where('action', 'tlm_item_deactivated')->count());
        $this->actingAs($admin)->put('/admin/tlm-items/'.$new->id, ['toggle' => 1])->assertNotFound(); // encrypted IDs only
    }

    public function test_officer_cannot_manage_the_master(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $this->actingAs($officer)->get(route('admin.tlm.index'))->assertForbidden();
        $this->actingAs($officer)->post(route('admin.tlm.store'), ['class' => 'I', 'name' => 'X item'])->assertForbidden();
    }
}

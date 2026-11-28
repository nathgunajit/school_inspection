<?php

namespace Tests\Feature;

use App\Enums\UserLevel;
use App\Models\Inspection;
use App\Models\MasterValue;
use App\Models\School;
use App\Models\TlmItem;
use Tests\TestCase;

/** 28-11-2026: TLM (FLN / ECCE) items belong to school categories; step 4 offers only the items of the school's category. */
class TlmCategoryTest extends TestCase
{
    private function cat(string $code): int
    {
        return (int) MasterValue::where('type', 'category')->where('code', $code)->value('id');
    }

    public function test_admin_sets_school_categories_on_an_item(): void
    {
        $admin = $this->admin();
        $primary = $this->cat('primary');
        $upper = $this->cat('primary_upper');
        $this->actingAs($admin)->post(route('admin.tlm.store'), ['list' => 'ecce', 'class' => 'all', 'name' => 'Picture Book', 'category_ids' => [$upper, $primary]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $item = TlmItem::where('name', 'Picture Book')->firstOrFail();
        $this->assertSame([min($primary, $upper), max($primary, $upper)], $item->category_ids);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tlm_item_created', 'auditable_id' => $item->id]);
        $this->get(route('admin.tlm.index', ['list' => 'ecce']))->assertOk()->assertSee('School categories')
            ->assertSee(MasterValue::find($primary)->label)->assertSee('name="category_ids[]"', false);

        // none ticked = every category
        $this->put(route('admin.tlm.update', $item), ['list' => 'ecce', 'class' => 'all', 'name' => 'Picture Book', '_edit' => $item->id])->assertRedirect();
        $this->assertNull($item->fresh()->category_ids);
        $this->assertTrue($item->fresh()->appliesTo($this->cat('secondary_only')));

        // only school categories are accepted
        $other = MasterValue::where('type', '!=', 'category')->value('id');
        $this->put(route('admin.tlm.update', $item), ['list' => 'ecce', 'class' => 'all', 'name' => 'Picture Book', '_edit' => $item->id, 'category_ids' => [$other]])
            ->assertSessionHasErrorsIn('edit', 'category_ids.0');
    }

    public function test_step_four_offers_only_the_items_of_the_school_category(): void
    {
        $primary = $this->cat('primary');
        $secOnly = $this->cat('secondary_only');
        $forPrimary = TlmItem::create(['list' => 'ecce', 'class' => null, 'name' => 'Primary Only Card', 'category_ids' => [$primary], 'is_active' => true]);
        $forSecondary = TlmItem::create(['list' => 'fln', 'class' => null, 'name' => 'Secondary Only Chart', 'category_ids' => [$secOnly], 'is_active' => true]);
        $forAll = TlmItem::create(['list' => 'fln', 'class' => null, 'name' => 'Every School Kit', 'is_active' => true]);

        $officer = $this->officer(UserLevel::Cluster);
        $school = $this->schoolIn($officer->currentJurisdiction->cluster);
        $school->update(['category_id' => $primary]);
        $i = $this->startInspection($officer, $school);

        $ids = collect($this->actingAs($officer)->get(route('inspections.step', [$i, 4]))->assertOk()->viewData('tlmItems'))->pluck('id');
        $this->assertTrue($ids->contains($forPrimary->id));
        $this->assertTrue($ids->contains($forAll->id));
        $this->assertFalse($ids->contains($forSecondary->id));

        $row = ['teacher_name' => 'Bijoy Das', 'class' => 'V', 'subject' => 'Mathematics', 'tlm_used' => 'yes', 'participation' => 'satisfactory'];
        $this->postJson(route('inspections.classrooms.store', $i), $row + ['tlm_items' => [$forSecondary->id]])
            ->assertStatus(422)->assertJsonValidationErrors('tlm_items.0');
        $this->postJson(route('inspections.classrooms.store', $i), $row + ['tlm_items' => [$forPrimary->id, $forAll->id]])->assertOk();

        // a row that already has an item keeps it after the item is narrowed to other categories
        $obs = $i->classroomObservations()->firstOrFail();
        $forPrimary->update(['category_ids' => [$secOnly]]);
        $this->putJson(route('inspections.classrooms.update', [$i, $obs]), $row + ['tlm_items' => [$forPrimary->id]])->assertOk();

        // a school without a category sees every active item
        $school->update(['category_id' => null]);
        $ids = collect($this->get(route('inspections.step', [$i, 4]))->assertOk()->viewData('tlmItems'))->pluck('id');
        $this->assertTrue($ids->contains($forSecondary->id));
    }
}

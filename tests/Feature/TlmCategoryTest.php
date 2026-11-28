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

    /** 28-11-2026: every TLM list is for lower primary (the categories with Class 1–5). */
    public function test_tlm_items_default_to_lower_primary_categories(): void
    {
        $lower = array_map(fn ($c) => $this->cat($c), ['primary', 'primary_upper', 'secondary_full', 'pr_to_hsec']);
        sort($lower);
        $this->assertSame($lower, TlmItem::lowerPrimaryCategoryIds());

        $narrowed = TlmItem::create(['list' => 'fln', 'class' => null, 'name' => 'Primary Chart', 'category_ids' => [$this->cat('primary')], 'is_active' => true]);
        $untagged = TlmItem::whereNull('category_ids')->count();
        $this->assertGreaterThan(0, $untagged);
        $this->assertSame($untagged, TlmItem::applyLowerPrimaryDefault());
        $this->assertSame(0, TlmItem::whereNull('category_ids')->count());
        $this->assertSame([$this->cat('primary')], $narrowed->fresh()->category_ids, 'an item already narrowed is left alone');
        $this->assertSame(0, TlmItem::applyLowerPrimaryDefault(), 'runs once');

        // an Upper Primary only school gets no TLM list; TLM = Yes then needs no tick, and the form says why
        $officer = $this->officer(UserLevel::Cluster);
        $school = $this->schoolIn($officer->currentJurisdiction->cluster);
        $school->update(['category_id' => $this->cat('upper_primary')]);
        $i = $this->startInspection($officer, $school);
        $step = $this->actingAs($officer)->get(route('inspections.step', [$i, 4]))->assertOk()->assertSee('id="tlm-none"', false);
        $this->assertSame([], $step->viewData('tlmItems'));
        $this->postJson(route('inspections.classrooms.store', $i), ['teacher_name' => 'Bijoy Das', 'class' => 'VII', 'subject' => 'Science', 'tlm_used' => 'yes', 'participation' => 'satisfactory'])->assertOk();

        // the TLM master Add form starts with the lower-primary categories ticked
        $html = $this->actingAs($this->admin())->get(route('admin.tlm.index'))->assertOk()->getContent();
        foreach ($lower as $id) {
            $this->assertMatchesRegularExpression('/id="tlm-cat-new-'.$id.'" checked/', $html);
        }
        $this->assertDoesNotMatchRegularExpression('/id="tlm-cat-new-'.$this->cat('secondary_only').'" checked/', $html);
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

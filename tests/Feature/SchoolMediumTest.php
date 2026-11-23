<?php

namespace Tests\Feature;

use App\Models\School;
use Tests\TestCase;

/** 23-11-2026: a school may teach in more than one medium, stored as "Assamese, Bodo"; the inspection PDF shows limited school data. */
class SchoolMediumTest extends TestCase
{
    public function test_medium_text_is_normalised_to_a_list(): void
    {
        $cases = [
            'AssameseBodo' => 'Assamese, Bodo',
            'Assamese,Bodo' => 'Assamese, Bodo',
            'AssameseBodoEnglish' => 'Assamese, Bodo, English',
            'BengaliEnglishBishnupriya manipuri' => 'Bengali, English, Bishnupriya Manipuri',
            'Bengali EnglishManipuri' => 'Bengali, English, Manipuri',
            'assamese / BODO' => 'Assamese, Bodo',
            'AssameseRabha' => 'Assamese, Rabha',
            'Assamese, Bodo' => 'Assamese, Bodo',
        ];
        foreach ($cases as $in => $out) {
            $this->assertSame($out, School::normaliseMedium($in), $in);
        }
        $this->assertSame('Assamese, Bodo, Rabha', School::normaliseMedium(['Assamese', 'Bodo', 'Assamese', ' rabha ']));
        $this->assertNull(School::normaliseMedium(null));
        $this->assertNull(School::normaliseMedium(''));
        $this->assertNull(School::normaliseMedium(['', null]));
        $this->assertSame(['Assamese', 'Bodo'], (new School(['medium' => 'AssameseBodo']))->mediums());
    }

    public function test_school_master_saves_two_mediums_and_filters_by_either(): void
    {
        $this->actingAs($this->admin());
        $s = School::orderBy('id')->firstOrFail();
        $payload = ['udise_code' => $s->udise_code, 'name' => $s->name, 'district_id' => $s->district_id, 'block_id' => $s->block_id,
            'cluster_id' => $s->cluster_id, 'status' => 'active'];

        $this->put(route('admin.schools.update', $s), $payload + ['medium' => ['Assamese', 'Bodo'], 'medium_other' => 'Rabha'])
            ->assertRedirect(route('admin.schools.index'));
        $this->assertSame('Assamese, Bodo, Rabha', $s->fresh()->medium);

        $html = $this->get(route('admin.schools.edit', $s))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="medium\[\]" value="Assamese" id="med_\d+"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/name="medium\[\]" value="Bodo" id="med_\d+"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="medium\[\]" value="Bengali" id="med_\d+"\s+checked/', $html);
        $this->assertStringContainsString('name="medium_other" value="Rabha"', $html);

        foreach (['Bodo', 'Assamese'] as $m) {
            $this->get(route('admin.schools.index', ['medium' => $m, 'q' => $s->udise_code]))->assertOk()->assertSee(e($s->name), false);
        }
        $this->get(route('admin.schools.index', ['medium' => 'Garo', 'q' => $s->udise_code]))->assertOk()->assertDontSee(e($s->name), false); // q stays in the search box, so check the name

        // no tick and no other text clears the medium
        $this->put(route('admin.schools.update', $s), $payload)->assertRedirect(route('admin.schools.index'));
        $this->assertNull($s->fresh()->medium);
    }

    public function test_inspection_pdf_prints_the_medium_but_not_type_or_area(): void
    {
        $view = file_get_contents(resource_path('views/inspections/pdf.blade.php'));
        $this->assertStringNotContainsString('school_type_label', $view);
        $this->assertStringNotContainsString('area_type_label', $view);
        $this->assertStringContainsString('Medium: {{ $s->medium_label }}', $view);
    }
}

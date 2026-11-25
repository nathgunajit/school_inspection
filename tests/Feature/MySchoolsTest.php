<?php

namespace Tests\Feature;

use App\Enums\InspectionStatus;
use App\Enums\UserLevel;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\School;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/** 23-11-2026: My Schools lists every school in the officer's own jurisdiction, without a search. */
class MySchoolsTest extends TestCase
{
    private function names(string $html): array
    {
        return School::pluck('name')->filter(fn ($n) => str_contains($html, e($n)))->values()->all();
    }

    public function test_each_level_sees_only_its_own_schools(): void
    {
        $cluster = Cluster::orderBy('id')->firstOrFail();
        $other = $this->otherCluster($cluster);
        $expect = [
            'cluster' => School::where('cluster_id', $cluster->id),
            'block' => School::where('block_id', $cluster->block_id),
            'district' => School::where('district_id', $cluster->block->district_id),
            'state' => School::query(),
        ];

        foreach ([UserLevel::Cluster, UserLevel::Block, UserLevel::District, UserLevel::State] as $level) {
            $officer = $this->officer($level, $cluster);
            $res = $this->actingAs($officer)->get(route('schools.mine'))->assertOk();
            $html = $res->getContent();
            $mine = (clone $expect[$level->value])->pluck('name')->all();
            $this->assertNotEmpty($mine);
            foreach ($mine as $n) {
                $this->assertStringContainsString(e($n), $html, "$level->value sees $n");
            }
            $res->assertViewHas('stats', fn ($s) => $s['total'] === count($mine) && $s['not_inspected'] === count($mine));
            if ($level !== UserLevel::State) {
                $this->assertStringNotContainsString(e($this->schoolIn($other)->name), $html, "$level->value does not see another district");
            }
        }
        $this->get(route('schools.mine'))->assertSee('Whole state');
    }

    public function test_inspected_filter_and_scope_cannot_be_widened(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $cluster = $officer->currentJurisdiction->cluster;
        $done = $this->schoolIn($cluster);
        $i = $this->startInspection($officer, $done);
        $i->forceFill(['status' => InspectionStatus::Submitted, 'submitted_at' => now(), 'inspection_no' => 'T/'.$i->id])->save();
        $todo = School::where('cluster_id', $cluster->id)->whereKeyNot($done->id)->firstOrFail();

        $this->actingAs($officer)->get(route('schools.mine'))->assertOk()
            ->assertViewHas('stats', fn ($s) => $s['inspected'] === 1 && $s['this_month'] === 1)
            ->assertSee('1 time');
        $this->get(route('schools.mine', ['inspected' => 'yes']))->assertSee(e($done->name), false)->assertDontSee(e($todo->name), false);
        $this->get(route('schools.mine', ['inspected' => 'no']))->assertSee(e($todo->name), false)->assertDontSee(e($done->name), false);

        // a cluster outside the jurisdiction only narrows the scope: nothing is listed
        $other = $this->otherCluster($cluster);
        $this->get(route('schools.mine', ['cluster_id' => $other->id]))->assertOk()
            ->assertDontSee(e($this->schoolIn($other)->name), false)->assertSee('No schools match');
    }

    public function test_excel_download_is_scoped_and_audited(): void
    {
        Excel::fake();
        $officer = $this->officer(UserLevel::Cluster);
        $this->actingAs($officer)->get(route('schools.mine.export', ['inspected' => 'no']))->assertOk();
        Excel::assertDownloaded('my-schools-'.now()->format('Y-m-d').'.xlsx', function (\App\Exports\MySchoolsExport $e) use ($officer) {
            $rows = $e->query()->get();

            return $rows->isNotEmpty() && $rows->every(fn ($s) => $s->cluster_id === $officer->currentJurisdiction->cluster_id);
        });
        $this->assertTrue(AuditLog::where('action', 'my_schools_exported')->exists());
    }

    public function test_excel_has_the_official_header_and_is_read_only(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $cluster = $officer->currentJurisdiction->cluster;
        $res = $this->actingAs($officer)->get(route('schools.mine.export', ['inspected' => 'no']))->assertOk();
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($res->getFile()->getPathname());
        $sheet = $book->getActiveSheet();

        $this->assertSame('GOVERNMENT OF ASSAM', $sheet->getCell('A1')->getValue());
        $this->assertSame('MY SCHOOLS', $sheet->getCell('A3')->getValue());
        $this->assertStringContainsString('Cluster · '.$cluster->label, $sheet->getCell('A4')->getValue());
        $this->assertStringContainsString($officer->name, $sheet->getCell('A4')->getValue());
        $this->assertStringContainsString('Inspected: No', $sheet->getCell('A5')->getValue());
        $this->assertSame('UDISE Code', $sheet->getCell('A6')->getValue());
        $this->assertSame(School::where('cluster_id', $cluster->id)->count(), $sheet->getHighestRow() - 6);
        $this->assertContains((string) $sheet->getCell('A7')->getValue(), School::where('cluster_id', $cluster->id)->pluck('udise_code')->map(fn ($c) => (string) $c)->all());
        $this->assertTrue($sheet->getProtection()->getSheet(), 'the sheet is protected');
        $this->assertTrue($book->getSecurity()->getLockStructure(), 'the workbook structure is locked');

        // no Medium column; widths follow the content and long text wraps
        $this->assertSame(\App\Exports\MySchoolsExport::HEADINGS, $sheet->rangeToArray('A6:J6')[0]);
        $this->assertNotContains('Medium', $sheet->rangeToArray('A6:K6')[0]);
        $longest = School::where('cluster_id', $cluster->id)->get()->max(fn ($s) => mb_strlen($s->name));
        $this->assertGreaterThanOrEqual(min(55, $longest + 2), $sheet->getColumnDimension('B')->getWidth());
        $this->assertTrue($sheet->getStyle('B7')->getAlignment()->getWrapText());
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4, $sheet->getPageSetup()->getPaperSize());

        // each logo stays inside its column and inside rows 1–3
        $drawings = $sheet->getDrawingCollection();
        $this->assertCount(2, $drawings);
        $headerPx = array_sum(array_map(fn ($r) => $sheet->getRowDimension($r)->getRowHeight(), [1, 2, 3])) * 96 / 72;
        foreach ($drawings as $d) {
            $col = preg_replace('/\d+/', '', $d->getCoordinates());
            $colPx = \PhpOffice\PhpSpreadsheet\Shared\Drawing::cellDimensionToPixels($sheet->getColumnDimension($col)->getWidth(), $book->getDefaultStyle()->getFont());
            $this->assertLessThanOrEqual($colPx, $d->getOffsetX() + $d->getWidth(), $d->getName().' fits its column');
            $this->assertLessThanOrEqual($headerPx, $d->getOffsetY() + $d->getHeight(), $d->getName().' stays in the header rows');
        }
    }

    public function test_pdf_download_is_audited_and_capped(): void
    {
        $officer = $this->officer(UserLevel::Cluster);
        $this->actingAs($officer)->get(route('schools.mine'))->assertSee(route('schools.mine.export-pdf'), false);
        $res = $this->get(route('schools.mine.export-pdf'))->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertTrue(AuditLog::where('action', 'my_schools_exported')->get()->contains(fn ($a) => ($a->meta['format'] ?? null) === 'pdf'));

        config(['inspection.master_pdf_max_rows' => 1]);
        $this->from(route('schools.mine'))->get(route('schools.mine.export-pdf'))
            ->assertRedirect(route('schools.mine'))->assertSessionHas('warning');
    }

    public function test_sidebar_links_my_schools(): void
    {
        $html = $this->actingAs($this->officer(UserLevel::Block))->get(route('schools.mine'))->assertOk()->getContent();
        // 25-11-2026: one "Schools" item (lit on My Schools too); the page tabs pick Find School | My Schools
        $this->assertMatchesRegularExpression('/href="[^"]*\/schools" class="nav-link active"/', $html);
        $this->assertMatchesRegularExpression('/class="nav-link active"\s+aria-current="page"\s+href="[^"]*my-schools"/', $html);
        $this->assertDoesNotMatchRegularExpression('/class="nav-link active"\s+aria-current="page"\s+href="[^"]*\/schools"/', $html, 'the Find School tab is not lit on My Schools');
    }
}

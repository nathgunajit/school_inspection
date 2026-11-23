<?php

namespace App\Exports;

use App\Models\School;
use App\Services\MasterImportService;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Modification V13 item 2: the School Master list as Excel, in the upload column order
 * (MasterImportService::SCHOOL_COLUMNS), so the file can be edited and uploaded again.
 * Read in chunks, so a state-wide list (44,000 rows) does not load at once.
 */
class SchoolListExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    private array $category;

    private array $management;

    public function __construct(private Builder $query)
    {
        // "N - Label" for the UDISE-numbered master values, as in the template's Value lists
        $this->category = array_flip(MasterImportService::UDISE_CATEGORY);
        $this->management = array_flip(MasterImportService::UDISE_MANAGEMENT);
    }

    public function query()
    {
        return $this->query->with(['district:id,code', 'block:id,code', 'cluster:id,code', 'category:id,code,label', 'management:id,code,label', 'ac:id,code', 'pc:id,code'])
            ->orderBy('id');
    }

    public function headings(): array
    {
        return MasterImportService::SCHOOL_COLUMNS;
    }

    /** @param School $s */
    public function map($s): array
    {
        $numbered = fn ($mv, array $nos) => $mv ? (isset($nos[$mv->code]) ? $nos[$mv->code].' - '.$mv->label : $mv->label) : '';
        $stream = fn (string $k) => $s->streams === null ? 'NA' : (! empty($s->streams[$k]) ? '1 - Yes' : '2 - No');
        $row = [
            'udise_code' => $s->udise_code,
            'school_name' => $s->name,
            'district_code' => $s->district?->code,
            'block_code' => $s->block?->code,
            'cluster_code' => $s->cluster?->code,
            'category' => $numbered($s->category, $this->category),
            'management' => $numbered($s->management, $this->management),
            'school_type' => ['boys' => '1 - Boys', 'girls' => '2 - Girls', 'co_ed' => '3 - Co-ed'][$s->school_type] ?? '',
            'area_type' => ['rural' => '1-Rural', 'urban' => '2-Urban'][$s->area_type] ?? '',
            'medium' => $s->medium,
            'ac' => $s->ac?->code,
            'pc' => $s->pc?->code,
            'arts_stream' => $stream('arts'),
            'commerce_stream' => $stream('commerce'),
            'science_stream' => $stream('science'),
            'vocational_stream' => $stream('vocational'),
            'other_stream' => $stream('other'),
            'address' => $s->address,
            'pincode' => $s->pincode,
            'status' => $s->status,
            'latitude' => $s->latitude,
            'longitude' => $s->longitude,
        ];

        return array_map(fn ($c) => $row[$c] ?? '', MasterImportService::SCHOOL_COLUMNS);
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '4338CA']]]];
    }
}

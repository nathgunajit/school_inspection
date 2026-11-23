<?php

namespace App\Exports;

use App\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 23-11-2026: My Schools as Excel, the officer's own schools with how often they were inspected.
 * The query comes from SchoolController (jurisdiction scope, filters, visit order); read in chunks.
 */
class MySchoolsExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    public function __construct(private Builder $query) {}

    public function query()
    {
        return $this->query->with(['district:id,name,code', 'block:id,name,code', 'cluster:id,name,code', 'category:id,label', 'management:id,label']);
    }

    public function headings(): array
    {
        return ['UDISE Code', 'School', 'District', 'Block', 'Cluster', 'Category', 'Management', 'Medium', 'Status', 'Times inspected', 'Last inspected'];
    }

    /** @param School $s */
    public function map($s): array
    {
        return [
            $s->udise_code, $s->name, $s->district?->label, $s->block?->label, $s->cluster?->label,
            $s->category?->label, $s->management?->label, $s->medium_label, ucfirst((string) $s->status),
            (int) $s->visits,
            $s->last_inspected_on ? Carbon::parse($s->last_inspected_on)->format('d-m-Y') : 'Not inspected yet',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}

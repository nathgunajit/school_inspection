<?php

namespace App\Exports;

use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** Modification V13 item 4: audit entries saved to Excel before they are purged (read in chunks). */
class AuditArchiveExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private int $maxId, private Carbon $cutoff) {}

    public function query()
    {
        return AuditLog::query()->with('user:id,name,mobile')
            ->where('id', '<=', $this->maxId)->where('created_at', '<', $this->cutoff)->orderBy('id');
    }

    public function headings(): array
    {
        return ['id', 'date_time', 'user', 'mobile', 'action', 'record_type', 'record_id', 'details', 'ip_address', 'user_agent'];
    }

    /** @param AuditLog $l */
    public function map($l): array
    {
        return [$l->id, $l->created_at?->format('Y-m-d H:i:s'), $l->user?->name, $l->user?->mobile, $l->action, $l->auditable_type, $l->auditable_id,
            $l->meta ? json_encode($l->meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '', $l->ip_address, $l->user_agent];
    }
}

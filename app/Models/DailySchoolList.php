<?php

namespace App\Models;

use App\Models\Concerns\HasEncryptedRouteKey;
use Illuminate\Database\Eloquent\Model;

/** V14 item 5: one school on an officer's "Today's schools" list for a day. */
class DailySchoolList extends Model
{
    use HasEncryptedRouteKey;

    public const REASONS = [
        'not_inspected' => 'Not inspected yet', 'low_rating' => 'Low rating', 'issue' => 'Open issues', 'less_visited' => 'Not visited for long',
        'random' => 'Random pick', 'carried' => 'Pending from earlier', 'manual' => 'Assigned',
    ];

    protected $fillable = ['officer_id', 'list_date', 'school_id', 'position', 'reason', 'source', 'status', 'carried_from', 'skip_reason', 'inspection_id', 'assigned_by'];

    public function officer() { return $this->belongsTo(User::class, 'officer_id'); }
    public function school() { return $this->belongsTo(School::class); }
    public function inspection() { return $this->belongsTo(Inspection::class); }
    public function assigner() { return $this->belongsTo(User::class, 'assigned_by'); }

    public function getListDateAttribute($v): ?string { return $v ? substr((string) $v, 0, 10) : null; }
    public function getCarriedFromAttribute($v): ?string { return $v ? substr((string) $v, 0, 10) : null; }

    /** The reason chip; "Low rating" reads "Needs support" while ratings are hidden from users (V9 switch). */
    public function reasonLabel(): string
    {
        if ($this->reason === 'low_rating' && ! config('inspection.rating.display')) {
            return 'Needs support';
        }

        return self::REASONS[$this->reason] ?? ucfirst(str_replace('_', ' ', $this->reason));
    }
}

<?php

namespace App\Services;

use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides whether an officer may start an inspection of a school (Modification V3 items 3, 4).
 * Rules, in order (cancelled inspections never count):
 *  1. own_draft   the officer has an open draft (draft / unlocked, any date) → reopen it, never a second one
 *  2. own_today   the officer already submitted this school today → show that report (one per officer, school, day)
 *  3. other_draft another officer has an open draft → blocked ("being inspected by …")
 *  4. other_month another officer submitted it in the current calendar month → blocked until the 1st of next month
 * Returns null when a new inspection may be started.
 */
class InspectionStartGuard
{
    public const OWN_DRAFT = 'own_draft';
    public const OWN_TODAY = 'own_today';
    public const OTHER_DRAFT = 'other_draft';
    public const OTHER_MONTH = 'other_month';

    /** @return array{type: string, inspection: Inspection, blocked: bool, message: string}|null */
    public function check(User $officer, School $school): ?array
    {
        $base = fn () => Inspection::query()->where('school_id', $school->id)->with('officer.designation');
        $open = [InspectionStatus::Draft, InspectionStatus::Unlocked];

        if ($i = $base()->where('officer_id', $officer->id)->whereIn('status', $open)->latest('inspection_date')->first()) {
            return $this->result(self::OWN_DRAFT, $i, false, $i->inspection_date->isToday()
                ? 'Your draft for this school has been reopened.'
                : 'Your draft for this school from '.$i->inspection_date->format('d M Y').' has been reopened. Finish it before starting a new one.');
        }
        if ($i = $base()->where('officer_id', $officer->id)->where('status', InspectionStatus::Submitted)->where('inspection_date', today()->toDateString())->first()) {
            return $this->result(self::OWN_TODAY, $i, false, 'You have already submitted an inspection of this school today.');
        }
        if ($i = $base()->where('officer_id', '!=', $officer->id)->where('status', InspectionStatus::Draft)->latest('started_at')->first()) {
            return $this->result(self::OTHER_DRAFT, $i, true, 'This school is being inspected by '.$this->who($i)
                .'. Their draft was started on '.$i->started_at->format('d M Y').'. You cannot start another inspection of this school.');
        }
        if ($i = $this->completedByOthersThisMonth($officer, $base())->first()) {
            return $this->result(self::OTHER_MONTH, $i, true, 'This school was already inspected by '.$this->who($i)
                .' on '.$i->inspection_date->format('d M Y').($i->inspection_no ? ' ('.$i->inspection_no.')' : '')
                .'. It can be inspected again from '.self::reopensOn()->format('d M Y').'.');
        }

        return null;
    }

    /** First day on which a school completed this month is open again. */
    public static function reopensOn(): \Illuminate\Support\Carbon
    {
        return today()->addMonthNoOverflow()->startOfMonth();
    }

    /** Scope for the school search badges: another officer's open draft. */
    public static function otherDraft(User $officer): \Closure
    {
        return fn (Builder $q) => $q->where('officer_id', '!=', $officer->id)->where('status', InspectionStatus::Draft);
    }

    /** Scope for the school search badges: completed by another officer this calendar month. */
    public static function otherThisMonth(User $officer): \Closure
    {
        return fn (Builder $q) => $q->where('officer_id', '!=', $officer->id)
            ->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked])
            ->whereBetween('inspection_date', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()]);
    }

    private function completedByOthersThisMonth(User $officer, Builder $q): Builder
    {
        return (self::otherThisMonth($officer))($q)->latest('inspection_date');
    }

    private function who(Inspection $i): string
    {
        $o = $i->officer;

        return $o ? $o->name.($o->designation_label ? ' ('.$o->designation_label.')' : '') : 'another officer';
    }

    private function result(string $type, Inspection $i, bool $blocked, string $message): array
    {
        return ['type' => $type, 'inspection' => $i, 'blocked' => $blocked, 'message' => $message];
    }
}

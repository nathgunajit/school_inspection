<?php

namespace App\Services;

use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Models\InspectionStartSetting;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides whether an officer may start an inspection of a school (Modification V3 items 3, 4).
 * Rules, in order (cancelled inspections never count):
 *  1. own_draft         the officer has an open draft (draft / unlocked, any date) → reopen it, never a second one
 *  2. own_today         the officer already submitted this school today → show that report (one per officer, school, day)
 *  3. other_draft       another officer OF THE SAME LEVEL has an open draft → blocked ("being inspected by …")
 *  4. other_month       another officer OF THE SAME LEVEL submitted it inside the block window → blocked until the window ends.
 *                       26-11-2026: the window is the State Admin's choice (InspectionStartSetting: month / week / day / none).
 *  5. other_level_today 26-11-2026, only when the State Admin switched off "different levels on the same day":
 *                       an officer of ANOTHER level inspects (or inspected) the school today → blocked for today.
 * 25-11-2026: officers of different levels (Cluster, Block, District…) may inspect the same school (InspectionVisibility::sameLevel).
 * Returns null when a new inspection may be started.
 */
class InspectionStartGuard
{
    public const OWN_DRAFT = 'own_draft';
    public const OWN_TODAY = 'own_today';
    public const OTHER_DRAFT = 'other_draft';
    public const OTHER_MONTH = 'other_month'; // the block window (kept name: month was the only choice before 26-11-2026)
    public const OTHER_LEVEL_TODAY = 'other_level_today';

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
        if ($i = \App\Support\InspectionVisibility::sameLevel($base(), $officer)->where('officer_id', '!=', $officer->id)->where('status', InspectionStatus::Draft)->latest('started_at')->first()) {
            return $this->result(self::OTHER_DRAFT, $i, true, 'This school is being inspected by '.$this->who($i)
                .'. Their draft was started on '.$i->started_at->format('d M Y').'. You cannot start another inspection of this school.');
        }
        if ($i = (self::otherInWindow($officer))($base())->latest('inspection_date')->first()) {
            return $this->result(self::OTHER_MONTH, $i, true, 'This school was already inspected by '.$this->who($i)
                .' on '.$i->inspection_date->format('d M Y').($i->inspection_no ? ' ('.$i->inspection_no.')' : '')
                .'. It can be inspected again from '.self::reopensOn()->format('d M Y').'.');
        }
        if ($i = (self::otherLevelToday($officer))($base())->latest('started_at')->first()) {
            return $this->result(self::OTHER_LEVEL_TODAY, $i, true, 'This school '.($i->status === InspectionStatus::Draft ? 'is being' : 'was')
                .' inspected today by '.$this->who($i).' ('.(\App\Enums\UserLevel::tryFrom((string) $i->officer_level)?->label() ?? 'another level').'). Officers of different levels cannot inspect the same school on the same day. You can inspect it from '
                .today()->addDay()->format('d M Y').'.');
        }

        return null;
    }

    /** First day on which a school completed in today's block window is open again (tomorrow when the window is "none"). */
    public static function reopensOn(): \Illuminate\Support\Carbon
    {
        return InspectionStartSetting::current()->reopensOn(today()) ?? today()->addDay();
    }

    /** Scope for the school search badges: another officer's open draft. */
    public static function otherDraft(User $officer): \Closure
    {
        return fn (Builder $q) => \App\Support\InspectionVisibility::sameLevel($q, $officer)->where('officer_id', '!=', $officer->id)->where('status', InspectionStatus::Draft);
    }

    /** Scope: completed by another officer of the same level inside today's block window (never, for "none"). */
    public static function otherInWindow(User $officer): \Closure
    {
        $window = InspectionStartSetting::current()->window(today());

        return fn (Builder $q) => $window === null ? $q->whereRaw('1 = 0')
            : \App\Support\InspectionVisibility::sameLevel($q, $officer)->where('officer_id', '!=', $officer->id)
                ->whereIn('status', [InspectionStatus::Submitted, InspectionStatus::Unlocked])
                ->whereBetween('inspection_date', [$window[0]->toDateString(), $window[1]->toDateString()]);
    }

    /** @deprecated 26-11-2026: the window is configurable, use otherInWindow(). */
    public static function otherThisMonth(User $officer): \Closure
    {
        return self::otherInWindow($officer);
    }

    /** Scope: an officer of another level inspects the school today, when the State Admin does not allow that (never otherwise). */
    public static function otherLevelToday(User $officer): \Closure
    {
        $allowed = InspectionStartSetting::current()->cross_level;

        return fn (Builder $q) => $allowed ? $q->whereRaw('1 = 0')
            : $q->where('officer_level', '!=', $officer->level->value)->where('officer_id', '!=', $officer->id)
                ->where('status', '!=', InspectionStatus::Cancelled)->where('inspection_date', today()->toDateString());
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

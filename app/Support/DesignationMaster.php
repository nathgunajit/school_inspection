<?php

namespace App\Support;

use App\Models\MasterValue;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * 27-11-2026: a designation typed after choosing "Other" goes into the Designation master
 * (`master_values`, type `designation`). A label already in the list (any case, extra spaces) is
 * reused; only a new one is added, just above "Other", so the next officer can pick it.
 */
class DesignationMaster
{
    public const TYPE = 'designation';

    /** Text that means nothing more than "Other"; refused by the forms. */
    public static function isJustOther(?string $typed): bool
    {
        return mb_strtolower(Str::squish((string) $typed)) === 'other';
    }

    /**
     * @return array{0:int,1:bool} the designation id and whether a new row was added
     */
    public static function resolve(string $typed, ?User $by = null): array
    {
        $label = Str::squish($typed);

        if ($id = static::find($label)) {
            return [$id, false];
        }

        $other = MasterValue::where('type', self::TYPE)->where('code', User::OTHER_DESIGNATION)->first();
        $base = Str::limit(Str::slug($label, '_'), 36, '') ?: 'designation';
        $code = $base;
        for ($n = 2; MasterValue::where('type', self::TYPE)->where('code', $code)->exists(); $n++) {
            $code = $base.'_'.$n;
        }

        try {
            $row = MasterValue::create([
                'type' => self::TYPE,
                'code' => $code,
                'label' => mb_substr($label, 0, 150),
                'sort' => max(0, ($other?->sort ?? 1000) - 1),
                'is_active' => true,
            ]);
        } catch (QueryException $e) {
            // another sign-up added it at the same moment
            if ($id = static::find($label)) {
                return [$id, false];
            }
            throw $e;
        }

        AuditLogger::log('designation_added', $row, ['label' => $row->label, 'code' => $row->code], $by);

        return [$row->id, true];
    }

    /** An active designation whose label is the same text, ignoring case and extra spaces. */
    private static function find(string $label): ?int
    {
        $want = mb_strtolower($label);

        return MasterValue::where('type', self::TYPE)->where('is_active', true)
            ->where('code', '!=', User::OTHER_DESIGNATION)
            ->get(['id', 'label'])
            ->first(fn ($m) => mb_strtolower(Str::squish($m->label)) === $want)
            ?->id;
    }
}

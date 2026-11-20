<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A coverage window (Inspection Coverage Report): daily, weekly (Mon–Sun), monthly, quarterly (calendar quarters,
 * which are also the financial-year quarters) or a custom from–to range. Also the inspection-cycle window.
 */
final class CoveragePeriod
{
    private function __construct(
        public readonly string $type,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    /** The window of a type that contains $ref (custom uses $from / $to as given). */
    public static function forType(string $type, CarbonInterface $ref, ?CarbonInterface $from = null, ?CarbonInterface $to = null): self
    {
        $r = CarbonImmutable::instance($ref)->startOfDay();

        return match ($type) {
            'daily' => new self('daily', $r, $r),
            'weekly' => new self('weekly', $r->startOfWeek(CarbonInterface::MONDAY), $r->startOfWeek(CarbonInterface::MONDAY)->addDays(6)),
            'quarterly' => new self('quarterly', $r->firstOfQuarter(), $r->lastOfQuarter()),
            'custom' => self::custom($from ?? $r, $to ?? $r),
            default => new self('monthly', $r->startOfMonth(), $r->endOfMonth()->startOfDay()),
        };
    }

    public static function custom(CarbonInterface $from, CarbonInterface $to): self
    {
        $f = CarbonImmutable::instance($from)->startOfDay();
        $t = CarbonImmutable::instance($to)->startOfDay();

        return $f->greaterThan($t) ? new self('custom', $t, $f) : new self('custom', $f, $t);
    }

    /** The inspection-cycle window (monthly / quarterly / half-yearly Apr–Sep, Oct–Mar / yearly Apr–Mar) that contains $date. */
    public static function cycle(string $cycle, CarbonInterface $date): self
    {
        $d = CarbonImmutable::instance($date)->startOfDay();
        // financial year starts on 1 April
        $fyStart = $d->month >= 4 ? $d->setDate($d->year, 4, 1) : $d->setDate($d->year - 1, 4, 1);

        return match ($cycle) {
            'monthly' => new self('monthly', $d->startOfMonth(), $d->endOfMonth()->startOfDay()),
            'half_yearly' => $d->month >= 4 && $d->month <= 9
                ? new self('half_yearly', $fyStart, $fyStart->addMonths(6)->subDay())
                : new self('half_yearly', $fyStart->addMonths(6), $fyStart->addYear()->subDay()),
            'yearly' => new self('yearly', $fyStart, $fyStart->addYear()->subDay()),
            default => new self('quarterly', $d->firstOfQuarter(), $d->lastOfQuarter()),
        };
    }

    /** The window before / after this one (a custom range moves by its own length). */
    public function previous(): self
    {
        return $this->shift(-1);
    }

    public function next(): self
    {
        return $this->shift(1);
    }

    private function shift(int $dir): self
    {
        return match ($this->type) {
            'daily' => self::forType('daily', $this->from->addDays($dir)),
            'weekly' => self::forType('weekly', $this->from->addWeeks($dir)),
            'monthly' => self::forType('monthly', $this->from->addMonthsNoOverflow($dir)),
            'quarterly' => self::forType('quarterly', $this->from->addMonthsNoOverflow(3 * $dir)),
            default => self::custom($this->from->addDays($this->days() * $dir), $this->to->addDays($this->days() * $dir)),
        };
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function contains(CarbonInterface $date): bool
    {
        $d = CarbonImmutable::instance($date)->startOfDay();

        return $d->betweenIncluded($this->from, $this->to);
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /** "Today, 20 Nov 2026", "Week 16–22 Nov 2026", "November 2026", "Q3 · Oct–Dec 2026", "Apr 2026 – Mar 2027". */
    public function label(): string
    {
        $f = $this->from;
        $t = $this->to;

        return match ($this->type) {
            'daily' => ($f->isToday() ? 'Today, ' : '').$f->format('D, d M Y'),
            'weekly' => 'Week '.($f->month === $t->month ? $f->format('d') : $f->format('d M')).'–'.$t->format('d M Y'),
            'monthly' => $f->format('F Y'),
            'quarterly' => 'Q'.$f->quarter.' · '.$f->format('M').'–'.$t->format('M Y'),
            default => $f->isSameDay($t) ? $f->format('d M Y') : $f->format('d M Y').' – '.$t->format('d M Y'),
        };
    }
}

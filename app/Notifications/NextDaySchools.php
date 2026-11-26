<?php

namespace App\Notifications;

use Illuminate\Support\Carbon;

/** V15 item 6: the next working day's Today's Schools list, sent at the State Administrator's notice time. */
class NextDaySchools extends InAppAlert
{
    /** @param string[] $names school names in list order */
    public function __construct(public string $day, public int $carried, public int $fresh, public array $names) {}

    protected function kind(): string { return 'next_day_schools'; }

    protected function icon(): string { return 'bi-calendar-check'; }

    protected function title(): string
    {
        $d = Carbon::parse($this->day);
        $when = $d->isTomorrow() ? 'Tomorrow ('.$d->format('D d M').')' : $d->format('l, d M');
        $n = $this->carried + $this->fresh;

        return "$when: $n school".($n === 1 ? '' : 's').' to visit';
    }

    protected function body(): string
    {
        $parts = array_filter([$this->carried ? "{$this->carried} carried from earlier (first)" : null, $this->fresh ? "{$this->fresh} new" : null]);

        return implode(' · ', $parts).($this->names ? '. '.implode(', ', array_slice($this->names, 0, 6)).(count($this->names) > 6 ? ' …' : '') : '');
    }

    protected function url(): string { return route('today.index', ['date' => $this->day], false); }
}

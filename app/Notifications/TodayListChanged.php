<?php

namespace App\Notifications;

use Illuminate\Support\Carbon;

/** V15 items 9–11: a higher officer or the State Administrator changed (or unlocked) the officer's list for a day. */
class TodayListChanged extends InAppAlert
{
    public function __construct(public string $day, public string $what, public string $by) {}

    protected function kind(): string { return 'today_list_changed'; }

    protected function icon(): string { return 'bi-signpost-2'; }

    protected function title(): string { return 'Your school list for '.Carbon::parse($this->day)->format('d M').' was changed'; }

    protected function body(): string { return $this->what.' (by '.$this->by.').'; }

    protected function url(): string { return route('today.index', Carbon::parse($this->day)->isToday() ? [] : ['date' => $this->day], false); }
}

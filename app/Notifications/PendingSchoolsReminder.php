<?php

namespace App\Notifications;

/** V15 item 7: the daily reminder for schools still pending on today's list. */
class PendingSchoolsReminder extends InAppAlert
{
    /** @param string[] $names */
    public function __construct(public string $day, public array $names) {}

    protected function kind(): string { return 'pending_reminder'; }

    protected function icon(): string { return 'bi-alarm'; }

    protected function title(): string
    {
        $n = count($this->names);

        return "$n school".($n === 1 ? ' is' : 's are').' still pending today';
    }

    protected function body(): string
    {
        return implode(', ', array_slice($this->names, 0, 6)).(count($this->names) > 6 ? ' …' : '').'. Visit them, or mark why they cannot be visited.';
    }

    protected function url(): string { return route('today.index', [], false); }
}

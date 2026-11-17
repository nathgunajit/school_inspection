<?php

namespace App\Policies;

use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Models\Permission;
use App\Models\User;
use App\Services\JurisdictionService;

class InspectionPolicy
{
    public function __construct(private JurisdictionService $j) {}

    /** View report, photos and PDF: own inspections, or submitted ones within jurisdiction (PRD 3.2, 9.8). */
    public function view(User $user, Inspection $inspection): bool
    {
        if (! $user->isActive()) {
            return false;
        }
        if ($inspection->officer_id === $user->id) {
            return true;
        }

        return $user->hasPermission(Permission::VIEW_REPORTS)
            && $inspection->status !== InspectionStatus::Draft
            && $this->j->coversInspection($user, $inspection);
    }

    /** Edit answers and evidence: only the officer, only while Draft or Unlocked. */
    public function update(User $user, Inspection $inspection): bool
    {
        return $user->isActive()
            && $inspection->officer_id === $user->id
            && $inspection->isEditable();
    }

    public function cancel(User $user, Inspection $inspection): bool
    {
        return $user->isActive() && $inspection->officer_id === $user->id && $inspection->status === InspectionStatus::Draft;
    }

    /** Only the State Administrator may unlock a submitted inspection (PRD 8.2). */
    public function unlock(User $user, Inspection $inspection): bool
    {
        return $user->hasPermission(Permission::UNLOCK_INSPECTIONS) && $inspection->status === InspectionStatus::Submitted;
    }
}

<?php

use App\Models\TlmItem;
use Illuminate\Database\Migrations\Migration;

/**
 * 28-11-2026: every TLM list (FLN and ECCE) is for lower primary. Items with no school category get the categories
 * that have Class 1–5 (TlmItem::LOWER_PRIMARY_CATEGORIES); items already narrowed are left alone.
 * Nothing happens while the category master is not loaded (a fresh install runs the seeders after the migrations).
 */
return new class extends Migration
{
    public function up(): void
    {
        TlmItem::applyLowerPrimaryDefault();
    }

    public function down(): void
    {
        // data only: the categories stay (they can be changed in the TLM master)
    }
};

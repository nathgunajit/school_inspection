<?php

namespace App\Console\Commands;

use App\Services\RatingService;
use Illuminate\Console\Command;

/** New Module 3: rate submitted inspections with the active rating scheme. */
class RecalculateRatings extends Command
{
    protected $signature = 'ratings:recalculate {--all : Re-rate every submitted inspection, not only those without a rating}';

    protected $description = 'Calculate the automatic rating of submitted inspections (questionnaire v2) with the active scheme';

    public function handle(RatingService $ratings): int
    {
        $scheme = $ratings->activeScheme();
        $n = $ratings->recalculate((bool) $this->option('all'));
        $this->info("Rated $n inspection(s) with rating scheme version {$scheme->version}.");

        return self::SUCCESS;
    }
}

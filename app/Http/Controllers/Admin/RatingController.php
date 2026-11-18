<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InspectionRating;
use App\Models\RatingScheme;
use App\Services\AuditLogger;
use App\Services\RatingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Rating Settings (New Module 3): weights, points and bands of the automatic rating. State Admin only. */
class RatingController extends Controller
{
    public const COLORS = ['green' => 'Green', 'blue' => 'Blue', 'yellow' => 'Yellow', 'orange' => 'Orange', 'red' => 'Red'];

    public function __construct(private RatingService $ratings) {}

    public function index()
    {
        $scheme = $this->ratings->activeScheme();

        return view('admin.rating.index', [
            'scheme' => $scheme,
            'versions' => RatingScheme::with('creator:id,name')->withCount('criteria')->orderByDesc('version')->get(),
            'rated' => InspectionRating::selectRaw('scheme_id, count(*) as n')->groupBy('scheme_id')->pluck('n', 'scheme_id'),
            'colors' => self::COLORS,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'criteria' => ['array'],
            'criteria.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'criteria.*.points' => ['array'],
            'criteria.*.points.*' => ['required', 'numeric', 'min:0', 'max:1'],
            'bands' => ['required', 'array', 'min:2', 'max:8'],
            'bands.*.min' => ['required', 'numeric', 'min:0', 'max:100'],
            'bands.*.label' => ['required', 'string', 'max:40'],
            'bands.*.color' => ['required', 'in:'.implode(',', array_keys(self::COLORS))],
            'recalculate' => ['nullable', 'boolean'],
        ]);

        $bands = array_values(array_map(fn ($b) => ['min' => (float) $b['min'], 'label' => trim($b['label']), 'color' => $b['color']], $data['bands']));
        $mins = array_column($bands, 'min');
        // contiguous 0–100: one band must start at 0, and no two bands may start at the same score
        if (! in_array(0.0, $mins, true)) {
            throw ValidationException::withMessages(['bands' => 'The lowest band must start at 0 %, so every score gets a rating.']);
        }
        if (count(array_unique($mins)) !== count($mins)) {
            throw ValidationException::withMessages(['bands' => 'Two bands cannot start at the same score.']);
        }
        if (array_sum(array_column($data['criteria'] ?? [], 'weight')) <= 0 && ! empty($data['criteria'])) {
            throw ValidationException::withMessages(['criteria' => 'At least one item must have a weight above 0.']);
        }

        $scheme = $this->ratings->saveNewVersion($request->user(), $data['criteria'] ?? [], $bands);
        $msg = "Rating scheme version {$scheme->version} is now active.";
        if ($request->boolean('recalculate')) {
            $n = $this->ratings->recalculate(true);
            AuditLogger::log('ratings_recalculated', $scheme, ['count' => $n, 'version' => $scheme->version]);
            $msg .= " $n submitted inspection(s) were re-rated with it.";
        } else {
            $msg .= ' New submissions use it; earlier ratings keep their version until recalculated.';
        }

        return redirect()->route('admin.rating.index')->with('success', $msg);
    }

    public function recalculate()
    {
        $scheme = $this->ratings->activeScheme();
        $n = $this->ratings->recalculate(true);
        AuditLogger::log('ratings_recalculated', $scheme, ['count' => $n, 'version' => $scheme->version]);

        return redirect()->route('admin.rating.index')->with('success', "$n submitted inspection(s) were rated with version {$scheme->version}.");
    }
}

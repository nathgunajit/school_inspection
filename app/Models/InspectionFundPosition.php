<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fund received & expenditure for one financial year, amounts in lakh (questionnaire v2, step 5).
 * amount_received / amount_utilised / fund_category_id are v1 legacy columns.
 */
class InspectionFundPosition extends Model
{
    /** source => [label, received column, expenditure column] */
    public const HEADS = [
        'asg' => ['Annual School Grant (SSA)', 'asg_received', 'asg_expenditure'],
        'mdm' => ['MDM Fund (SSA)', 'mdm_received', 'mdm_expenditure'],
        'govt' => ['Government', 'govt_received', 'govt_expenditure'],
        'other' => ['Other Source', 'other_received', 'other_expenditure'],
    ];

    /** Fund sources, shown as coloured groups on screen: [label, icon, heads]. */
    public const SOURCES = [
        'ssa' => ['Samagra Shiksha (SSA)', 'bi-mortarboard', ['asg', 'mdm']],
        'govt' => ['Government', 'bi-bank', ['govt']],
        'other' => ['Other Source', 'bi-wallet2', ['other']],
    ];

    /** Head label without the source suffix, for use inside its source group. */
    public const SHORT = ['asg' => 'Annual School Grant', 'mdm' => 'MDM Fund', 'govt' => 'Government fund', 'other' => 'Other source fund'];

    public static function source(string $head): string
    {
        foreach (self::SOURCES as $key => [, , $heads]) {
            if (in_array($head, $heads, true)) {
                return $key;
            }
        }

        return 'other';
    }

    public const AMOUNT_COLUMNS =['asg_received', 'asg_expenditure', 'mdm_received', 'mdm_expenditure', 'govt_received', 'govt_expenditure', 'other_received', 'other_expenditure'];

    protected $fillable = ['inspection_id', 'financial_year', 'fund_category_id', 'amount_received', 'amount_utilised', ...self::AMOUNT_COLUMNS];

    protected $casts = [
        'amount_received' => 'decimal:2', 'amount_utilised' => 'decimal:2',
        'asg_received' => 'decimal:2', 'asg_expenditure' => 'decimal:2', 'mdm_received' => 'decimal:2', 'mdm_expenditure' => 'decimal:2',
        'govt_received' => 'decimal:2', 'govt_expenditure' => 'decimal:2', 'other_received' => 'decimal:2', 'other_expenditure' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(MasterValue::class, 'fund_category_id');
    }

    public function isLegacy(): bool
    {
        return $this->amount_received !== null && (float) $this->asg_received + (float) $this->govt_received + (float) $this->other_received + (float) $this->mdm_received == 0.0;
    }

    public function balance(?string $head = null): float
    {
        if ($head) {
            [, $r, $e] = self::HEADS[$head];

            return (float) $this->$r - (float) $this->$e;
        }
        if ($this->isLegacy()) {
            return (float) $this->amount_received - (float) $this->amount_utilised;
        }

        return array_sum(array_map(fn ($h) => $this->balance($h), array_keys(self::HEADS)));
    }

    public function totalReceived(): float
    {
        return array_sum(array_map(fn ($h) => (float) $this->{self::HEADS[$h][1]}, array_keys(self::HEADS)));
    }

    public function totalExpenditure(): float
    {
        return array_sum(array_map(fn ($h) => (float) $this->{self::HEADS[$h][2]}, array_keys(self::HEADS)));
    }

    public function toClientArray(): array
    {
        $fmt = fn ($v) => number_format((float) $v, 2);
        $heads = [];
        foreach (self::HEADS as $key => [$label, $r, $e]) {
            $heads[] = ['key' => $key, 'source' => self::source($key), 'label' => $label, 'received' => $fmt($this->$r), 'expenditure' => $fmt($this->$e), 'balance' => $fmt($this->balance($key))];
        }

        return [
            'id' => $this->id,
            'financial_year' => $this->financial_year,
            'heads' => $heads,
            'total_received' => $fmt($this->totalReceived()),
            'total_expenditure' => $fmt($this->totalExpenditure()),
            'balance' => $fmt($this->balance()),
            'values' => collect(self::AMOUNT_COLUMNS)->mapWithKeys(fn ($c) => [$c => (float) $this->$c])->all(),
            'delete_url' => route('inspections.funds.destroy', [$this->inspection_id, $this->id]),
        ];
    }

    /** FY 2021-22 … current financial year (April start). */
    public static function financialYears(): array
    {
        $current = now()->month >= 4 ? now()->year : now()->year - 1;
        $years = [];
        for ($y = $current; $y >= 2021; $y--) {
            $years[] = sprintf('FY-%d-%02d', $y, ($y + 1) % 100);
        }

        return $years;
    }
}

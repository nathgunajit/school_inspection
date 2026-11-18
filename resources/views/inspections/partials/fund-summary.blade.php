{{-- Year-wise fund summary in ₹ lakh. $funds = collection of InspectionFundPosition. Display only. --}}
@php
    use App\Models\InspectionFundPosition as FP;
    $funds = $funds->sortByDesc('financial_year')->values();
    // legacy (v1) rows keep their amounts in amount_received / amount_utilised
    $rec = fn ($f) => $f->isLegacy() ? (float) $f->amount_received : $f->totalReceived();
    $exp = fn ($f) => $f->isLegacy() ? (float) $f->amount_utilised : $f->totalExpenditure();
    $gr = $funds->sum($rec);
    $ge = $funds->sum($exp);
    $tone = fn ($v) => $v < 0 ? 'neg' : 'pos';
@endphp
@if ($funds->isEmpty())
    <div class="review-line text-secondary"><i class="bi bi-dash-circle"></i>No fund details (optional).</div>
@else
    <div class="fy-summary">
        @foreach ($funds as $f)
            @php $bal = $f->balance(); @endphp
            <div class="fy-card">
                <div class="fy-card-head">
                    <span class="fy-card-year"><i class="bi bi-calendar3"></i>FY {{ str_replace('FY-', '', $f->financial_year) }}</span>
                    <span class="fy-bal {{ $tone($bal) }}"><small>Balance</small>₹ {{ FP::lakh($bal) }} <em>L</em></span>
                </div>
                <div class="fy-figs">
                    <div><small>Received</small><b>₹ {{ FP::lakh($rec($f)) }} <em>L</em></b></div>
                    <div><small>Expenditure</small><b>₹ {{ FP::lakh($exp($f)) }} <em>L</em></b></div>
                </div>
                @if ($f->isLegacy())
                    <div class="fy-heads"><div class="fy-head"><span class="fy-head-name">{{ $f->category?->label ?? 'Fund' }} (legacy)</span><span></span><span></span><span></span></div></div>
                @endif
                @unless ($f->isLegacy())
                    <div class="fy-heads">
                        <div class="fy-head fy-head-cols"><span>Fund head (₹ lakh)</span><span>Received</span><span>Spent</span><span>Balance</span></div>
                        @foreach (FP::HEADS as $key => [$label, $rc, $ec])
                            @continue((float) $f->$rc == 0 && (float) $f->$ec == 0)
                            @php $hb = $f->balance($key); @endphp
                            <div class="fy-head src-{{ FP::source($key) }}">
                                <span class="fy-head-name">{{ FP::SHORT[$key] }}</span>
                                <span>{{ FP::lakh((float) $f->$rc) }}</span>
                                <span>{{ FP::lakh((float) $f->$ec) }}</span>
                                <span class="{{ $tone($hb) }}">{{ FP::lakh($hb) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endunless
            </div>
        @endforeach
        @if ($funds->count() > 1)
            <div class="fy-grand">
                <span>All {{ $funds->count() }} years</span>
                <span><small>Received</small>₹ {{ FP::lakh($gr) }} L</span>
                <span><small>Expenditure</small>₹ {{ FP::lakh($ge) }} L</span>
                <span class="{{ $tone($gr - $ge) }}"><small>Balance</small>₹ {{ FP::lakh($gr - $ge) }} L</span>
            </div>
        @endif
    </div>
@endif

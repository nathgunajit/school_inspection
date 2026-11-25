@php
    use App\Models\DailyDisplaySetting as DS;
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $levelNames = ['cluster' => 'Cluster (CRCC)', 'block' => 'Block', 'district' => 'District', 'state' => 'State'];
    $forms = [];
    if ($isState) {
        $forms[] = ['row' => $state, 'scope' => 'state', 'districtId' => null, 'title' => 'State default', 'hint' => 'Applies to every district without its own setting.', 'pick' => false];
        $forms[] = ['row' => $state, 'scope' => 'district', 'districtId' => null, 'title' => 'Set a district of its own', 'hint' => 'Pick the district; it starts from the state default.', 'pick' => true];
    } else {
        $forms[] = ['row' => $own ?? $state, 'scope' => 'district', 'districtId' => $district->id, 'title' => $district->label,
            'hint' => $own ? 'This district has its own setting.' : 'This district follows the state default. Save to set its own.', 'pick' => false];
    }
@endphp
<x-layouts.app title="Today's Schools Settings" :back="route('today.index')">
    <div class="row g-4">
        <div class="col-xl-8">
            @foreach ($forms as $i => $fm)
                @php $r = $fm['row']; @endphp
                <section class="card mb-4">
                    <div class="card-header d-flex flex-wrap justify-content-between gap-2"><span><i class="bi bi-sliders me-2 text-primary"></i>{{ $fm['title'] }}</span><span class="text-xs text-secondary">{{ $fm['hint'] }}</span></div>
                    <form method="POST" action="{{ route('today.settings.update') }}" class="card-body">
                        @csrf @method('PUT')
                        <input type="hidden" name="scope" value="{{ $fm['scope'] }}">
                        @if ($fm['pick'])
                            <label class="form-label">District</label>
                            <select name="district_id" class="form-select mb-3" required>
                                <option value="">Pick a district</option>
                                @foreach (\App\Models\District::where('is_active', true)->orderBy('code')->get(['id', 'name', 'code']) as $d)<option value="{{ $d->id }}">{{ $d->label }}</option>@endforeach
                            </select>
                        @elseif ($fm['districtId'])<input type="hidden" name="district_id" value="{{ $fm['districtId'] }}">@endif

                        <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" name="enabled" value="1" id="en{{ $i }}" @checked($r->enabled)>
                            <label class="form-check-label fw-semibold" for="en{{ $i }}">Show Today's schools</label></div>

                        <div class="row g-3">
                            <div class="col-sm-4"><label class="form-label">Daily display limit</label>
                                <input type="number" name="daily_limit" min="1" max="10" value="{{ $r->daily_limit }}" class="form-control" required>
                                <div class="form-text">Schools a day per officer (default 2).</div></div>
                            <div class="col-sm-8"><label class="form-label">Officers who get the list</label>
                                <div class="d-flex flex-wrap gap-3">
                                    @foreach ($levelNames as $k => $l)<div class="form-check"><input class="form-check-input" type="checkbox" name="levels[]" value="{{ $k }}" id="lv{{ $i }}{{ $k }}" @checked(in_array($k, $r->levels ?? [], true))><label class="form-check-label" for="lv{{ $i }}{{ $k }}">{{ $l }}</label></div>@endforeach
                                </div></div>
                            <div class="col-12"><label class="form-label">Selection</label>
                                @foreach (DS::SELECTIONS as $k => $l)<div class="form-check"><input class="form-check-input" type="radio" name="selection" value="{{ $k }}" id="sel{{ $i }}{{ $k }}" @checked($r->selection === $k)><label class="form-check-label" for="sel{{ $i }}{{ $k }}">{{ $l }}</label></div>@endforeach</div>

                            <div class="col-12"><label class="form-label">Priority <span class="opt">used with "By priority"</span></label>
                                <div class="setting-checks">
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="prefer_not_inspected" value="1" id="pn{{ $i }}" @checked($r->prefer_not_inspected)><label class="form-check-label" for="pn{{ $i }}"><b>Not yet inspected first</b><span>Schools never inspected come first.</span></label></div>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="prefer_low_rating" value="1" id="pl{{ $i }}" @checked($r->prefer_low_rating)><label class="form-check-label" for="pl{{ $i }}"><b>Low-rated schools</b><span>A latest inspection rating below
                                        <input type="number" name="low_rating_below" min="1" max="100" value="{{ $r->low_rating_below }}" class="form-control form-control-sm d-inline-block setting-num"> % gets a higher place.</span></label></div>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="prefer_issues" value="1" id="pi{{ $i }}" @checked($r->prefer_issues)><label class="form-check-label" for="pi{{ $i }}"><b>Issue-based priority</b><span>Open issues at the last inspection: no girls' or boys' toilet, no safe drinking water, no electricity, too few classrooms, network problem.</span></label></div>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="prefer_less_visited" value="1" id="pv{{ $i }}" @checked($r->prefer_less_visited)><label class="form-check-label" for="pv{{ $i }}"><b>Less frequently visited</b><span>The longer since the last visit, the higher the place.</span></label></div>
                                </div></div>

                            <div class="col-12"><div class="setting-checks">
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="carry_forward" value="1" id="cf{{ $i }}" @checked($r->carry_forward)><label class="form-check-label" for="cf{{ $i }}"><b>Pending school list</b><span>Schools not visited move to the next working day (up to {{ \App\Services\DailySchoolListService::CARRY_DAYS }} days).</span></label></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="skip_blocked" value="1" id="sb{{ $i }}" @checked($r->skip_blocked)><label class="form-check-label" for="sb{{ $i }}"><b>Skip schools taken by another officer</b><span>Being inspected now, or already inspected this month by someone else.</span></label></div>
                            </div></div>

                            <div class="col-sm-6"><label class="form-label">Recently visited restriction</label>
                                <div class="input-group"><input type="number" name="recent_days" min="0" max="365" value="{{ $r->recent_days }}" class="form-control" required><span class="input-group-text">days</span></div>
                                <div class="form-text">A school inspected within this many days is not shown again (0 = off).</div></div>
                            <div class="col-sm-6"><label class="form-label">Maximum distance <span class="opt">optional</span></label>
                                <div class="input-group"><input type="number" name="max_distance_km" min="1" max="500" value="{{ $r->max_distance_km }}" class="form-control" placeholder="Any"><span class="input-group-text">km</span></div>
                                <div class="form-text">From the officer's office (route start point). Schools without GPS stay in.</div></div>
                            <div class="col-12"><label class="form-label">Weekly off days</label>
                                <div class="d-flex flex-wrap gap-3">
                                    @foreach ($days as $n => $d)<div class="form-check"><input class="form-check-input" type="checkbox" name="weekly_off[]" value="{{ $n }}" id="wo{{ $i }}{{ $n }}" @checked(in_array($n, $r->weekly_off ?? [], true))><label class="form-check-label" for="wo{{ $i }}{{ $n }}">{{ $d }}</label></div>@endforeach
                                </div>
                                <div class="form-text">Holidays come from the <a href="{{ route('holidays.index') }}">Holiday Calendar</a>; no schools are shown on them.</div></div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button class="btn btn-primary"><i class="bi bi-check2"></i>Save</button>
                            @if (! $isState && $own)
                                <button class="btn btn-light" name="reset" value="1" onclick="return confirm(this.dataset.msg)" data-msg="Use the state default for {{ $fm['title'] }}?"><i class="bi bi-arrow-counterclockwise"></i>Use state default</button>
                            @endif
                        </div>
                        @if (! $fm['pick'] && $r->updater)<div class="text-xs text-secondary mt-2">Last changed by {{ $r->updater->name }} on {{ $r->updated_at->format('d M Y, h:i A') }}</div>@endif
                    </form>
                </section>
            @endforeach
        </div>
        <div class="col-xl-4">
            @if ($isState)
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-diagram-3 me-2 text-primary"></i>Districts with their own setting</div>
                    @forelse ($overrides as $o)
                        <div class="d-flex gap-2 align-items-center px-3 py-2 border-bottom">
                            <span class="flex-grow-1 min-w-0"><span class="d-block text-sm fw-semibold">{{ $o->district?->label }}</span>
                                <span class="d-block text-xs text-secondary">{{ $o->enabled ? 'On' : 'Off' }} · {{ $o->daily_limit }} a day · {{ $o->selection === 'random' ? 'random' : 'priority' }}</span></span>
                            <form method="POST" action="{{ route('today.settings.update') }}" data-confirm="Use the state default for {{ $o->district?->label }}?">@csrf @method('PUT')
                                <input type="hidden" name="scope" value="district"><input type="hidden" name="district_id" value="{{ $o->district_id }}"><input type="hidden" name="reset" value="1">
                                <button class="btn btn-sm btn-light" title="Use the state default"><i class="bi bi-arrow-counterclockwise"></i></button></form>
                        </div>
                    @empty
                        <div class="dash-empty"><i class="bi bi-check2"></i>Every district follows the state default.</div>
                    @endforelse
                </div>
            @endif
            <div class="alert alert-light border text-sm"><i class="bi bi-info-circle me-1"></i>The list is made once, when the officer first opens it on a working day, and then stays the same all day. Changes apply from the next list. Higher officers can also <a href="{{ route('today.assign') }}">assign schools</a> by hand (manual selection).</div>
        </div>
    </div>
</x-layouts.app>

{{-- One sign-in attempt in the login report tables ($l: UserLoginLog; $hideOfficer on the officer's own page) --}}
@php [$stLabel, $stTone] = \App\Services\LoginMonitorService::stateLabel($l->sessionState()); $hideOfficer ??= false; @endphp
<tr>
    <td class="text-nowrap text-sm">{{ $l->login_at->format('d M Y') }}<div class="text-xs text-secondary">{{ $l->login_at->format('h:i:s A') }}</div></td>
    @unless ($hideOfficer)
        <td>
            @if ($l->user)
                <a href="{{ route('admin.login-report.user', $l->user) }}" class="d-flex align-items-center gap-2 text-decoration-none">
                    <x-user-avatar :user="$l->user" />
                    <div><div class="fw-semibold">{{ $l->user->name }}</div><div class="text-xs text-secondary">{{ $l->mobile }}</div></div>
                </a>
            @else
                <div class="text-sm">Unknown number</div><div class="text-xs text-secondary font-monospace">{{ $l->mobile }}</div>
            @endif
        </td>
        <td class="text-sm">{{ $l->user?->level->label() ?? '—' }}<div class="text-xs text-secondary">{{ $l->user?->currentJurisdiction?->label() }}</div></td>
    @endunless
    <td>
        <span class="text-nowrap badge-soft badge-soft-{{ $l->status === 'success' ? 'success' : ($l->status === 'denied' ? 'warning' : 'danger') }}">{{ \App\Models\UserLoginLog::STATUSES[$l->status] ?? $l->status }}</span>
        @if ($l->reason)<div class="text-xs text-secondary mt-1">{{ $l->reason }}</div>@endif
    </td>
    <td>
        @if ($l->status === 'success')
            <span class="badge-soft badge-soft-{{ $stTone }} text-nowrap">{{ $stLabel }}</span>
            <div class="text-xs text-secondary mt-1">{{ $l->logout_at ? 'out '.$l->logout_at->format('h:i A') : ($l->last_seen_at ? 'seen '.$l->last_seen_at->format('h:i A') : '') }}</div>
        @else
            —
        @endif
    </td>
    <td class="text-end text-sm text-nowrap">{{ \App\Models\UserLoginLog::durationLabel($l->durationMinutes()) }}</td>
    <td class="text-xs">
        <span class="font-monospace">{{ $l->ip_address }}</span>
        <div class="text-secondary"><i class="bi {{ $l->device === 'mobile' ? 'bi-phone' : ($l->device === 'tablet' ? 'bi-tablet' : 'bi-laptop') }}"></i> {{ trim($l->browser.' · '.$l->platform, ' ·') }}</div>
    </td>
</tr>

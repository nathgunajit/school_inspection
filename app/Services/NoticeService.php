<?php

namespace App\Services;

use App\Enums\UserLevel;
use App\Models\Block;
use App\Models\Cluster;
use App\Models\Notice;
use App\Models\NoticeRead;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * V14 item 1 (25-11-2026): Instruction / Notification / Order letters. The State Admin sends to any area;
 * a District officer with Manage users (gate issue_notices) sends inside their own district only.
 */
class NoticeService
{
    private const VERSION_KEY = 'notices:version';

    public const DISK = 'private';

    public function __construct(private JurisdictionService $j) {}

    public static function flushCounts(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
    }

    /** Unread notices for the sidebar badge (cached 60 s; reset when a notice is sent or read). */
    public function unreadCount(User $user): int
    {
        if ($user->isStateAdmin()) {
            return 0;
        }
        $key = 'notices:unread:'.Cache::get(self::VERSION_KEY, 0).':'.$user->id;

        return (int) Cache::remember($key, 60, fn () => Notice::query()->for($user)
            ->whereDoesntHave('reads', fn ($r) => $r->where('user_id', $user->id)->whereNotNull('read_at'))->count());
    }

    /** Levels a sender may address: every officer level below their own. */
    public function levelsFor(User $sender): array
    {
        return collect(UserLevel::registrable())->filter(fn ($l) => $l->rank() > $sender->level->rank())->values()->all();
    }

    /**
     * Create and publish a notice.
     *
     * @param  array{type:string, ref_no:?string, title:string, body:string, notice_date:string, levels:array, district_id:?int, block_id:?int, cluster_id:?int, expires_on:?string}  $data
     */
    public function publish(User $sender, array $data, ?UploadedFile $file): Notice
    {
        $data = $this->checkAudience($sender, $data);
        $stored = $file ? $this->store($file) : [];

        try {
            $notice = DB::transaction(fn () => Notice::create($data + $stored + ['status' => 'published', 'created_by' => $sender->id]));
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk(self::DISK)->delete($stored['attachment_path']);
            }
            throw $e;
        }
        AuditLogger::log('notice_published', $notice, ['type' => $notice->type, 'title' => $notice->title, 'audience' => $notice->audienceLabel(),
            'recipients' => $notice->recipients()->count(), 'attachment' => (bool) $file]);

        return $notice;
    }

    public function withdraw(User $actor, Notice $notice): void
    {
        $notice->update(['status' => 'withdrawn', 'withdrawn_at' => now()]);
        AuditLogger::log('notice_withdrawn', $notice, ['title' => $notice->title]);
    }

    public function markRead(User $user, Notice $notice): NoticeRead
    {
        $read = NoticeRead::firstOrCreate(['notice_id' => $notice->id, 'user_id' => $user->id], ['read_at' => now()]);
        if (! $read->read_at) {
            $read->update(['read_at' => now()]);
        }
        if ($read->wasRecentlyCreated) {
            self::flushCounts();
        }

        return $read;
    }

    public function acknowledge(User $user, Notice $notice): void
    {
        $read = $this->markRead($user, $notice);
        if (! $read->acknowledged_at) {
            $read->update(['acknowledged_at' => now()]);
            AuditLogger::log('notice_acknowledged', $notice, ['title' => $notice->title]);
        }
    }

    /** May this user manage (see the read report, withdraw) this notice? */
    public function canManage(User $user, Notice $notice): bool
    {
        if (! $user->can('issue_notices')) {
            return false;
        }

        return $user->isStateAdmin() || $notice->created_by === $user->id
            || ($notice->district_id && $notice->district_id === $this->j->current($user)?->district_id);
    }

    public function canView(User $user, Notice $notice): bool
    {
        return $this->canManage($user, $notice) || ($notice->status === 'published' && $notice->isFor($user))
            || NoticeRead::where('notice_id', $notice->id)->where('user_id', $user->id)->exists(); // a read notice stays readable
    }

    /** Read report: recipients with read / acknowledged times. */
    public function report(Notice $notice): array
    {
        $users = $notice->recipients()->with(['currentJurisdiction.cluster:id,name,code', 'currentJurisdiction.block:id,name,code', 'currentJurisdiction.district:id,name,code'])
            ->orderBy('level')->orderBy('name')->get(['id', 'name', 'level', 'mobile', 'designation_id', 'designation_other']);
        $reads = NoticeRead::where('notice_id', $notice->id)->get()->keyBy('user_id');

        return [
            'rows' => $users->map(fn ($u) => ['user' => $u, 'read' => $reads->get($u->id)]),
            'total' => $users->count(),
            'read' => $users->filter(fn ($u) => $reads->get($u->id)?->read_at)->count(),
            'ack' => $users->filter(fn ($u) => $reads->get($u->id)?->acknowledged_at)->count(),
        ];
    }

    /** Check the levels and the area against the sender's own rights and posting. */
    private function checkAudience(User $sender, array $data): array
    {
        $allowed = array_map(fn ($l) => $l->value, $this->levelsFor($sender));
        $levels = array_values(array_intersect($allowed, $data['levels'] ?? []));
        if (! $levels) {
            throw ValidationException::withMessages(['levels' => 'Pick who should receive it.']);
        }
        $districtId = $data['district_id'] ?? null;
        $blockId = $data['block_id'] ?? null;
        $clusterId = $data['cluster_id'] ?? null;

        if ($clusterId) {
            $c = Cluster::with('block')->find($clusterId);
            [$blockId, $districtId] = [$c?->block_id, $c?->block?->district_id];
        } elseif ($blockId) {
            $districtId = Block::whereKey($blockId)->value('district_id');
        }
        if (! $sender->isStateAdmin()) {
            $own = $this->j->current($sender)?->district_id;
            if (! $own || ($districtId && (int) $districtId !== (int) $own)) {
                throw ValidationException::withMessages(['district_id' => 'You can send notices inside your own district only.']);
            }
            $districtId = $own;
        }

        return [
            'type' => $data['type'], 'ref_no' => $data['ref_no'] ?? null, 'title' => trim($data['title']), 'body' => trim($data['body']),
            'notice_date' => $data['notice_date'], 'expires_on' => $data['expires_on'] ?? null, 'levels' => $levels,
            'district_id' => $districtId ? (int) $districtId : null, 'block_id' => $blockId ? (int) $blockId : null, 'cluster_id' => $clusterId ? (int) $clusterId : null,
        ];
    }

    private function store(UploadedFile $file): array
    {
        $ext = strtolower($file->extension() ?: $file->getClientOriginalExtension());

        return [
            'attachment_path' => $file->storeAs('notices', Str::random(40).'.'.$ext, self::DISK),
            'attachment_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'attachment_mime' => $file->getMimeType(),
        ];
    }
}

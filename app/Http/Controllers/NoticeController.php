<?php

namespace App\Http\Controllers;

use App\Models\Block;
use App\Models\Cluster;
use App\Models\District;
use App\Models\Notice;
use App\Services\AuditLogger;
use App\Services\JurisdictionService;
use App\Services\NoticeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * V14 item 1 (25-11-2026): Instruction / Notification / Order letters.
 * Inbox for every officer (notices addressed to their level and area), Sent list, compose and read report
 * for senders (gate issue_notices).
 */
class NoticeController extends Controller
{
    public function __construct(private NoticeService $notices, private JurisdictionService $j) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $canIssue = $user->can('issue_notices');
        $tab = $request->query('tab') === 'sent' && $canIssue ? 'sent' : ($user->isStateAdmin() ? 'sent' : 'inbox');
        $type = array_key_exists((string) $request->query('type'), \App\Models\NoticeType::options(false)) ? (string) $request->query('type') : '';

        $query = $tab === 'sent'
            ? Notice::query()->when(! $user->isStateAdmin(), fn ($q) => $q->where(fn ($w) => $w->where('created_by', $user->id)
                ->orWhere('district_id', $this->j->current($user)?->district_id ?: 0)))->withCount([
                    'reads as read_count' => fn ($r) => $r->whereNotNull('read_at'),
                    'reads as ack_count' => fn ($r) => $r->whereNotNull('acknowledged_at')])
            : Notice::query()->for($user)->with(['reads' => fn ($r) => $r->where('user_id', $user->id)]);

        $items = $query->with(['creator:id,name,level,designation_id,designation_other', 'district:id,name,code', 'block:id,name,code', 'cluster:id,name,code'])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('title', 'like', "%$s%")->orWhere('ref_no', 'like', "%$s%")))
            ->when($tab === 'inbox' && $request->query('unread'), fn ($q) => $q->whereDoesntHave('reads', fn ($r) => $r->where('user_id', $user->id)->whereNotNull('read_at')))
            ->orderByDesc('notice_date')->orderByDesc('id')
            ->paginate(config('inspection.per_page'))->withQueryString();

        return view('notices.index', ['items' => $items, 'tab' => $tab, 'type' => $type, 'canIssue' => $canIssue,
            'unread' => $this->notices->unreadCount($user)]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $own = $this->j->current($user)?->district_id;

        return view('notices.create', [
            'levels' => $this->notices->levelsFor($user),
            'districts' => $user->isStateAdmin() ? District::where('is_active', true)->orderBy('code')->get(['id', 'name', 'code']) : District::whereKey($own)->get(['id', 'name', 'code']),
            'blocks' => Block::where('is_active', true)->when(! $user->isStateAdmin(), fn ($q) => $q->where('district_id', $own))->orderBy('code')->get(['id', 'name', 'code', 'district_id']),
            // clusters only for a district sender (the state has about 4,600; the State Admin narrows by district / block)
            'clusters' => $user->isStateAdmin() ? collect() : Cluster::where('is_active', true)->whereHas('block', fn ($b) => $b->where('district_id', $own))
                ->orderBy('code')->get(['id', 'name', 'code', 'block_id']),
            'ownDistrict' => $own,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::exists('notice_types', 'code')->where('is_active', true)], // 25-11-2026: NoticeType master
            'ref_no' => ['nullable', 'string', 'max:60'],
            'title' => ['required', 'string', 'min:3', 'max:200'],
            'body' => ['required', 'string', 'min:3', 'max:10000'],
            'notice_date' => ['required', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:notice_date'],
            'levels' => ['required', 'array', 'min:1'],
            'levels.*' => ['string'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'block_id' => ['nullable', 'integer', 'exists:blocks,id'],
            'cluster_id' => ['nullable', 'integer', 'exists:clusters,id'],
            'attachment' => ['nullable', 'file', 'mimes:'.implode(',', config('inspection.notice.mimes')), 'max:'.(int) config('inspection.notice.max_kb')],
        ], ['levels.required' => 'Pick who should receive it.', 'attachment.mimes' => 'Attach a PDF, Word or image file.',
            'attachment.max' => 'The attachment can be at most '.\App\Support\FileSize::human((int) config('inspection.notice.max_kb')).'.']);

        $data['show_on_home'] = $request->boolean('show_on_home'); // 25-11-2026: homepage Latest Notice ticker
        $notice = $this->notices->publish($request->user(), $data, $request->file('attachment'));

        return redirect()->route('notices.show', $notice)->with('success', $notice->typeLabel().' sent to '.$notice->recipients()->count().' officer(s).');
    }

    public function show(Request $request, Notice $notice)
    {
        $user = $request->user();
        abort_unless($this->notices->canView($user, $notice), 404);
        $manage = $this->notices->canManage($user, $notice);
        $read = $notice->isFor($user) ? $this->notices->markRead($user, $notice) : null;

        return view('notices.show', ['notice' => $notice->load('creator', 'district', 'block', 'cluster'), 'read' => $read,
            'manage' => $manage, 'report' => $manage ? $this->notices->report($notice) : null]);
    }

    public function acknowledge(Request $request, Notice $notice)
    {
        abort_unless($notice->status === 'published' && $notice->isFor($request->user()), 404);
        $this->notices->acknowledge($request->user(), $notice);

        return back()->with('success', 'Thank you. Your acknowledgement is recorded.');
    }

    public function withdraw(Request $request, Notice $notice)
    {
        abort_unless($this->notices->canManage($request->user(), $notice), 403);
        if (! $notice->isWithdrawn()) {
            $this->notices->withdraw($request->user(), $notice);
        }

        return back()->with('success', 'The '.strtolower($notice->typeLabel()).' is withdrawn. Officers no longer see it in their inbox.');
    }

    /** 25-11-2026: show the notice in the Dashboard "Latest Notice" ticker, or take it off. */
    public function home(Request $request, Notice $notice)
    {
        abort_unless($this->notices->canManage($request->user(), $notice), 403);
        $on = $request->boolean('show_on_home');
        if ($notice->show_on_home !== $on) {
            $notice->update(['show_on_home' => $on]);
            AuditLogger::log('notice_home_changed', $notice, ['title' => $notice->title, 'show_on_home' => $on]);
        }

        return back()->with('success', $on ? 'Shown in Latest Notice on the homepage.' : 'Removed from the homepage Latest Notice.');
    }

    public function attachment(Request $request, Notice $notice)
    {
        abort_unless($notice->attachment_path && $this->notices->canView($request->user(), $notice), 404);
        $disk = Storage::disk(NoticeService::DISK);
        abort_unless($disk->exists($notice->attachment_path), 404);
        AuditLogger::log('notice_attachment_viewed', $notice, ['file' => $notice->attachment_name]);

        return $disk->response($notice->attachment_path, $notice->attachment_name, [
            'Content-Type' => $notice->attachment_mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ], $request->boolean('download') ? 'attachment' : 'inline');
    }
}

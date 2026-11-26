<?php

namespace App\Http\Controllers;

use App\Services\AlertService;
use Illuminate\Http\Request;

/** V15 (26-11-2026): the bell's alerts. Each user sees only their own (another user's id is a 404). */
class AlertController extends Controller
{
    public function __construct(private AlertService $alerts) {}

    public function index(Request $request)
    {
        return view('alerts.index', ['alerts' => $request->user()->notifications()->latest()->paginate(25)]);
    }

    /** JSON for the bell: {count, items}. */
    public function count(Request $request)
    {
        $user = $request->user();

        return response()->json(['count' => $this->alerts->unreadCount($user), 'items' => $request->boolean('items') ? $this->alerts->latest($user) : null])
            ->header('Cache-Control', 'no-store, private');
    }

    /** Mark read and go to the alert's page. */
    public function open(Request $request, string $alert)
    {
        $a = $request->user()->notifications()->whereKey($alert)->firstOrFail();
        if (! $a->read_at) {
            $a->markAsRead();
            AlertService::forget($request->user()->id);
        }
        $to = (string) ($a->data['url'] ?? '');

        // only a path inside the app (the alert stores a relative URL)
        return redirect(str_starts_with($to, '/') && ! str_starts_with($to, '//') ? url($to) : route('alerts.index'));
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);
        AlertService::forget($request->user()->id);

        return back()->with('success', 'All alerts marked as read.');
    }
}

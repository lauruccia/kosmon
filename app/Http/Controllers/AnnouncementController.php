<?php

namespace App\Http\Controllers;

use App\Mail\AnnouncementReplyNotification;
use App\Models\Account;
use App\Models\Announcement;
use App\Models\AnnouncementReply;
use App\Notifications\AnnouncementReplyNotification as AnnouncementReplyInAppNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    // ── Portale: lista pubblica ───────────────────────────────────────────────

    public function index(Request $request): View
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        $type   = $request->query('type', '');
        $sector = $request->query('sector', '');
        $q      = trim((string) $request->query('q', ''));

        $announcementsQuery = Announcement::query()
            ->with('company')
            ->active()
            ->when($type   !== '', fn ($query) => $query->ofType($type))
            ->when($sector !== '', fn ($query) => $query->inSector($sector))
            ->when($q      !== '', fn ($query) => $query->where(function ($scope) use ($q) {
                $scope->where('title', 'like', "%{$q}%")
                      ->orWhere('body', 'like', "%{$q}%")
                      ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            }))
            ->orderByDesc('featured')
            ->orderByDesc('created_at');

        $announcements = $announcementsQuery->paginate(12)->withQueryString();

        $featuredAnnouncements = Announcement::query()
            ->with('company')
            ->active()
            ->featured()
            ->latest()
            ->take(4)
            ->get();

        return view('portal.announcements', [
            'pageTitle'             => 'Annunci del circuito',
            'currentAccount'        => $currentAccount,
            'currentUser'           => $user,
            'announcements'         => $announcements,
            'featuredAnnouncements' => $featuredAnnouncements,
            'sectors'               => Announcement::SECTORS,
            'types'                 => Announcement::TYPES,
            'selectedType'          => $type,
            'selectedSector'        => $sector,
            'searchQuery'           => $q,
            'activeNav'             => 'annunci',
        ]);
    }

    // ── Portale: dettaglio annuncio ───────────────────────────────────────────

    public function show(Request $request, Announcement $announcement): View|RedirectResponse
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        if ($announcement->status !== 'active') {
            return redirect()->route('portal.announcements')
                ->with('portal_error', 'Questo annuncio non è più disponibile.');
        }

        $announcement->increment('views_count');

        // Carica risposte con relazioni — visibili solo al proprietario
        $isOwner = $user->is_super_admin || $announcement->company_id === $user->company_id;
        $replies = $isOwner
            ? $announcement->load(['company', 'replies.user', 'replies.company'])->replies
            : collect();

        // Segna come lette le risposte non ancora viste
        if ($isOwner && $replies->where('is_read', false)->isNotEmpty()) {
            $announcement->replies()->unread()->update(['is_read' => true]);
        }

        return view('portal.announcements-show', [
            'pageTitle'      => $announcement->title . ' — Annunci KMoney',
            'currentAccount' => $currentAccount,
            'currentUser'    => $user,
            'announcement'   => $announcement->load('company'),
            'replies'        => $replies,
            'isOwner'        => $isOwner,
            'related'        => Announcement::query()
                ->with('company')
                ->active()
                ->inSector($announcement->sector)
                ->whereKeyNot($announcement->id)
                ->latest()
                ->take(3)
                ->get(),
            'activeNav'      => 'annunci',
        ]);
    }

    // ── Portale: form creazione ───────────────────────────────────────────────

    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        if (! $user->canAccessMarketplace()) {
            return redirect()->route('portal.announcements')
                ->with('portal_error', 'Non hai i permessi per pubblicare annunci.');
        }

        return view('portal.announcements-create', [
            'pageTitle'           => 'Pubblica un annuncio',
            'currentAccount'      => $currentAccount,
            'currentUser'         => $user,
            'sectors'             => Announcement::SECTORS,
            'types'               => Announcement::TYPES,
            'editingAnnouncement' => null,
            'activeNav'           => 'annunci',
        ]);
    }

    // ── Portale: salva nuovo annuncio ─────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->canAccessMarketplace()) {
            abort(403);
        }

        $validated = $this->validateAnnouncement($request);

        Announcement::create(array_merge($validated, [
            'company_id'         => $user->company_id,
            'created_by_user_id' => $user->id,
            'status'             => 'active',
        ]));

        return redirect()->route('portal.announcements')
            ->with('portal_success', 'Annuncio pubblicato nel circuito.');
    }

    // ── Portale: form modifica ────────────────────────────────────────────────

    public function edit(Request $request, Announcement $announcement): View|RedirectResponse
    {
        $user = $request->user();
        $currentAccount = $this->resolveAccount($user);

        abort_unless($user->is_super_admin || $announcement->company_id === $user->company_id, 403);

        return view('portal.announcements-create', [
            'pageTitle'           => 'Modifica annuncio',
            'currentAccount'      => $currentAccount,
            'currentUser'         => $user,
            'sectors'             => Announcement::SECTORS,
            'types'               => Announcement::TYPES,
            'editingAnnouncement' => $announcement,
            'activeNav'           => 'annunci',
        ]);
    }

    // ── Portale: aggiorna annuncio ────────────────────────────────────────────

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->is_super_admin || $announcement->company_id === $user->company_id, 403);

        $validated = $this->validateAnnouncement($request);
        $announcement->update($validated);

        return redirect()->route('portal.announcements')
            ->with('portal_success', 'Annuncio aggiornato correttamente.');
    }

    // ── Portale: elimina annuncio ─────────────────────────────────────────────

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->is_super_admin || $announcement->company_id === $user->company_id, 403);

        $announcement->delete();

        return redirect()->route('portal.announcements')
            ->with('portal_success', 'Annuncio rimosso.');
    }

    // ── Portale: invia risposta a un annuncio ────────────────────────────────

    public function reply(Request $request, Announcement $announcement): RedirectResponse
    {
        $user = $request->user();

        if ($announcement->status !== 'active') {
            return back()->with('portal_error', 'Questo annuncio non è più disponibile.');
        }

        // Il proprietario non può rispondere al proprio annuncio
        if ($announcement->company_id === $user->company_id && ! $user->is_super_admin) {
            return back()->with('portal_error', 'Non puoi rispondere al tuo stesso annuncio.');
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:10', 'max:1500'],
        ]);

        $reply = AnnouncementReply::create([
            'announcement_id' => $announcement->id,
            'user_id'         => $user->id,
            'company_id'      => $user->company_id,
            'message'         => $validated['message'],
        ]);

        // Notifica il pubblicatore (email + in-app)
        $publisher = $announcement->createdByUser;
        if ($publisher && $publisher->email) {
            Mail::to($publisher->email)
                ->queue(new AnnouncementReplyNotification(
                    recipient: $publisher,
                    announcement: $announcement,
                    reply: $reply->load(['user', 'company']),
                ));
            $publisher->notify(new AnnouncementReplyInAppNotification(
                announcement: $announcement,
                reply: $reply,
            ));
        }

        return back()->with('portal_success', 'La tua risposta è stata inviata. Il pubblicatore riceverà una notifica.');
    }

    // ── Admin: lista moderazione ──────────────────────────────────────────────

    public function adminIndex(Request $request): View
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $q      = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $type   = (string) $request->query('type', '');

        $announcements = Announcement::query()
            ->with(['company', 'createdByUser'])
            ->when($q !== '', fn ($query) => $query->where('title', 'like', "%{$q}%")
                ->orWhereHas('company', fn ($c) => $c->where('name', 'like', "%{$q}%")))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($type   !== '', fn ($query) => $query->where('type', $type))
            ->orderByDesc('created_at')
            ->paginate(20)->withQueryString();

        return view('admin.announcements', [
            'pageTitle'     => 'Moderazione Annunci',
            'announcements' => $announcements,
            'statuses'      => Announcement::STATUSES,
            'types'         => Announcement::TYPES,
            'activeNav'     => 'admin',
        ]);
    }

    // ── Admin: cambia stato ───────────────────────────────────────────────────

    public function adminUpdateStatus(Request $request, Announcement $announcement): RedirectResponse
    {
        abort_unless($request->user()->canAccessBackoffice(), 403);

        $request->validate(['status' => ['required', Rule::in(Announcement::STATUSES)]]);
        $announcement->update(['status' => $request->input('status')]);

        return back()->with('portal_success', 'Stato annuncio aggiornato.');
    }

    // ── Helpers privati ───────────────────────────────────────────────────────

    private function validateAnnouncement(Request $request): array
    {
        return $request->validate([
            'type'         => ['required', Rule::in(array_keys(Announcement::TYPES))],
            'title'        => ['required', 'string', 'max:160'],
            'body'         => ['required', 'string', 'max:3000'],
            'sector'       => ['required', Rule::in(array_keys(Announcement::SECTORS))],
            'contact_info' => ['nullable', 'string', 'max:200'],
            'expires_at'   => ['nullable', 'date', 'after:today'],
            'featured'     => ['nullable', 'boolean'],
        ]);
    }

    private function resolveAccount($user): Account
    {
        if ($user->managed_account_id) {
            return Account::query()->with(['company', 'ownerUser'])->findOrFail($user->managed_account_id);
        }
        if ($user->company_id) {
            return Account::query()->with(['company'])->where('company_id', $user->company_id)->whereNull('parent_account_id')->firstOrFail();
        }
        return Account::query()->with(['ownerUser'])->where('owner_user_id', $user->id)->whereNull('parent_account_id')->firstOrFail();
    }
}

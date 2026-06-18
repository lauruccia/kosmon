<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesBackoffice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedResponse;

class AuditController extends Controller
{
    use AuthorizesBackoffice;

    public function auditLog(Request $request): \Illuminate\View\View
    {
        $this->authorizeBackoffice($request->user());

        $event  = trim((string) $request->query('event', ''));
        $userId = (int) $request->query('user_id', 0);
        $from   = $request->query('from', '');
        $to     = $request->query('to', '');

        $query = \App\Models\AuditLog::query()
            ->with('actor')
            ->when($event, fn ($q) => $q->where('event', $event))
            ->when($userId, fn ($q) => $q->where('actor_user_id', $userId))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from), fn ($q) => $q->where('created_at', '>=', $from . ' 00:00:00'))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $to),   fn ($q) => $q->where('created_at', '<=', $to . ' 23:59:59'))
            ->latest()
            ->paginate(50)->withQueryString();

        // Distinct events for filter dropdown
        $eventOptions = \App\Models\AuditLog::query()
            ->distinct()
            ->orderBy('event')
            ->pluck('event');

        return view('admin.audit', [
            'pageTitle'    => 'Audit Log',
            'logs'         => $query,
            'eventOptions' => $eventOptions,
            'filters'      => compact('event', 'userId', 'from', 'to'),
            'activeNav'    => 'audit',
        ]);
    }

    public function exportAuditCsv(Request $request): StreamedResponse
    {
        $this->authorizeBackoffice($request->user());

        $event  = trim((string) $request->query('event', ''));
        $userId = (int) $request->query('user_id', 0);
        $from   = $request->query('from', '');
        $to     = $request->query('to', '');

        $query = \App\Models\AuditLog::query()
            ->with('actor')
            ->when($event,  fn ($q) => $q->where('event', $event))
            ->when($userId, fn ($q) => $q->where('actor_user_id', $userId))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from), fn ($q) => $q->where('created_at', '>=', $from . ' 00:00:00'))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $to),   fn ($q) => $q->where('created_at', '<=', $to . ' 23:59:59'))
            ->latest();

        $filename = 'audit-log-kmoney-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');

            // BOM UTF-8 per Excel
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'ID', 'UUID', 'Data', 'Ora', 'Evento',
                'Attore (nome)', 'Attore (email)',
                'Tipo riferimento', 'ID riferimento',
                'IP', 'Contesto JSON',
            ], ';');

            $query->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $log) {
                    fputcsv($out, [
                        $log->id,
                        $log->uuid,
                        $log->created_at->format('d/m/Y'),
                        $log->created_at->format('H:i:s'),
                        $log->event,
                        $log->actor?->name ?? 'Sistema',
                        $log->actor?->email ?? '',
                        $log->auditable_type ? class_basename($log->auditable_type) : '',
                        $log->auditable_id ?? '',
                        $log->ip_address ?? '',
                        $log->context ? json_encode($log->context, JSON_UNESCAPED_UNICODE) : '',
                    ], ';');
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}

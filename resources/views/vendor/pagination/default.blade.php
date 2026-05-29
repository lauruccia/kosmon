@if ($paginator->hasPages())
<nav style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:20px;flex-wrap:wrap;">
    <div style="font-size:13px;color:var(--ink-muted);">
        {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} di {{ $paginator->total() }} risultati
    </div>
    <div style="display:flex;gap:4px;align-items:center;">
        {{-- Prev --}}
        @if ($paginator->onFirstPage())
            <span style="padding:6px 12px;border-radius:8px;font-size:13px;color:var(--ink-muted);background:var(--surface-soft);border:1px solid var(--line);cursor:not-allowed;">&laquo;</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" style="padding:6px 12px;border-radius:8px;font-size:13px;color:var(--ink);background:var(--surface);border:1px solid var(--line);text-decoration:none;" rel="prev">&laquo;</a>
        @endif

        {{-- Pages --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <span style="padding:6px 8px;font-size:13px;color:var(--ink-muted);">…</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span style="padding:6px 12px;border-radius:8px;font-size:13px;font-weight:700;background:var(--primary);color:#fff;border:1px solid var(--primary);">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" style="padding:6px 12px;border-radius:8px;font-size:13px;color:var(--ink);background:var(--surface);border:1px solid var(--line);text-decoration:none;">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" style="padding:6px 12px;border-radius:8px;font-size:13px;color:var(--ink);background:var(--surface);border:1px solid var(--line);text-decoration:none;" rel="next">&raquo;</a>
        @else
            <span style="padding:6px 12px;border-radius:8px;font-size:13px;color:var(--ink-muted);background:var(--surface-soft);border:1px solid var(--line);cursor:not-allowed;">&raquo;</span>
        @endif
    </div>
</nav>
@endif

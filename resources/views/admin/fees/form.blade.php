@extends('layouts.portal')
@section('content')
<div style="max-width:520px;margin:0 auto;padding:0 16px 48px;">
    <div style="margin-bottom:24px;">
        <div class="eyebrow">Admin</div>
        <h1 class="page-title">{{ $pageTitle }}</h1>
    </div>

    <section class="card card-pad">
        <form method="POST" action="{{ $fee ? route('admin.fees.update', $fee) : route('admin.fees.store') }}">
            @csrf
            @if($fee) @method('PUT') @endif

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Tipo operazione</label>
                <select name="operation_kind" class="form-control">
                    @foreach($kindOptions as $value => $label)
                        <option value="{{ $value }}" {{ old('operation_kind', $fee?->operation_kind) === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Tipo commissione</label>
                <select name="fee_type" class="form-control" id="fee_type_select">
                    <option value="flat" {{ old('fee_type', $fee?->fee_type) === 'flat' ? 'selected' : '' }}>Flat — importo fisso KY</option>
                    <option value="percentage" {{ old('fee_type', $fee?->fee_type) === 'percentage' ? 'selected' : '' }}>Percentuale — % sull'importo</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Valore <span id="fee_unit_label">(KY)</span></label>
                <input type="number" name="fee_value" step="0.01" min="0" class="form-control"
                       value="{{ old('fee_value', $fee?->fee_value ?? 0) }}" required>
            </div>

            <div id="pct_options" style="display:{{ old('fee_type', $fee?->fee_type) === 'percentage' ? 'block' : 'none' }}">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                    <div class="form-group">
                        <label class="form-label">Min KY (opzionale)</label>
                        <input type="number" name="min_fee" min="0" class="form-control" value="{{ old('min_fee', $fee?->min_fee) }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max KY (opzionale)</label>
                        <input type="number" name="max_fee" min="0" class="form-control" value="{{ old('max_fee', $fee?->max_fee) }}">
                    </div>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Descrizione interna (opzionale)</label>
                <input type="text" name="description" class="form-control" value="{{ old('description', $fee?->description) }}">
            </div>

            <div class="form-group" style="margin-bottom:20px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $fee?->is_active ?? true) ? 'checked' : '' }}>
                    Commissione attiva
                </label>
            </div>

            <div style="display:flex;gap:10px;">
                <button type="submit" class="cta">Salva</button>
                <a href="{{ route('admin.fees.index') }}" class="cta secondary">Annulla</a>
            </div>
        </form>
    </section>
</div>

<script>
document.getElementById('fee_type_select').addEventListener('change', function() {
    var isPct = this.value === 'percentage';
    document.getElementById('pct_options').style.display = isPct ? 'block' : 'none';
    document.getElementById('fee_unit_label').textContent = isPct ? '(%)' : '(KY)';
});
</script>
@endsection

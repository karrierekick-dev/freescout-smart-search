@php
    $index = (int)($index ?? 0);
    $filter = (array)($filter ?? []);
    $fid = (int)($filter['id'] ?? 0);
    $fvals = array_map('strval', (array)($filter['values'] ?? []));
    $meta = $filter['meta'] ?? null;
    if (!$meta && $fid) {
        foreach (($fields ?? []) as $f) {
            if ((int)$f['id'] === $fid) {
                $meta = $f;
                break;
            }
        }
    }
    $type = (int)($meta['type'] ?? 0);
    $options = (!empty($meta['options']) && is_array($meta['options'])) ? $meta['options'] : [];
    // Real dropdown/multiselect options (ignore autosuggest-only type-2 options).
    $hasRealOptions = false;
    if (in_array($type, [1, 8], true) && is_array($options) && count($options)) {
        $optKeys = array_keys($options);
        $hasRealOptions = !(count($optKeys) === 1 && (string)$optKeys[0] === 'autosuggest');
    }
    $displayOptions = [];
    if ($hasRealOptions) {
        foreach ($options as $optKey => $optLabel) {
            if ((string)$optKey === 'autosuggest') {
                continue;
            }
            $displayOptions[$optKey] = $optLabel;
        }
    }

    $defaultOps = [
        1 => 'is',
        2 => 'contains',
        3 => 'contains',
        4 => 'equals',
        5 => 'on',
        7 => 'contains_any',
        8 => 'contains_any',
    ];
    $fop = trim((string)($filter['op'] ?? ''));
    if ($fop === '' && $fid) {
        $fop = $defaultOps[$type] ?? 'contains';
    }

    $opsByType = [
        1 => ['is', 'empty'],
        2 => ['is', 'contains', 'empty'],
        3 => ['contains', 'empty'],
        4 => ['equals', 'gt', 'lt', 'between', 'empty'],
        5 => ['on', 'before', 'after', 'between', 'empty'],
        7 => ['contains_any', 'empty'],
        8 => ['contains_any', 'empty'],
    ];
    $opLabels = [
        'is' => __('is'),
        'contains' => __('contains'),
        'equals' => __('equals'),
        'gt' => __('greater than'),
        'lt' => __('less than'),
        'between' => __('between'),
        'on' => __('on'),
        'before' => __('before'),
        'after' => __('after'),
        'contains_any' => __('contains any'),
        'empty' => __('Empty / not set'),
    ];
    $allowedOps = $opsByType[$type] ?? ['contains', 'empty'];
    if ($fop !== '' && !in_array($fop, $allowedOps, true)) {
        $fop = $defaultOps[$type] ?? 'contains';
    }

    $nonEmptyVals = array_values(array_filter($fvals, function ($v) {
        return $v !== '' && $v !== '__empty__';
    }));
    $val0 = $nonEmptyVals[0] ?? '';
    $val1 = $nonEmptyVals[1] ?? '';
    $isEmptyOp = ($fop === 'empty');
    $isBetween = ($fop === 'between');
@endphp
<div class="smartsearch-cf-row" data-index="{{ $index }}" data-type="{{ $type }}">
    <div class="smartsearch-cf-row-head">
        <select class="form-control smartsearch-cf-id" name="cf_filters[{{ $index }}][id]">
            <option value="0">{{ __('Choose field…') }}</option>
            @foreach (($fields ?? []) as $f)
                <option value="{{ $f['id'] }}" data-type="{{ (int)($f['type'] ?? 0) }}" @if($fid === (int)$f['id']) selected @endif>{{ $f['name'] }}</option>
            @endforeach
        </select>
        <button type="button" class="btn btn-default btn-sm smartsearch-cf-remove" title="{{ __('Remove field') }}">&times;</button>
    </div>
    <div class="smartsearch-cf-values" @if(!$fid) style="display:none" @endif>
        @if ($fid)
            <div class="smartsearch-cf-op-wrap">
                <label class="smartsearch-cf-op-label">{{ __('Operator') }}</label>
                <select class="form-control smartsearch-cf-op" name="cf_filters[{{ $index }}][op]">
                    @foreach ($allowedOps as $opKey)
                        <option value="{{ $opKey }}" @if($fop === $opKey) selected @endif>{{ $opLabels[$opKey] ?? $opKey }}</option>
                    @endforeach
                </select>
            </div>
            <div class="smartsearch-cf-value-ui" @if($isEmptyOp) style="display:none" @endif>
                @if ($hasRealOptions && in_array($fop, ['is', 'contains_any'], true))
                    <div class="smartsearch-check-list">
                        @foreach ($displayOptions as $optKey => $optLabel)
                            @php
                                // Type 1 stores option KEYs; type 8 stores comma-separated LABELS.
                                $optValue = ($type === 8) ? (string)$optLabel : (string)$optKey;
                            @endphp
                            <label class="checkbox">
                                <input type="checkbox" name="cf_filters[{{ $index }}][values][]" value="{{ $optValue }}" @if(in_array($optValue, $fvals, true)) checked @endif>
                                {{ $optLabel }}
                            </label>
                        @endforeach
                    </div>
                @elseif ($type === 4)
                    @if ($isBetween)
                        <div class="smartsearch-cf-between">
                            <input type="number" step="any" class="form-control" name="cf_filters[{{ $index }}][values][]" placeholder="{{ __('From') }}" value="{{ $val0 }}">
                            <span class="smartsearch-cf-between-sep">–</span>
                            <input type="number" step="any" class="form-control" name="cf_filters[{{ $index }}][values][]" placeholder="{{ __('To') }}" value="{{ $val1 }}">
                        </div>
                    @else
                        <input type="number" step="any" class="form-control" name="cf_filters[{{ $index }}][values][]" placeholder="{{ __('Value') }}" value="{{ $val0 }}">
                    @endif
                @elseif ($type === 5)
                    @if ($isBetween)
                        <div class="smartsearch-cf-between">
                            <input type="date" class="form-control" name="cf_filters[{{ $index }}][values][]" value="{{ $val0 }}">
                            <span class="smartsearch-cf-between-sep">–</span>
                            <input type="date" class="form-control" name="cf_filters[{{ $index }}][values][]" value="{{ $val1 }}">
                        </div>
                    @else
                        <input type="date" class="form-control" name="cf_filters[{{ $index }}][values][]" value="{{ $val0 }}">
                    @endif
                @elseif ($type === 7)
                    <input
                        type="text"
                        class="form-control"
                        name="cf_filters[{{ $index }}][values][]"
                        placeholder="{{ __('Value1, Value2…') }}"
                        value="{{ implode(', ', $nonEmptyVals) }}"
                    >
                    <p class="help-block">{{ __('Enter one or more values separated by commas (OR). Leave empty to match any value / use the query.') }}</p>
                @else
                    {{-- Types 2/3 text (and fallback) --}}
                    <input
                        type="text"
                        class="form-control"
                        name="cf_filters[{{ $index }}][values][]"
                        placeholder="{{ __('Value') }}"
                        value="{{ $val0 }}"
                    >
                @endif
            </div>
        @endif
    </div>
</div>

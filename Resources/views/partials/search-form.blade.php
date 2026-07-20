@php
    $selectedStatuses = array_map('intval', (array)($statuses ?? []));
    $cfFilters = (array)($cfFilters ?? []);
    if (!count($cfFilters) && !empty($fieldId)) {
        $cfFilters = [[
            'id' => (int)$fieldId,
            'values' => (array)($fieldValues ?? []),
            'meta' => $selectedField ?? null,
        ]];
    }
    $advanced = (array)($advanced ?? []);
    $searchInNow = (array)($advanced['search_in'] ?? ['subject', 'messages', 'notes']);
    sort($searchInNow);
    $advancedActive = (($advanced['date_from'] ?? '') !== '')
        || (($advanced['date_to'] ?? '') !== '')
        || (($advanced['date_field'] ?? 'updated') === 'created')
        || !empty($advanced['tag_ids'])
        || (($advanced['customer_q'] ?? '') !== '')
        || (($advanced['has_attachments'] ?? '') !== '')
        || (($advanced['waiting_reply'] ?? '') !== '')
        || ($searchInNow !== ['messages', 'notes', 'subject']);
    $moreOpen = ((int)($folderId ?? 0) !== 0)
        || ((int)($assigneeId ?? 0) !== 0)
        || (!preg_match('/^cf_\d+_(asc|desc)$/', (string)($sort ?? '')) && ($sort ?? 'updated_desc') !== 'updated_desc')
        || $advancedActive;
    $cfModuleOk = !empty($cfModuleOk);
@endphp

<div class="smartsearch-card">
    <form method="GET" action="{{ route('smartsearch.search') }}" class="smartsearch-form" id="smartsearch-form">
        <input type="hidden" name="ss" value="1">

        <div class="smartsearch-query-wrap">
            <label class="smartsearch-query-label" for="smartsearch-q">{{ __('Query') }}</label>
            <div class="smartsearch-query-input-wrap">
                <input
                    id="smartsearch-q"
                    type="search"
                    class="form-control smartsearch-query-input"
                    name="q"
                    value="{{ $q }}"
                    placeholder="{{ __('Search conversations, customers, custom fields…') }}"
                    autocomplete="off"
                    autofocus
                >
            </div>
            <p class="smartsearch-tip">{{ __('Tip: #1234 prefers the FreeScout conversation number; plain 1234 prefers the internal conversation ID. Search also checks message and note content.') }}</p>
        </div>

        <div class="smartsearch-filter-grid" data-fields="{{ e(json_encode(array_values($fields ?? []))) }}">
            {{-- Mailbox --}}
            <div class="smartsearch-tile" data-tile="mailbox">
                <button type="button" class="smartsearch-tile-btn" aria-expanded="false">
                    <span class="smartsearch-tile-icon glyphicon glyphicon-envelope" aria-hidden="true"></span>
                    <span class="smartsearch-tile-body">
                        <span class="smartsearch-tile-label">{{ __('Mailbox') }}</span>
                        <span class="smartsearch-tile-value smartsearch-tile-mailbox-label">
                            @if ((int)$mailboxId)
                                @php $mbName = ''; foreach (($mailboxes ?? []) as $mb) { if ((int)$mb->id === (int)$mailboxId) { $mbName = $mb->name; break; } } @endphp
                                {{ $mbName !== '' ? $mbName : __('All accessible mailboxes') }}
                            @else
                                {{ __('All accessible mailboxes') }}
                            @endif
                        </span>
                    </span>
                    <span class="smartsearch-tile-caret glyphicon glyphicon-chevron-down" aria-hidden="true"></span>
                </button>
                <div class="smartsearch-tile-panel" hidden>
                    <div class="smartsearch-option-list" data-tile-field="mailbox_id">
                        <label class="radio">
                            <input type="radio" name="mailbox_id" value="0" @if(!(int)$mailboxId) checked @endif>
                            {{ __('All accessible mailboxes') }}
                        </label>
                        @foreach (($mailboxes ?? collect()) as $mb)
                            <label class="radio">
                                <input type="radio" name="mailbox_id" value="{{ $mb->id }}" @if((int)$mailboxId === (int)$mb->id) checked @endif>
                                {{ $mb->name }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Status (checkboxes) --}}
            <div class="smartsearch-tile" data-tile="status">
                <button type="button" class="smartsearch-tile-btn" aria-expanded="false">
                    <span class="smartsearch-tile-icon glyphicon glyphicon-record" aria-hidden="true"></span>
                    <span class="smartsearch-tile-body">
                        <span class="smartsearch-tile-label">{{ __('Status') }}</span>
                        <span class="smartsearch-tile-value smartsearch-status-pills">
                            @if (!count($selectedStatuses))
                                <span class="smartsearch-muted">{{ __('Any status') }}</span>
                            @else
                                @foreach ($selectedStatuses as $code)
                                    <span class="smartsearch-pill">{{ $statusOptions[$code] ?? $code }}</span>
                                @endforeach
                            @endif
                        </span>
                    </span>
                    <span class="smartsearch-tile-caret glyphicon glyphicon-chevron-down" aria-hidden="true"></span>
                </button>
                <div class="smartsearch-tile-panel" hidden>
                    <div class="smartsearch-check-list smartsearch-status-checks">
                        @foreach (($statusOptions ?? []) as $code => $label)
                            <label class="checkbox">
                                <input type="checkbox" name="status[]" value="{{ $code }}" @if(in_array((int)$code, $selectedStatuses, true)) checked @endif>
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <p class="help-block">{{ __('Select one or more statuses (OR). Leave empty for any status.') }}</p>
                </div>
            </div>

            @if ($cfModuleOk)
            {{-- Custom Fields: always visible primary filter (same row as Mailbox/Status from 1200px) --}}
            <div class="smartsearch-tile smartsearch-tile-cf" data-tile="fields">
                <button type="button" class="smartsearch-tile-btn" aria-expanded="false">
                    <span class="smartsearch-tile-icon glyphicon glyphicon-list-alt" aria-hidden="true"></span>
                    <span class="smartsearch-tile-body">
                        <span class="smartsearch-tile-label">{{ __('Custom Fields') }}</span>
                        <span class="smartsearch-tile-value smartsearch-cf-summary">
                            @if (!count($cfFilters))
                                <span class="smartsearch-muted">—</span>
                            @else
                                @foreach ($cfFilters as $cf)
                                    @php
                                        $cfName = $cf['meta']['name'] ?? ('#'.$cf['id']);
                                        $cfType = (int)($cf['meta']['type'] ?? 0);
                                        $cfOp = (string)($cf['op'] ?? '');
                                        if ($cfOp === '') {
                                            $cfOpDefaults = [1=>'is',2=>'contains',3=>'contains',4=>'equals',5=>'on',7=>'contains_any',8=>'contains_any'];
                                            $cfOp = $cfOpDefaults[$cfType] ?? 'contains';
                                        }
                                        $cfOpBrief = [
                                            'is' => '=',
                                            'contains' => __('contains'),
                                            'equals' => '=',
                                            'gt' => '>',
                                            'lt' => '<',
                                            'between' => __('between'),
                                            'on' => '=',
                                            'before' => __('before'),
                                            'after' => __('after'),
                                            'contains_any' => __('contains any'),
                                            'empty' => __('Empty / not set'),
                                        ];
                                        $cfOpts = (!empty($cf['meta']['options']) && is_array($cf['meta']['options'])) ? $cf['meta']['options'] : [];
                                        $cfValLabels = [];
                                        foreach ((array)($cf['values'] ?? []) as $rawVal) {
                                            $rawVal = (string)$rawVal;
                                            if ($rawVal === '') {
                                                continue;
                                            }
                                            if ($rawVal === '__empty__') {
                                                $cfValLabels[] = __('Empty / not set');
                                                continue;
                                            }
                                            // Type 1: keys → labels; type 8 values are already labels.
                                            if ($cfType === 1 && isset($cfOpts[$rawVal])) {
                                                $cfValLabels[] = (string)$cfOpts[$rawVal];
                                                continue;
                                            }
                                            if ($cfType === 8) {
                                                $cfValLabels[] = $rawVal;
                                                continue;
                                            }
                                            foreach (preg_split('/\s*,\s*/', $rawVal) ?: [] as $part) {
                                                $part = trim((string)$part);
                                                if ($part !== '') {
                                                    $cfValLabels[] = $part;
                                                }
                                            }
                                        }
                                        $cfValLabels = array_values(array_unique($cfValLabels));
                                        $cfPill = $cfName;
                                        if ($cfOp === 'empty') {
                                            $cfPill .= ' '.$cfOpBrief['empty'];
                                        } elseif (count($cfValLabels)) {
                                            $opWord = $cfOpBrief[$cfOp] ?? $cfOp;
                                            if (in_array($cfOp, ['is', 'equals', 'on', 'gt', 'lt'], true)) {
                                                $cfPill .= ' '.$opWord.' '.implode(' | ', $cfValLabels);
                                            } elseif ($cfOp === 'between' && count($cfValLabels) >= 2) {
                                                $cfPill .= ' '.$opWord.' '.$cfValLabels[0].'–'.$cfValLabels[1];
                                            } else {
                                                $cfPill .= ' '.$opWord.' '.implode(' | ', $cfValLabels);
                                            }
                                        }
                                    @endphp
                                    <span class="smartsearch-pill">{{ $cfPill }}</span>
                                @endforeach
                            @endif
                        </span>
                    </span>
                    <span class="smartsearch-tile-caret glyphicon glyphicon-chevron-down" aria-hidden="true"></span>
                </button>
                <div class="smartsearch-tile-panel smartsearch-cf-panel" hidden>
                    <div class="smartsearch-cf-rows" data-empty-label="{{ __('Empty / not set') }}" data-any-value="{{ __('Any value') }}" data-add-label="{{ __('Add field') }}" data-remove-label="{{ __('Remove field') }}" data-fields="{{ e(json_encode(array_values($fields ?? []))) }}">
                        @forelse ($cfFilters as $idx => $cf)
                            @include('smartsearch::partials.cf-filter-row', [
                                'index' => $idx,
                                'fields' => $fields ?? [],
                                'filter' => $cf,
                            ])
                        @empty
                            @include('smartsearch::partials.cf-filter-row', [
                                'index' => 0,
                                'fields' => $fields ?? [],
                                'filter' => ['id' => 0, 'values' => [], 'meta' => null],
                            ])
                        @endforelse
                    </div>
                    <button type="button" class="btn btn-link btn-sm smartsearch-cf-add">+ {{ __('Add field') }}</button>
                    <p class="help-block">{{ __('Multiple fields are combined with AND. Values within a field use OR.') }}</p>
                </div>
            </div>
            @endif
        </div>

        <div class="smartsearch-more-wrap @if(!$moreOpen) is-collapsed @endif" id="smartsearch-more-wrap" aria-hidden="{{ $moreOpen ? 'false' : 'true' }}">
            <div class="smartsearch-more-anim">
                <div class="smartsearch-filter-grid smartsearch-more-grid">
            {{-- Assignee --}}
            <div class="smartsearch-tile" data-tile="assignee">
                <button type="button" class="smartsearch-tile-btn" aria-expanded="false">
                    <span class="smartsearch-tile-icon glyphicon glyphicon-user" aria-hidden="true"></span>
                    <span class="smartsearch-tile-body">
                        <span class="smartsearch-tile-label">{{ __('Assignee') }}</span>
                        <span class="smartsearch-tile-value smartsearch-tile-assignee-label">
                            @if ((int)$assigneeId === -1)
                                {{ __('Unassigned') }}
                            @elseif ((int)$assigneeId > 0)
                                @php $an = ''; foreach (($assignees ?? []) as $a) { if ((int)$a['id'] === (int)$assigneeId) { $an = $a['name']; break; } } @endphp
                                {{ $an !== '' ? $an : __('Any assignee') }}
                            @else
                                {{ __('Any assignee') }}
                            @endif
                        </span>
                    </span>
                    <span class="smartsearch-tile-caret glyphicon glyphicon-chevron-down" aria-hidden="true"></span>
                </button>
                <div class="smartsearch-tile-panel" hidden>
                    <div class="smartsearch-option-list" data-tile-field="assignee_id">
                        <label class="radio">
                            <input type="radio" name="assignee_id" value="0" @if((int)($assigneeId ?? 0) === 0) checked @endif>
                            {{ __('Any assignee') }}
                        </label>
                        <label class="radio">
                            <input type="radio" name="assignee_id" value="-1" @if((int)($assigneeId ?? 0) === -1) checked @endif>
                            {{ __('Unassigned') }}
                        </label>
                        @foreach (($assignees ?? []) as $assignee)
                            <label class="radio">
                                <input type="radio" name="assignee_id" value="{{ $assignee['id'] }}" @if((int)($assigneeId ?? 0) === (int)$assignee['id']) checked @endif>
                                {{ $assignee['name'] }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Folder --}}
            <div class="smartsearch-tile" data-tile="folder">
                <button type="button" class="smartsearch-tile-btn" aria-expanded="false">
                    <span class="smartsearch-tile-icon glyphicon glyphicon-folder-open" aria-hidden="true"></span>
                    <span class="smartsearch-tile-body">
                        <span class="smartsearch-tile-label">{{ __('Folder') }}</span>
                        <span class="smartsearch-tile-value smartsearch-tile-folder-label">
                            @if ((int)($folderId ?? 0) && !empty($folders))
                                @php $fn = ''; foreach ($folders as $f) { if ((int)$f['id'] === (int)$folderId) { $fn = $f['name']; break; } } @endphp
                                {{ $fn !== '' ? $fn : __('Any folder') }}
                            @else
                                {{ __('Any folder') }}
                            @endif
                        </span>
                    </span>
                    <span class="smartsearch-tile-caret glyphicon glyphicon-chevron-down" aria-hidden="true"></span>
                </button>
                <div class="smartsearch-tile-panel" hidden>
                    <div class="smartsearch-option-list" data-tile-field="folder_id">
                        <label class="radio">
                            <input type="radio" name="folder_id" value="0" @if((int)($folderId ?? 0) === 0) checked @endif @if(empty($foldersOk)) disabled @endif>
                            {{ __('Any folder') }}
                        </label>
                        @if (!empty($foldersOk) && !empty($folders) && is_array($folders))
                            @foreach ($folders as $f)
                                <label class="radio">
                                    <input type="radio" name="folder_id" value="{{ $f['id'] }}" @if((int)($folderId ?? 0) === (int)$f['id']) checked @endif>
                                    {{ $f['name'] }}
                                </label>
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>

            {{-- Sort --}}
            <div class="smartsearch-tile" data-tile="sort">
                <button type="button" class="smartsearch-tile-btn" aria-expanded="false">
                    <span class="smartsearch-tile-icon glyphicon glyphicon-sort" aria-hidden="true"></span>
                    <span class="smartsearch-tile-body">
                        <span class="smartsearch-tile-label">{{ __('Sort') }}</span>
                        <span class="smartsearch-tile-value smartsearch-tile-sort-label">
                            @if (($sort ?? '') === 'updated_asc') {{ __('Updated: oldest') }}
                            @elseif (($sort ?? '') === 'created_desc') {{ __('Created: newest') }}
                            @elseif (($sort ?? '') === 'created_asc') {{ __('Created: oldest') }}
                            @elseif (($sort ?? '') === 'id_desc') {{ __('Ticket #: highest') }}
                            @elseif (($sort ?? '') === 'id_asc') {{ __('Ticket #: lowest') }}
                            @else {{ __('Updated: newest') }}
                            @endif
                        </span>
                    </span>
                    <span class="smartsearch-tile-caret glyphicon glyphicon-chevron-down" aria-hidden="true"></span>
                </button>
                <div class="smartsearch-tile-panel" hidden>
                    <div class="smartsearch-option-list" data-tile-field="sort">
                        <label class="radio">
                            <input type="radio" name="sort" value="updated_desc" @if(($sort ?? 'updated_desc') === 'updated_desc') checked @endif>
                            {{ __('Updated: newest') }}
                        </label>
                        <label class="radio">
                            <input type="radio" name="sort" value="updated_asc" @if(($sort ?? 'updated_desc') === 'updated_asc') checked @endif>
                            {{ __('Updated: oldest') }}
                        </label>
                        <label class="radio">
                            <input type="radio" name="sort" value="created_desc" @if(($sort ?? 'updated_desc') === 'created_desc') checked @endif>
                            {{ __('Created: newest') }}
                        </label>
                        <label class="radio">
                            <input type="radio" name="sort" value="created_asc" @if(($sort ?? 'updated_desc') === 'created_asc') checked @endif>
                            {{ __('Created: oldest') }}
                        </label>
                        <label class="radio">
                            <input type="radio" name="sort" value="id_desc" @if(($sort ?? 'updated_desc') === 'id_desc') checked @endif>
                            {{ __('Ticket #: highest') }}
                        </label>
                        <label class="radio">
                            <input type="radio" name="sort" value="id_asc" @if(($sort ?? 'updated_desc') === 'id_asc') checked @endif>
                            {{ __('Ticket #: lowest') }}
                        </label>
                    </div>
                </div>
            </div>
                </div>

                @include('smartsearch::partials.advanced-filters', [
                    'advanced' => $advanced,
                    'tags' => $tags ?? [],
                    'tagsOk' => $tagsOk ?? false,
                ])
            </div>
        </div>

        <div class="smartsearch-form-actions">
            <button type="button" class="btn btn-link smartsearch-more-toggle" data-expanded="{{ $moreOpen ? '1' : '0' }}" aria-controls="smartsearch-more-wrap" aria-expanded="{{ $moreOpen ? 'true' : 'false' }}">
                <span class="smartsearch-more-toggle-label">{{ $moreOpen ? __('Hide more options') : __('Show more options') }}</span>
                <span class="smartsearch-more-toggle-caret glyphicon glyphicon-chevron-down" aria-hidden="true"></span>
            </button>
            <button type="submit" class="btn btn-primary smartsearch-submit">{{ __('Search') }}</button>
        </div>
    </form>
</div>

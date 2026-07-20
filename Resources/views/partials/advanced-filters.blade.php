@php
    $adv = (array)($advanced ?? []);
    $dateField = (string)($adv['date_field'] ?? 'updated');
    $dateFrom = (string)($adv['date_from'] ?? '');
    $dateTo = (string)($adv['date_to'] ?? '');
    $tagIds = array_map('intval', (array)($adv['tag_ids'] ?? []));
    $customerQ = (string)($adv['customer_q'] ?? '');
    $hasAttachments = $adv['has_attachments'] ?? '';
    $waitingReply = (string)($adv['waiting_reply'] ?? '');
    $searchIn = (array)($adv['search_in'] ?? []);
    if (!count($searchIn)) {
        $searchIn = ['subject', 'messages', 'notes'];
    }
    $tagsList = (array)($tags ?? []);
    $tagsOk = !empty($tagsOk);
@endphp

<div class="smartsearch-advanced">
    <div class="smartsearch-advanced-title">{{ __('Advanced filters') }}</div>
    <div class="smartsearch-advanced-grid">
        <div class="smartsearch-advanced-item">
            <label class="smartsearch-query-label">{{ __('Date range') }}</label>
            <div class="smartsearch-date-row">
                <select class="form-control input-sm" name="date_field" title="{{ __('Date basis') }}">
                    <option value="updated" @if($dateField === 'updated') selected @endif>{{ __('Last updated') }}</option>
                    <option value="created" @if($dateField === 'created') selected @endif>{{ __('Created date') }}</option>
                </select>
                <input type="date" class="form-control input-sm" name="date_from" value="{{ $dateFrom }}" title="{{ __('From') }}">
                <span class="smartsearch-date-sep">–</span>
                <input type="date" class="form-control input-sm" name="date_to" value="{{ $dateTo }}" title="{{ __('To') }}">
            </div>
        </div>

        <div class="smartsearch-advanced-item">
            <label class="smartsearch-query-label">{{ __('Customer name or email') }}</label>
            <input
                type="search"
                class="form-control input-sm"
                name="customer_q"
                value="{{ $customerQ }}"
                placeholder="{{ __('Name or email…') }}"
            >
        </div>

        <div class="smartsearch-advanced-item">
            <label class="smartsearch-query-label">{{ __('Attachments') }}</label>
            <select class="form-control input-sm" name="has_attachments">
                <option value="" @if($hasAttachments === '' || $hasAttachments === null) selected @endif>{{ __('Any') }}</option>
                <option value="1" @if((string)$hasAttachments === '1') selected @endif>{{ __('With attachments only') }}</option>
                <option value="0" @if((string)$hasAttachments === '0') selected @endif>{{ __('Without attachments') }}</option>
            </select>
        </div>

        <div class="smartsearch-advanced-item">
            <label class="smartsearch-query-label">{{ __('Last reply') }}</label>
            <select class="form-control input-sm" name="waiting_reply">
                <option value="" @if($waitingReply === '' || $waitingReply === null) selected @endif>{{ __('Any') }}</option>
                <option value="customer" @if($waitingReply === 'customer') selected @endif>{{ __('Waiting for reply (last from customer)') }}</option>
                <option value="user" @if($waitingReply === 'user') selected @endif>{{ __('Last reply from agent') }}</option>
            </select>
        </div>

        <div class="smartsearch-advanced-item smartsearch-advanced-wide">
            <label class="smartsearch-query-label">{{ __('Search only in') }}</label>
            <div class="smartsearch-check-list smartsearch-tags-checks">
                <label class="checkbox">
                    <input type="checkbox" name="search_in[]" value="subject" @if(in_array('subject', $searchIn, true)) checked @endif>
                    {{ __('Subject') }}
                </label>
                <label class="checkbox">
                    <input type="checkbox" name="search_in[]" value="messages" @if(in_array('messages', $searchIn, true)) checked @endif>
                    {{ __('Messages') }}
                </label>
                <label class="checkbox">
                    <input type="checkbox" name="search_in[]" value="notes" @if(in_array('notes', $searchIn, true)) checked @endif>
                    {{ __('Notes') }}
                </label>
            </div>
            <p class="help-block">{{ __('Limits the main query. All three selected = full search including customers and custom fields.') }}</p>
        </div>

        @if ($tagsOk)
            <div class="smartsearch-advanced-item smartsearch-advanced-wide">
                <label class="smartsearch-query-label">{{ __('Tags') }}</label>
                <div class="smartsearch-check-list smartsearch-tags-checks">
                    @forelse ($tagsList as $tag)
                        <label class="checkbox">
                            <input type="checkbox" name="tag_ids[]" value="{{ $tag['id'] }}" @if(in_array((int)$tag['id'], $tagIds, true)) checked @endif>
                            {{ $tag['name'] }}
                        </label>
                    @empty
                        <span class="text-muted">{{ __('No tags available.') }}</span>
                    @endforelse
                </div>
                <p class="help-block">{{ __('Multiple tags use OR (conversation has at least one).') }}</p>
            </div>
        @endif
    </div>
</div>

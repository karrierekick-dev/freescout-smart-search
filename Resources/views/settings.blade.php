<form class="form-horizontal margin-top margin-bottom" method="POST" action="">
    {{ csrf_field() }}

    <div class="descr-block">
        <p>{{ __('These defaults apply when Smart Search opens (navbar link or empty form). Submitting the search form overrides them for that request.') }}</p>
    </div>

    <div class="form-group">
        <label for="default_statuses" class="col-sm-2 control-label">{{ __('Default statuses') }}</label>
        <div class="col-sm-6">
            @php
                $selectedStatuses = array_map('intval', (array) old(
                    'settings[smartsearch.default_statuses]',
                    $settings['smartsearch.default_statuses'] ?? []
                ));
            @endphp
            <select
                id="default_statuses"
                class="form-control"
                name="settings[smartsearch.default_statuses][]"
                multiple
                size="6"
            >
                @foreach (($statusOptions ?? []) as $code => $label)
                    <option value="{{ $code }}" @if (in_array((int)$code, $selectedStatuses, true)) selected @endif>{{ $label }}</option>
                @endforeach
            </select>
            <p class="form-help">{{ __('Hold Ctrl/Cmd to select multiple (OR). Leave empty for any status. Recommended: Active + Pending.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label for="default_mailbox_id" class="col-sm-2 control-label">{{ __('Default mailbox') }}</label>
        <div class="col-sm-6">
            <select id="default_mailbox_id" class="form-control" name="settings[smartsearch.default_mailbox_id]">
                <option value="0" @if ((int) old('settings[smartsearch.default_mailbox_id]', $settings['smartsearch.default_mailbox_id'] ?? 0) === 0) selected @endif>
                    {{ __('All accessible mailboxes') }}
                </option>
                @foreach (($mailboxes ?? []) as $mb)
                    <option
                        value="{{ $mb->id }}"
                        @if ((int) old('settings[smartsearch.default_mailbox_id]', $settings['smartsearch.default_mailbox_id'] ?? 0) === (int)$mb->id) selected @endif
                    >{{ $mb->name }}</option>
                @endforeach
            </select>
            <p class="form-help">{{ __('Applied only if the user can access this mailbox; otherwise all accessible mailboxes are used.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label for="search_thread_body" class="col-sm-2 control-label">{{ __('Search message bodies') }}</label>
        <div class="col-sm-6">
            <div class="controls">
                <div class="onoffswitch-wrap">
                    <div class="onoffswitch">
                        <input
                            type="checkbox"
                            name="settings[smartsearch.search_thread_body]"
                            value="1"
                            id="search_thread_body"
                            class="onoffswitch-checkbox"
                            @if (old('settings[smartsearch.search_thread_body]', $settings['smartsearch.search_thread_body'] ?? true)) checked="checked" @endif
                        >
                        <label class="onoffswitch-label" for="search_thread_body"></label>
                    </div>
                </div>
            </div>
            <p class="form-help">{{ __('Also search customer messages, agent replies, and internal notes. Disabling this makes search faster.') }}</p>
        </div>
    </div>

    <div class="form-group margin-top">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Save Options') }}</button>
        </div>
    </div>
</form>

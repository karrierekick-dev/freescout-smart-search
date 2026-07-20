<?php

namespace Modules\SmartSearch\Http\Controllers;

use App\Conversation;
use App\Folder;
use App\Http\Controllers\Controller;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class SmartSearchController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string)$request->get('q', ''));
        $q = $this->sanitizeSearchString($q);
        $page = max(1, (int)$request->get('page', 1));
        $perPage = (int)config('smartsearch.per_page', 50);
        if ($perPage < 10) {
            $perPage = 10;
        }
        if ($perPage > 200) {
            $perPage = 200;
        }

        $mailboxId = (int)$request->get('mailbox_id', 0);
        $folderId = (int)$request->get('folder_id', 0);
        $assigneeId = (int)$request->get('assignee_id', 0);
        $formSubmitted = $request->has('ss');

        $user = Auth::user();
        $mailboxes = $user ? $user->mailboxesCanView(true) : collect();
        $allowedMailboxIds = [];
        foreach ($mailboxes as $mailbox) {
            $allowedMailboxIds[] = (int)$mailbox->id;
        }
        $allowedMailboxIds = array_values(array_unique($allowedMailboxIds));
        $assignedOnlyUserId = $this->getAssignedOnlyUserId($user);

        // Apply module defaults on first open / deep links without form marker.
        if (!$formSubmitted && !$request->has('mailbox_id')) {
            $mailboxId = $this->getDefaultMailboxIdSetting();
        }

        // If user selected a mailbox they cannot access, ignore it.
        if ($mailboxId && !in_array($mailboxId, $allowedMailboxIds, true)) {
            $mailboxId = 0;
        }

        // Multi-status filter (OR). Empty selection = any status (no filter).
        $statusOptions = $this->statusOptions();
        if ($formSubmitted || $request->has('status')) {
            $statuses = $this->parseStatusFilter($request);
        } else {
            $statuses = $this->getDefaultStatusesSetting($statusOptions);
        }

        // Folder list is optional (depends on schema).
        $folders = [];
        $foldersOk = $this->foldersOk();
        if ($foldersOk) {
            $folders = $this->loadFolders($allowedMailboxIds, $mailboxId);
            if ($folderId) {
                $isValidFolder = false;
                foreach ($folders as $f) {
                    if ((int)($f['id'] ?? 0) === $folderId) {
                        $isValidFolder = true;
                        break;
                    }
                }
                if (!$isValidFolder) {
                    $folderId = 0;
                }
            }
        } else {
            $folderId = 0;
        }

        $assignees = $this->loadAssignees($allowedMailboxIds, $mailboxId, $assignedOnlyUserId);
        if (!$this->isValidAssigneeFilter($assigneeId, $assignees)) {
            $assigneeId = 0;
        }

        $schemaOk = $this->schemaOk();
        $cfModuleOk = $this->customFieldsModuleActive();
        $cfOk = $cfModuleOk && $this->customFieldsOk();
        $cfDefsOk = $cfModuleOk && $this->customFieldDefinitionsOk();
        $error = $schemaOk ? null : __('The FreeScout conversations table is unavailable.');

        $fields = [];
        $fieldsById = [];
        if ($cfDefsOk) {
            $fields = $this->loadFields($allowedMailboxIds, $mailboxId);
            foreach ($fields as $f) {
                $fieldsById[(int)$f['id']] = $f;
            }
        }

        $cfFilters = $this->parseCfFilters($request, $fieldsById, $cfOk && $cfDefsOk);
        // Resolve meta for filters not in current mailbox field list.
        foreach ($cfFilters as $i => $filter) {
            if (empty($filter['meta'])) {
                $meta = $this->loadFieldById($allowedMailboxIds, (int)$filter['id']);
                if ($meta) {
                    $cfFilters[$i]['meta'] = $meta;
                    $fieldsById[(int)$filter['id']] = $meta;
                }
            }
        }
        $fieldId = count($cfFilters) ? (int)$cfFilters[0]['id'] : 0;
        $fieldValues = count($cfFilters) ? (array)$cfFilters[0]['values'] : [];
        $selectedField = count($cfFilters) ? ($cfFilters[0]['meta'] ?? null) : null;

        $allowedCfSortIds = [];
        foreach ($cfFilters as $filter) {
            $cid = (int)($filter['id'] ?? 0);
            if ($cid > 0) {
                $allowedCfSortIds[] = $cid;
            }
        }
        $sortInfo = $this->resolveSort((string)$request->get('sort', 'updated_desc'), $allowedCfSortIds);
        $sort = (string)$sortInfo['key'];

        $tagsOk = $this->tagsOk();
        $tags = $tagsOk ? $this->loadTags() : [];
        $advanced = $this->parseAdvancedFilters($request, $tagsOk ? $tags : []);

        $results = [];
        $total = 0;
        // Idle: bare page open (navbar "more", no ss/q) — form only, no DB listing.
        // Search/recent only when form was submitted (ss=1) or an explicit q is present.
        $mode = 'idle';

        // Native behavior: if user entered a conversation ID (or thread ID), jump directly to the conversation.
        // FreeScout core routes by conversations.id.
        if ($schemaOk && !count($cfFilters) && $q !== '' && mb_strlen($q) >= (int)config('smartsearch.min_query_len', 2)) {
            $numericTicket = $this->normalizeTicketNumberExact($q);
            if ($numericTicket !== null && $this->isStrictNumericQuery($q)) {
                $ids = $allowedMailboxIds;
                if ($mailboxId) {
                    $ids = [$mailboxId];
                }
                if (count($ids)) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $resolved = $this->resolveNumericConversation(
                        $numericTicket,
                        $ids,
                        $in,
                        $statuses,
                        $folderId,
                        $assigneeId,
                        $assignedOnlyUserId,
                        $this->isDisplayNumberQuery($q)
                    );
                    if ($resolved !== null) {
                        return redirect()->route('conversations.view', ['id' => (int)$resolved['id']]);
                    }
                }
            }
        }

        if ($schemaOk && $q !== '' && mb_strlen($q) >= (int)config('smartsearch.min_query_len', 2)) {
            [$results, $total] = $this->search(
                $q,
                $allowedMailboxIds,
                $mailboxId,
                $cfFilters,
                $statuses,
                $folderId,
                $assigneeId,
                $assignedOnlyUserId,
                $page,
                $perPage,
                $sort,
                $advanced
            );
            $mode = 'search';
        } elseif ($schemaOk && $formSubmitted && $q === '') {
            // Form submit without query: show newest conversations (filters still apply).
            [$results, $total] = $this->recent(
                $allowedMailboxIds,
                $mailboxId,
                $statuses,
                $folderId,
                $assigneeId,
                $assignedOnlyUserId,
                $page,
                $perPage,
                $sort,
                $cfFilters,
                $advanced
            );
            $mode = 'recent';
        }

        // If a specific custom field is selected, always hydrate its value column
        // for the current results page (including "recent" mode).
        if (count($cfFilters) && $cfOk && count($results)) {
            $results = $this->appendMultipleCustomFieldValues($results, $cfFilters);
        }

        if (count($results)) {
            $results = $this->appendStatusMeta($results);
        }

        return view('smartsearch::search', [
            'q' => $q,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'results' => $results,
            'mode' => $mode,
            'mailboxId' => $mailboxId,
            'fieldId' => $fieldId,
            'fieldValues' => $fieldValues,
            'cfFilters' => $cfFilters,
            'statuses' => $statuses,
            'statusOptions' => $statusOptions,
            'folderId' => $folderId,
            'folders' => $folders,
            'foldersOk' => $foldersOk,
            'assigneeId' => $assigneeId,
            'assignees' => $assignees,
            'bulkAssignableUsers' => $this->loadAssignableUsers($allowedMailboxIds, $mailboxId),
            'bulkStatusOptions' => $this->writableStatusOptions(),
            'sort' => $sort,
            'fields' => $fields,
            'selectedField' => $selectedField,
            'advanced' => $advanced,
            'tags' => $tags,
            'tagsOk' => $tagsOk,
            'cfModuleOk' => $cfModuleOk,
            'allowedMailboxIds' => $allowedMailboxIds,
            'mailboxes' => $mailboxes,
            'error' => $error,
        ]);
    }

    /**
     * Default listing when query is empty: newest conversations the user can access.
     */
    protected function recent(array $allowedMailboxIds, int $mailboxId, array $statuses, int $folderId, int $assigneeId, int $assignedOnlyUserId, int $page, int $perPage, string $sort = 'updated_desc', array $cfFilters = [], array $advanced = [])
    {
        if (!count($allowedMailboxIds)) {
            return [[], 0];
        }

        $ids = $allowedMailboxIds;
        if ($mailboxId) {
            $ids = [$mailboxId];
        }
        if (!count($ids)) {
            return [[], 0];
        }

        $offset = ($page - 1) * $perPage;
        $in = implode(',', array_fill(0, count($ids), '?'));

        $where = "c.mailbox_id IN ($in)";
        $bWhere = $ids;
        $statusFilter = $this->buildStatusFilterSql($statuses);
        if ($statusFilter['sql'] !== '') {
            $where .= $statusFilter['sql'];
            $bWhere = array_merge($bWhere, $statusFilter['bindings']);
        }
        if ($folderId) {
            $where .= " AND c.folder_id = ?";
            $bWhere[] = $folderId;
        }
        if ($assigneeId === -1) {
            $where .= " AND (c.user_id IS NULL OR c.user_id <= 0)";
        } elseif ($assigneeId > 0) {
            $where .= " AND c.user_id = ?";
            $bWhere[] = $assigneeId;
        }

        $visibility = $this->buildAssignedVisibilitySql($assignedOnlyUserId, 'c');
        $where .= (string)$visibility['sql'];
        $bWhere = array_merge($bWhere, (array)$visibility['bindings']);

        if (count($cfFilters) && $this->customFieldsOk()) {
            $cfFilter = $this->buildMultiCustomFieldFiltersSql($cfFilters, '', 'LIKE');
            if ($cfFilter['sql'] !== '') {
                $where .= $cfFilter['sql'];
                $bWhere = array_merge($bWhere, $cfFilter['bindings']);
            }
        }

        $advancedFilter = $this->buildAdvancedFilterSql($advanced);
        $where .= (string)$advancedFilter['sql'];
        $bWhere = array_merge($bWhere, (array)$advancedFilter['bindings']);
        $fromSql = 'conversations c';
        if (!empty($advancedFilter['needs_customer_join'])) {
            $fromSql .= ' LEFT JOIN customers cu ON cu.id = c.customer_id';
        }

        $allowedCfSortIds = [];
        foreach ($cfFilters as $filter) {
            $cid = (int)($filter['id'] ?? 0);
            if ($cid > 0) {
                $allowedCfSortIds[] = $cid;
            }
        }
        $sortInfo = $this->resolveSort($sort, $allowedCfSortIds);
        $fromSql .= (string)$sortInfo['join_sql'];
        $orderBy = (string)$sortInfo['order_by'];
        if (count($sortInfo['bindings'])) {
            // JOIN bindings appear before WHERE bindings in the SQL string.
            $bWhere = array_merge((array)$sortInfo['bindings'], $bWhere);
        }

        $total = 0;
        try {
            $row = DB::selectOne("SELECT COUNT(DISTINCT c.id) as cnt FROM $fromSql WHERE $where", $bWhere);
            $total = $row ? (int)$row->cnt : 0;
        } catch (\Throwable $e) {
            $total = 0;
        }

        $rows = [];
        try {
            $rows = DB::select(
                "SELECT DISTINCT c.id, c.subject, c.status, c.state, c.updated_at, c.mailbox_id
                 FROM $fromSql
                 WHERE $where
                 ORDER BY $orderBy
                 LIMIT ? OFFSET ?",
                array_merge($bWhere, [$perPage, $offset])
            );
        } catch (\Throwable $e) {
            $rows = [];
        }

        $results = [];
        foreach ($rows as $r) {
            $results[] = [
                'id' => (int)$r->id,
                'subject' => (string)$r->subject,
                'status' => (int)$r->status,
                'state' => isset($r->state) ? (int)$r->state : 0,
                'updated_at' => (string)$r->updated_at,
                'mailbox_id' => (int)$r->mailbox_id,
            ];
        }

        return [$results, $total];
    }

    /**
     * Lightweight autosuggest endpoint for the topbar input.
     * Returns a small list of matches the user is allowed to view.
     */
    public function suggest(Request $request)
    {
        try {
            $q = trim((string)$request->get('q', ''));
            $q = $this->sanitizeSearchString($q);
            $minLen = (int)config('smartsearch.min_query_len', 2);
            if ($q === '' || mb_strlen($q) < $minLen) {
                return response()->json(['items' => []]);
            }

            $user = Auth::user();
            $allowedMailboxIds = $user ? $user->mailboxesIdsCanView() : [];
            $assignedOnlyUserId = $this->getAssignedOnlyUserId($user);
            if (!count($allowedMailboxIds)) {
                return response()->json(['items' => []]);
            }

            $mailboxId = (int)$request->get('mailbox_id', 0);
            if ($mailboxId && !in_array($mailboxId, array_map('intval', $allowedMailboxIds), true)) {
                $mailboxId = 0;
            }

            $statuses = $this->parseStatusFilter($request);
            if (!$request->has('ss') && !$request->has('status')) {
                $statuses = $this->getDefaultStatusesSetting($this->statusOptions());
            }

            $folderId = (int)$request->get('folder_id', 0);
            if (!$this->foldersOk()) {
                $folderId = 0;
            }

            $cfOkSuggest = $this->customFieldsOk();
            $fieldsById = [];
            if ($cfOkSuggest && $this->customFieldDefinitionsOk()) {
                foreach ($this->loadFields($allowedMailboxIds, $mailboxId) as $f) {
                    $fieldsById[(int)$f['id']] = $f;
                }
            }
            $cfFilters = $this->parseCfFilters($request, $fieldsById, $cfOkSuggest);

            if (!$this->schemaOk()) {
                return response()->json(['items' => []]);
            }

            // Keep it intentionally small and fast.
            $perPage = 8;
            // Always suggest newest first (deterministic UX).
            [$results, $total] = $this->search(
                $q,
                $allowedMailboxIds,
                $mailboxId,
                $cfFilters,
                $statuses,
                $folderId,
                0,
                $assignedOnlyUserId,
                1,
                $perPage,
                'updated_desc'
            );

            // Optional: hydrate selected custom field value for suggestions.
            if (count($cfFilters) && $cfOkSuggest && count($results)) {
                $results = $this->appendMultipleCustomFieldValues($results, $cfFilters);
            }

            $items = $this->buildConversationMetaItems($results);

            return response()->json([
                'items' => $items,
                'total' => (int)$total,
            ]);
        } catch (\Throwable $e) {
            // Never break the navbar.
            return response()->json(['items' => []]);
        }
    }


    /**
     * Refresh conversation metadata used by recent-search shortcuts in the navbar.
     * This keeps status badges and timestamps from drifting when a conversation
     * changes after it was first stored in localStorage.
     */
    public function recentMeta(Request $request)
    {
        try {
            $rawIds = (string)$request->get('ids', '');
            if ($rawIds === '') {
                return response()->json(['items' => []]);
            }

            $parts = preg_split('/[^0-9]+/', $rawIds);
            $ids = [];
            foreach ((array)$parts as $part) {
                $id = (int)$part;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            $ids = array_values($ids);
            if (!count($ids)) {
                return response()->json(['items' => []]);
            }
            if (count($ids) > 10) {
                $ids = array_slice($ids, 0, 10);
            }

            $user = Auth::user();
            $allowedMailboxIds = $user ? $user->mailboxesIdsCanView() : [];
            if (!count($allowedMailboxIds) || !$this->schemaOk()) {
                return response()->json(['items' => []]);
            }

            $rows = Conversation::whereIn('id', $ids)
                ->whereIn('mailbox_id', $allowedMailboxIds)
                ->get(['id', 'subject', 'mailbox_id', 'status', 'state', 'updated_at']);

            if (!count($rows)) {
                return response()->json(['items' => []]);
            }

            $results = [];
            foreach ($rows as $conversation) {
                if (!$this->canViewConversation($conversation, $user)) {
                    continue;
                }

                $results[] = [
                    'id' => (int)$conversation->id,
                    'subject' => (string)$conversation->subject,
                    'mailbox_id' => (int)$conversation->mailbox_id,
                    'status' => (int)$conversation->status,
                    'state' => isset($conversation->state) ? (int)$conversation->state : 0,
                    'updated_at' => (string)$conversation->updated_at,
                ];
            }

            return response()->json([
                'items' => $this->buildConversationMetaItems($results),
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
              ->header('Pragma', 'no-cache')
              ->header('Expires', '0');
        } catch (\Throwable $e) {
            return response()->json(['items' => []]);
        }
    }

    protected function buildConversationMetaItems(array $results)
    {
        if (!count($results)) {
            return [];
        }

        $mailboxNames = [];
        try {
            $mbIds = [];
            foreach ($results as $r) {
                $mid = (int)($r['mailbox_id'] ?? 0);
                if ($mid) {
                    $mbIds[$mid] = true;
                }
            }
            $mbIds = array_keys($mbIds);
            if (count($mbIds)) {
                $in = implode(',', array_fill(0, count($mbIds), '?'));
                $rows = DB::select("SELECT id, name FROM mailboxes WHERE id IN ($in)", $mbIds);
                foreach ($rows as $mb) {
                    $mailboxNames[(int)$mb->id] = (string)$mb->name;
                }
            }
        } catch (\Throwable $e) {
            $mailboxNames = [];
        }

        $items = [];
        foreach ($results as $r) {
            $id = (int)($r['id'] ?? 0);
            if (!$id) {
                continue;
            }

            $status = (int)($r['status'] ?? 0);
            $state = (int)($r['state'] ?? 0);
            $mailboxId = (int)($r['mailbox_id'] ?? 0);
            $updatedAt = (string)($r['updated_at'] ?? '');

            $updatedHuman = '';
            try {
                if ($updatedAt) {
                    $updatedHuman = Carbon::parse($updatedAt)->diffForHumans();
                }
            } catch (\Throwable $e) {
                $updatedHuman = '';
            }

            $badge = $this->getConversationBadgeMeta($status, $state);
            $statusName = (string)($badge['name'] ?? '');
            $statusClass = (string)($badge['class'] ?? 'default');

            $items[] = [
                'id' => $id,
                'subject' => (string)($r['subject'] ?? ''),
                'url' => route('conversations.view', ['id' => $id]),
                'mailbox_id' => $mailboxId,
                'mailbox_name' => (string)($mailboxNames[$mailboxId] ?? ''),
                'status' => $status,
                'state' => $state,
                'status_name' => $statusName,
                'status_class' => $statusClass,
                'updated_at' => $updatedAt,
                'updated_human' => $updatedHuman,
                'field_value' => (string)($r['field_value'] ?? ''),
            ];
        }

        return $items;
    }



    /**
     * Return available custom fields for a selected mailbox.
     * Used by the Smart Search form before submitting the search.
     */
    public function fields(Request $request)
    {
        try {
            $user = Auth::user();
            $allowedMailboxIds = $user ? $user->mailboxesIdsCanView() : [];
            if (!count($allowedMailboxIds)) {
                return response()->json(['status' => 'success', 'fields' => []]);
            }

            $mailboxId = (int)$request->get('mailbox_id', 0);
            if ($mailboxId && !in_array($mailboxId, array_map('intval', $allowedMailboxIds), true)) {
                $mailboxId = 0;
            }

            if (!$this->customFieldDefinitionsOk()) {
                return response()->json(['status' => 'success', 'fields' => []]);
            }

            $fields = $this->loadFields($allowedMailboxIds, $mailboxId);

            return response()->json([
                'status' => 'success',
                'fields' => $fields,
            ]);
        } catch (\Throwable $e) {
            try {
                \Helper::logException($e);
            } catch (\Throwable $ignored) {}

            return response()->json([
                'status' => 'error',
                'fields' => [],
            ]);
        }
    }


    public function bulk(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return redirect()->route('smartsearch.search');
            }

            $data = $request->only([
                'bulk_action',
                'bulk_assignee_id',
                'bulk_status',
                'bulk_note',
                'conversation_ids',
            ]);

            $ids = $this->normalizeBulkConversationIds($data['conversation_ids'] ?? []);
            if (!count($ids)) {
                \Session::flash('flash_error_floating', __('Select at least one conversation.'));
                return redirect()->back();
            }

            $action = trim((string)($data['bulk_action'] ?? ''));
            if (!in_array($action, ['assign', 'status', 'note'], true)) {
                \Session::flash('flash_error_floating', __('Choose a bulk action.'));
                return redirect()->back();
            }

            $allowedMailboxIds = $user->mailboxesIdsCanView();
            if (!count($allowedMailboxIds)) {
                \Session::flash('flash_error_floating', __('Not enough permissions'));
                return redirect()->back();
            }

            if (!$this->validateBulkActionPayload($action, $data, $allowedMailboxIds)) {
                return redirect()->back();
            }

            $conversations = Conversation::with('mailbox')
                ->whereIn('id', $ids)
                ->whereIn('mailbox_id', $allowedMailboxIds)
                ->get();

            $updated = 0;
            $skipped = max(0, count($ids) - count($conversations));
            $mailboxIdsToRefresh = [];

            foreach ($conversations as $conversation) {
                try {
                    if (!$this->canBulkTouchConversation($conversation, $user)) {
                        ++$skipped;
                        continue;
                    }

                    if ($this->isDeletedConversationState((int)($conversation->state ?? 0))) {
                        ++$skipped;
                        continue;
                    }

                    $changed = false;
                    if ($action === 'assign') {
                        $changed = $this->applyBulkAssignment($conversation, $user, (int)($data['bulk_assignee_id'] ?? 0));
                    } elseif ($action === 'status') {
                        $changed = $this->applyBulkStatus($conversation, $user, (int)($data['bulk_status'] ?? 0));
                    } elseif ($action === 'note') {
                        $changed = $this->applyBulkNote($conversation, $user, (string)($data['bulk_note'] ?? ''), $request);
                    }

                    if ($changed) {
                        ++$updated;
                        $mailboxIdsToRefresh[(int)$conversation->mailbox_id] = (int)$conversation->mailbox_id;
                    } else {
                        ++$skipped;
                    }
                } catch (\Throwable $e) {
                    ++$skipped;
                    try {
                        \Helper::logException($e);
                    } catch (\Throwable $ignored) {}
                }
            }

            $this->refreshMailboxCounters(array_values($mailboxIdsToRefresh));

            if ($updated > 0) {
                \Session::flash('flash_success_floating', __('Bulk action completed: :updated updated, :skipped skipped.', [
                    'updated' => $updated,
                    'skipped' => $skipped,
                ]));
            } else {
                \Session::flash('flash_error_floating', __('No conversations were updated.'));
            }
        } catch (\Throwable $e) {
            try {
                \Helper::logException($e);
            } catch (\Throwable $ignored) {}
            \Session::flash('flash_error_floating', __('Bulk action failed.'));
        }

        return redirect()->back();
    }

    protected function normalizeBulkConversationIds($raw)
    {
        $ids = [];
        if (!is_array($raw)) {
            $raw = preg_split('/[^0-9]+/', (string)$raw);
        }

        foreach ((array)$raw as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        $ids = array_values($ids);
        $limit = (int)config('smartsearch.bulk_max_selected', 200);
        $limit = max(1, min(500, $limit));
        if (count($ids) > $limit) {
            $ids = array_slice($ids, 0, $limit);
        }

        return $ids;
    }

    protected function validateBulkActionPayload(string $action, array $data, array $allowedMailboxIds)
    {
        if ($action === 'assign') {
            $assigneeId = (int)($data['bulk_assignee_id'] ?? 0);
            if ($assigneeId === 0) {
                \Session::flash('flash_error_floating', __('Choose an assignee.'));
                return false;
            }

            $assignableIds = [-1 => -1];
            foreach ($this->loadAssignableUsers($allowedMailboxIds, 0) as $row) {
                $assignableIds[(int)$row['id']] = (int)$row['id'];
            }

            if (!isset($assignableIds[$assigneeId])) {
                \Session::flash('flash_error_floating', __('Choose an assignee.'));
                return false;
            }
        } elseif ($action === 'status') {
            $status = (int)($data['bulk_status'] ?? 0);
            if (!array_key_exists($status, $this->writableStatusOptions())) {
                \Session::flash('flash_error_floating', __('Choose a status.'));
                return false;
            }
        } elseif ($action === 'note') {
            $noteText = trim((string)($data['bulk_note'] ?? ''));
            if ($noteText === '' || trim(strip_tags($noteText)) === '') {
                \Session::flash('flash_error_floating', __('Enter an internal note.'));
                return false;
            }

            $maxLength = (int)config('smartsearch.bulk_note_max_length', 50000);
            $maxLength = max(100, min(100000, $maxLength));
            if (mb_strlen($noteText) > $maxLength) {
                \Session::flash('flash_error_floating', __('The internal note must not exceed :max characters.', [
                    'max' => $maxLength,
                ]));
                return false;
            }
        }

        return true;
    }

    protected function canBulkTouchConversation($conversation, $user)
    {
        if (!$conversation || !$user) {
            return false;
        }

        try {
            if (!$user->can('update', $conversation)) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    protected function applyBulkAssignment($conversation, $user, int $newUserId)
    {
        if ($newUserId === 0) {
            return false;
        }

        if ($newUserId !== -1 && !$this->isUserAssignableToMailbox($conversation->mailbox, $newUserId)) {
            return false;
        }

        $currentUserId = (int)($conversation->user_id ?? 0);
        if ($newUserId === -1) {
            if ($currentUserId <= 0) {
                return false;
            }
        } elseif ($currentUserId === $newUserId) {
            return false;
        }

        $conversation->changeUser($newUserId, $user);
        return true;
    }

    protected function isUserAssignableToMailbox($mailbox, int $userId)
    {
        if (!$mailbox || $userId <= 0) {
            return false;
        }

        try {
            // Prefer the same extension surface used by FreeScout's assignee UI.
            // Teams and other assignment modules can extend this collection.
            if (method_exists($mailbox, 'usersAssignable')) {
                foreach ($mailbox->usersAssignable(true) as $assignableUser) {
                    if ((int)($assignableUser->id ?? 0) === $userId) {
                        return true;
                    }
                }
                return false;
            }

            if (method_exists($mailbox, 'userHasAccess')) {
                return (bool)$mailbox->userHasAccess($userId);
            }

            if (method_exists($mailbox, 'userIdsHavingAccess')) {
                $ids = array_map('intval', (array)$mailbox->userIdsHavingAccess());
                return in_array($userId, $ids, true);
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    protected function applyBulkStatus($conversation, $user, int $newStatus)
    {
        if (!array_key_exists($newStatus, $this->writableStatusOptions())) {
            return false;
        }

        if ((int)($conversation->status ?? 0) === $newStatus) {
            return false;
        }

        $conversation->changeStatus($newStatus, $user);
        return true;
    }

    protected function applyBulkNote($conversation, $user, string $noteText, Request $request)
    {
        $noteText = trim($noteText);
        if ($noteText === '' || trim(strip_tags($noteText)) === '') {
            return false;
        }

        $body = e(str_replace(["\r\n", "\r"], "\n", $noteText));
        $body = nl2br($body);

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->user_id = $user->id;
        $thread->type = Thread::TYPE_NOTE;
        $thread->status = $conversation->status;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->customer_id = $conversation->customer_id;
        $thread->created_by_user_id = $user->id;
        $thread->body = $body;

        $hookRequest = clone $request;
        $hookRequest->merge([
            'body' => $body,
            'is_note' => 1,
            'conversation_id' => (int)$conversation->id,
        ]);

        \Eventy::action('thread.before_save_from_request', $thread, $hookRequest);
        $thread->save();

        if (class_exists('App\\Events\\UserAddedNote')) {
            event(new \App\Events\UserAddedNote($conversation, $thread));
        }
        \Eventy::action('conversation.note_added', $conversation, $thread);

        return true;
    }

    protected function refreshMailboxCounters(array $mailboxIds)
    {
        $mailboxIds = array_values(array_unique(array_filter(array_map('intval', $mailboxIds))));
        if (!count($mailboxIds)) {
            return;
        }

        try {
            $mailboxes = Mailbox::whereIn('id', $mailboxIds)->get();
            foreach ($mailboxes as $mailbox) {
                try {
                    if (method_exists($mailbox, 'updateFoldersCounters')) {
                        $mailbox->updateFoldersCounters();
                    }
                } catch (\Throwable $e) {
                    try {
                        \Helper::logException($e);
                    } catch (\Throwable $ignored) {}
                }
            }
        } catch (\Throwable $e) {
            try {
                \Helper::logException($e);
            } catch (\Throwable $ignored) {}
        }
    }

    protected function sanitizeSearchString($value)
    {
        $value = (string)$value;

        try {
            if (class_exists('Helper') && method_exists('Helper', 'sqlSanitizeString')) {
                $value = \Helper::sqlSanitizeString($value);
            } else {
                $value = str_replace("\0", '', $value);
            }
        } catch (\Throwable $e) {
            $value = str_replace("\0", '', $value);
        }

        return trim($value);
    }

    protected function rememberSchemaCheck($key, $callback)
    {
        $cacheMinutes = (int)config('smartsearch.schema_cache_minutes', 10);
        $cacheMinutes = max(1, min(1440, $cacheMinutes));

        return (bool)Cache::remember(
            'smart_search.'.$key,
            Carbon::now()->addMinutes($cacheMinutes),
            $callback
        );
    }

    protected function threadsTableOk()
    {
        return $this->rememberSchemaCheck('threads_table_ok', function () {
            try {
                return Schema::hasTable('threads')
                    && Schema::hasColumn('threads', 'id')
                    && Schema::hasColumn('threads', 'conversation_id');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function threadsSearchOk()
    {
        return $this->rememberSchemaCheck('threads_search_ok', function () {
            try {
                return $this->threadsTableOk()
                    && Schema::hasColumn('threads', 'type')
                    && Schema::hasColumn('threads', 'state')
                    && Schema::hasColumn('threads', 'body');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function conversationCreatorColumnOk()
    {
        return $this->rememberSchemaCheck('conversation_creator_column_ok', function () {
            try {
                return Schema::hasTable('conversations')
                    && Schema::hasColumn('conversations', 'created_by_user_id');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function customFieldDefinitionsOk()
    {
        return $this->rememberSchemaCheck('custom_field_defs_ok', function () {
            try {
                return Schema::hasTable('custom_fields')
                    && Schema::hasColumn('custom_fields', 'id')
                    && Schema::hasColumn('custom_fields', 'name')
                    && Schema::hasColumn('custom_fields', 'mailbox_id')
                    && Schema::hasColumn('custom_fields', 'type')
                    && Schema::hasColumn('custom_fields', 'options');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function customFieldsOk()
    {
        return $this->rememberSchemaCheck('custom_fields_ok', function () {
            try {
                return $this->customFieldDefinitionsOk()
                    && Schema::hasTable('conversation_custom_field')
                    && Schema::hasColumn('conversation_custom_field', 'conversation_id')
                    && Schema::hasColumn('conversation_custom_field', 'custom_field_id')
                    && Schema::hasColumn('conversation_custom_field', 'value');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    /**
     * True when the Custom Fields module is installed/active and schema is usable.
     */
    protected function customFieldsModuleActive()
    {
        try {
            if (class_exists('\Module') && method_exists('\Module', 'isActive')) {
                // FreeScout Module::isActive() expects the module alias (lowercase).
                $active = \Module::isActive('customfields')
                    || \Module::isActive('CustomFields')
                    || \Module::isActive('Custom Fields');
                if (!$active) {
                    return false;
                }
            } elseif (!class_exists('Modules\CustomFields\Entities\CustomField')) {
                return false;
            }
            return $this->customFieldsOk();
        } catch (\Throwable $e) {
            return $this->customFieldsOk();
        }
    }

    protected function schemaOk()
    {
        return $this->rememberSchemaCheck('schema_ok', function () {
            try {
                $requiredColumns = [
                    'id',
                    'subject',
                    'status',
                    'state',
                    'updated_at',
                    'mailbox_id',
                    'user_id',
                    'customer_id',
                    'preview',
                    'customer_email',
                ];

                if (!Schema::hasTable('conversations')) {
                    return false;
                }

                foreach ($requiredColumns as $column) {
                    if (!Schema::hasColumn('conversations', $column)) {
                        return false;
                    }
                }

                return Schema::hasTable('customers')
                    && Schema::hasColumn('customers', 'id')
                    && Schema::hasColumn('customers', 'first_name')
                    && Schema::hasColumn('customers', 'last_name')
                    && Schema::hasColumn('customers', 'phones');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }
    protected function foldersOk()
    {
        return $this->rememberSchemaCheck('folders_ok', function () {
            try {
                return Schema::hasTable('folders')
                    && Schema::hasColumn('folders', 'id')
                    && Schema::hasColumn('folders', 'mailbox_id')
                    && Schema::hasColumn('folders', 'type')
                    && Schema::hasColumn('folders', 'user_id')
                    && Schema::hasColumn('conversations', 'folder_id');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function tagsOk()
    {
        return $this->rememberSchemaCheck('tags_ok', function () {
            try {
                return Schema::hasTable('tags')
                    && Schema::hasColumn('tags', 'id')
                    && Schema::hasColumn('tags', 'name')
                    && Schema::hasTable('conversation_tag')
                    && Schema::hasColumn('conversation_tag', 'conversation_id')
                    && Schema::hasColumn('conversation_tag', 'tag_id');
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    /**
     * @return array<int, array{id:int,name:string}>
     */
    protected function loadTags()
    {
        if (!$this->tagsOk()) {
            return [];
        }
        try {
            $rows = DB::select('SELECT id, name FROM tags ORDER BY name ASC');
            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id' => (int)$row->id,
                    'name' => (string)$row->name,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<int, array{id:int,name:string}> $tags
     * @return array{
     *   date_field:string,
     *   date_from:string,
     *   date_to:string,
     *   tag_ids:int[],
     *   customer_q:string,
     *   has_attachments:?string,
     *   waiting_reply:string,
     *   search_in:string[]
     * }
     */
    protected function parseAdvancedFilters(Request $request, array $tags = [])
    {
        $dateField = strtolower(trim((string)$request->get('date_field', 'updated')));
        if (!in_array($dateField, ['updated', 'created'], true)) {
            $dateField = 'updated';
        }

        $dateFrom = $this->normalizeDateInput((string)$request->get('date_from', ''));
        $dateTo = $this->normalizeDateInput((string)$request->get('date_to', ''));

        $allowedTagIds = [];
        foreach ($tags as $tag) {
            $allowedTagIds[(int)$tag['id']] = true;
        }
        $tagIds = [];
        foreach ((array)$request->input('tag_ids', []) as $raw) {
            $id = (int)$raw;
            if ($id > 0 && isset($allowedTagIds[$id])) {
                $tagIds[] = $id;
            }
        }
        $tagIds = array_values(array_unique($tagIds));

        $customerQ = $this->sanitizeSearchString(trim((string)$request->get('customer_q', '')));

        $hasAttachments = $request->get('has_attachments', '');
        if ($hasAttachments === null || $hasAttachments === '') {
            $hasAttachments = '';
        } else {
            $hasAttachments = ((string)$hasAttachments === '1') ? '1' : (((string)$hasAttachments === '0') ? '0' : '');
        }
        if ($hasAttachments !== '' && !Schema::hasColumn('conversations', 'has_attachments')) {
            $hasAttachments = '';
        }

        $waitingReply = strtolower(trim((string)$request->get('waiting_reply', '')));
        if (!in_array($waitingReply, ['customer', 'user'], true)) {
            $waitingReply = '';
        }
        if ($waitingReply !== '' && !Schema::hasColumn('conversations', 'last_reply_from')) {
            $waitingReply = '';
        }

        $allowedScopes = ['subject' => true, 'messages' => true, 'notes' => true];
        $searchIn = [];
        if ($request->has('search_in')) {
            foreach ((array)$request->input('search_in', []) as $scope) {
                $scope = strtolower(trim((string)$scope));
                if (isset($allowedScopes[$scope])) {
                    $searchIn[] = $scope;
                }
            }
            $searchIn = array_values(array_unique($searchIn));
        }
        if (!count($searchIn)) {
            $searchIn = ['subject', 'messages', 'notes'];
        }

        return [
            'date_field' => $dateField,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'tag_ids' => $tagIds,
            'customer_q' => $customerQ,
            'has_attachments' => $hasAttachments,
            'waiting_reply' => $waitingReply,
            'search_in' => $searchIn,
        ];
    }

    protected function normalizeDateInput(string $value)
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }
        try {
            $dt = Carbon::createFromFormat('Y-m-d', $value);
            if (!$dt || $dt->format('Y-m-d') !== $value) {
                return '';
            }
            return $value;
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function advancedFiltersActive(array $advanced)
    {
        if (($advanced['date_from'] ?? '') !== '' || ($advanced['date_to'] ?? '') !== '') {
            return true;
        }
        if (!empty($advanced['tag_ids'])) {
            return true;
        }
        if (($advanced['customer_q'] ?? '') !== '') {
            return true;
        }
        if (($advanced['has_attachments'] ?? '') !== '') {
            return true;
        }
        if (($advanced['waiting_reply'] ?? '') !== '') {
            return true;
        }
        $searchIn = (array)($advanced['search_in'] ?? []);
        sort($searchIn);
        if ($searchIn && $searchIn !== ['messages', 'notes', 'subject']) {
            return true;
        }
        if (($advanced['date_field'] ?? 'updated') === 'created'
            && (($advanced['date_from'] ?? '') !== '' || ($advanced['date_to'] ?? '') !== '')
        ) {
            return true;
        }
        return false;
    }

    /**
     * @return array{sql:string,bindings:array,needs_customer_join:bool}
     */
    protected function buildAdvancedFilterSql(array $advanced, string $op = 'LIKE', string $fullNameExpr = '')
    {
        $sql = '';
        $bindings = [];
        $needsCustomerJoin = false;

        $dateField = (($advanced['date_field'] ?? 'updated') === 'created') ? 'created_at' : 'updated_at';
        if (!Schema::hasColumn('conversations', $dateField)) {
            $dateField = 'updated_at';
        }
        $dateFrom = (string)($advanced['date_from'] ?? '');
        $dateTo = (string)($advanced['date_to'] ?? '');
        if ($dateFrom !== '') {
            $sql .= " AND c.$dateField >= ?";
            $bindings[] = $dateFrom.' 00:00:00';
        }
        if ($dateTo !== '') {
            $sql .= " AND c.$dateField <= ?";
            $bindings[] = $dateTo.' 23:59:59';
        }

        $tagIds = array_values(array_unique(array_map('intval', (array)($advanced['tag_ids'] ?? []))));
        $tagIds = array_values(array_filter($tagIds, function ($id) {
            return $id > 0;
        }));
        if (count($tagIds) && $this->tagsOk()) {
            $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
            $sql .= " AND EXISTS (
                    SELECT 1 FROM conversation_tag ct
                    WHERE ct.conversation_id = c.id
                      AND ct.tag_id IN ($placeholders)
                )";
            $bindings = array_merge($bindings, $tagIds);
        }

        $hasAttachments = (string)($advanced['has_attachments'] ?? '');
        if ($hasAttachments === '1' && Schema::hasColumn('conversations', 'has_attachments')) {
            $sql .= ' AND c.has_attachments = 1';
        } elseif ($hasAttachments === '0' && Schema::hasColumn('conversations', 'has_attachments')) {
            $sql .= ' AND (c.has_attachments = 0 OR c.has_attachments IS NULL)';
        }

        $waitingReply = (string)($advanced['waiting_reply'] ?? '');
        if ($waitingReply !== '' && Schema::hasColumn('conversations', 'last_reply_from')) {
            $personCustomer = defined(Conversation::class.'::PERSON_CUSTOMER')
                ? (int)constant(Conversation::class.'::PERSON_CUSTOMER')
                : 1;
            $personUser = defined(Conversation::class.'::PERSON_USER')
                ? (int)constant(Conversation::class.'::PERSON_USER')
                : 2;
            if ($waitingReply === 'customer') {
                $sql .= ' AND c.last_reply_from = ?';
                $bindings[] = $personCustomer;
            } elseif ($waitingReply === 'user') {
                $sql .= ' AND c.last_reply_from = ?';
                $bindings[] = $personUser;
            }
        }

        $customerQ = trim((string)($advanced['customer_q'] ?? ''));
        if ($customerQ !== '') {
            $needsCustomerJoin = true;
            if ($fullNameExpr === '') {
                $driver = null;
                try {
                    $driver = DB::connection()->getDriverName();
                } catch (\Throwable $e) {
                    $driver = null;
                }
                $fullNameExpr = in_array($driver, ['pgsql', 'sqlite'], true)
                    ? "COALESCE(cu.first_name,'') || ' ' || COALESCE(cu.last_name,'')"
                    : "CONCAT(COALESCE(cu.first_name,''), ' ', COALESCE(cu.last_name,''))";
                if ($driver === 'pgsql') {
                    $op = 'ILIKE';
                }
            }
            $like = '%'.$customerQ.'%';
            $sql .= " AND (
                    COALESCE(c.customer_email,'') $op ?
                    OR COALESCE(cu.first_name,'') $op ?
                    OR COALESCE(cu.last_name,'') $op ?
                    OR $fullNameExpr $op ?
                    OR COALESCE(cu.phones,'') $op ?
                )";
            $bindings = array_merge($bindings, [$like, $like, $like, $like, $like]);
        }

        return [
            'sql' => $sql,
            'bindings' => $bindings,
            'needs_customer_join' => $needsCustomerJoin,
        ];
    }

    protected function loadFolders(array $allowedMailboxIds, int $mailboxId = 0)
    {
        try {
            if (!count($allowedMailboxIds)) {
                return [];
            }

            $ids = $mailboxId ? [$mailboxId] : $allowedMailboxIds;
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!count($ids)) {
                return [];
            }

            $query = Folder::whereIn('mailbox_id', $ids)
                ->select(['id', 'mailbox_id', 'type', 'user_id']);

            // Personal folders are not represented by conversations.folder_id consistently.
            if (property_exists(Folder::class, 'personal_types') && is_array(Folder::$personal_types)) {
                $query->whereNotIn('type', Folder::$personal_types);
            }

            $folders = $query->get();
            $mailboxNames = [];
            if (!$mailboxId && count($ids) > 1) {
                foreach (Mailbox::whereIn('id', $ids)->get(['id', 'name']) as $mailbox) {
                    $mailboxNames[(int)$mailbox->id] = (string)$mailbox->name;
                }
            }

            $out = [];
            foreach ($folders as $folder) {
                try {
                    $name = method_exists($folder, 'getTypeName')
                        ? (string)$folder->getTypeName()
                        : (string)(Folder::$types[(int)$folder->type] ?? '');
                } catch (\Throwable $e) {
                    $name = (string)(Folder::$types[(int)$folder->type] ?? '');
                }
                if ($name === '') {
                    continue;
                }

                $folderMailboxId = (int)$folder->mailbox_id;
                $label = isset($mailboxNames[$folderMailboxId])
                    ? $mailboxNames[$folderMailboxId].' — '.$name
                    : $name;

                $out[] = [
                    'id' => (int)$folder->id,
                    'name' => $label,
                    'mailbox_id' => $folderMailboxId,
                ];
            }

            usort($out, function ($a, $b) {
                return strcasecmp((string)$a['name'], (string)$b['name']);
            });

            return $out;
        } catch (\Throwable $e) {
            try {
                \Helper::logException($e);
            } catch (\Throwable $ignored) {
                // no-op
            }
            return [];
        }
    }
    protected function statusOptions()
    {
        $out = [];
        try {
            if (property_exists(Conversation::class, 'statuses') && is_array(Conversation::$statuses)) {
                foreach (Conversation::$statuses as $code => $name) {
                    $code = (int)$code;
                    if ($code <= 0) {
                        continue;
                    }
                    $label = '';
                    try {
                        $label = (string)Conversation::statusCodeToName($code);
                    } catch (\Throwable $e) {
                        $label = (string)$name;
                    }
                    if ($label === '') {
                        $label = (string)$name;
                    }
                    $out[$code] = $label;
                }
            }
        } catch (\Throwable $e) {
            $out = [];
        }

        // Keep stable order in UI, then append Deleted because it is a conversation state, not a status.
        ksort($out);
        $out[$this->deletedStatusFilterValue()] = (string)__('Deleted');

        return $out;
    }


    protected function writableStatusOptions()
    {
        $out = [];
        try {
            if (property_exists(Conversation::class, 'statuses') && is_array(Conversation::$statuses)) {
                foreach (Conversation::$statuses as $code => $name) {
                    $code = (int)$code;
                    if ($code <= 0) {
                        continue;
                    }
                    try {
                        $label = (string)Conversation::statusCodeToName($code);
                    } catch (\Throwable $e) {
                        $label = (string)$name;
                    }
                    $out[$code] = $label !== '' ? $label : (string)$name;
                }
            }
        } catch (\Throwable $e) {
            $out = [];
        }

        ksort($out);
        return $out;
    }

    protected function loadAssignableUsers(array $allowedMailboxIds, int $mailboxId = 0)
    {
        try {
            $ids = $allowedMailboxIds;
            if ($mailboxId) {
                $ids = [$mailboxId];
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!count($ids)) {
                return [];
            }

            $users = [];
            $mailboxes = Mailbox::whereIn('id', $ids)->get();
            foreach ($mailboxes as $mailbox) {
                try {
                    if (method_exists($mailbox, 'usersAssignable')) {
                        foreach ($mailbox->usersAssignable(true) as $mailboxUser) {
                            $uid = (int)($mailboxUser->id ?? 0);
                            if ($uid > 0) {
                                $users[$uid] = $mailboxUser;
                            }
                        }
                        continue;
                    }

                    if (method_exists($mailbox, 'usersHavingAccess')) {
                        foreach ($mailbox->usersHavingAccess() as $mailboxUser) {
                            $uid = (int)($mailboxUser->id ?? 0);
                            if ($uid > 0) {
                                $users[$uid] = $mailboxUser;
                            }
                        }
                        continue;
                    }

                    if (method_exists($mailbox, 'userIdsHavingAccess')) {
                        $userIds = $mailbox->userIdsHavingAccess();
                        if (is_array($userIds) && count($userIds)) {
                            $rows = DB::table('users')
                                ->select('id', 'first_name', 'last_name', 'email')
                                ->whereIn('id', $userIds)
                                ->get();
                            foreach ($rows as $row) {
                                $uid = (int)($row->id ?? 0);
                                if ($uid > 0) {
                                    $users[$uid] = $row;
                                }
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Fall back to already assigned users below if needed.
                }
            }

            if (!count($users)) {
                foreach ($this->loadAssignees($allowedMailboxIds, $mailboxId, 0) as $assignee) {
                    $row = (object)[
                        'id' => (int)($assignee['id'] ?? 0),
                        'first_name' => (string)($assignee['name'] ?? ''),
                        'last_name' => '',
                        'email' => '',
                    ];
                    if ((int)$row->id > 0) {
                        $users[(int)$row->id] = $row;
                    }
                }
            }

            $out = [];
            foreach ($users as $row) {
                $out[] = [
                    'id' => (int)$row->id,
                    'name' => $this->formatAssigneeLabel($row),
                ];
            }

            usort($out, function ($a, $b) {
                return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            });

            return $out;
        } catch (\Throwable $e) {
            return $this->loadAssignees($allowedMailboxIds, $mailboxId, 0);
        }
    }

    protected function loadFields(array $allowedMailboxIds, int $mailboxId = 0)
    {
        try {
            $ids = $allowedMailboxIds;
            if ($mailboxId) {
                $ids = [$mailboxId];
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!count($ids)) {
                return [];
            }

            // Prefer the official CustomFields module entity when available so we stay aligned
            // with the module's own mailbox-level field semantics.
            if (class_exists('Modules\CustomFields\Entities\CustomField')) {
                $out = [];
                foreach ($ids as $id) {
                    try {
                        $fields = \Modules\CustomFields\Entities\CustomField::getMailboxCustomFields($id);
                        foreach ($fields as $field) {
                            $options = [];
                            if (isset($field->options) && is_array($field->options)) {
                                $options = $field->options;
                            } elseif (isset($field->options) && $field->options !== null && $field->options !== '') {
                                $decoded = \Helper::jsonDecode((string)$field->options, true);
                                if (is_array($decoded)) {
                                    $options = $decoded;
                                }
                            }
                            $out[] = [
                                'id' => (int)$field->id,
                                'name' => (string)$field->name,
                                'mailbox_id' => (int)$field->mailbox_id,
                                'type' => (int)($field->type ?? 0),
                                'options' => $options,
                            ];
                        }
                    } catch (\Throwable $e) {
                        // ignore and fall back below if needed
                    }
                }
                if (count($out)) {
                    usort($out, function ($a, $b) {
                        return strcasecmp((string)$a['name'], (string)$b['name']);
                    });
                    return $out;
                }
            }

            $in = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT id, name, mailbox_id, type, options FROM custom_fields WHERE mailbox_id IN ($in) ORDER BY name";
            $rows = DB::select($sql, $ids);

            $out = [];
            foreach ($rows as $r) {
                $options = [];
                if (isset($r->options) && $r->options !== null && $r->options !== '') {
                    $decoded = \Helper::jsonDecode((string)$r->options, true);
                    if (is_array($decoded)) {
                        $options = $decoded;
                    }
                }
                $out[] = [
                    'id' => (int)$r->id,
                    'name' => (string)$r->name,
                    'mailbox_id' => (int)$r->mailbox_id,
                    'type' => (int)($r->type ?? 0),
                    'options' => $options,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Best-effort single-field resolver for cases where the dropdown list does not include
     * the selected field (e.g. mailbox filter/permissions changed).
     */
    protected function loadFieldById(array $allowedMailboxIds, int $fieldId)
    {
        try {
            if (!$fieldId || !count($allowedMailboxIds)) {
                return null;
            }

            if (class_exists('Modules\CustomFields\Entities\CustomField')) {
                try {
                    $field = \Modules\CustomFields\Entities\CustomField::where('id', $fieldId)
                        ->whereIn('mailbox_id', $allowedMailboxIds)
                        ->first();
                    if ($field) {
                        $options = [];
                        if (isset($field->options) && is_array($field->options)) {
                            $options = $field->options;
                        } elseif (isset($field->options) && $field->options !== null && $field->options !== '') {
                            $decoded = \Helper::jsonDecode((string)$field->options, true);
                            if (is_array($decoded)) {
                                $options = $decoded;
                            }
                        }
                        return [
                            'id' => (int)$field->id,
                            'name' => (string)$field->name,
                            'mailbox_id' => (int)$field->mailbox_id,
                            'type' => (int)($field->type ?? 0),
                            'options' => $options,
                        ];
                    }
                } catch (\Throwable $e) {
                    // ignore and fall back
                }
            }

            $in = implode(',', array_fill(0, count($allowedMailboxIds), '?'));
            $sql = "SELECT id, name, mailbox_id, type, options FROM custom_fields WHERE id = ? AND mailbox_id IN ($in) LIMIT 1";
            $row = DB::selectOne($sql, array_merge([$fieldId], $allowedMailboxIds));
            if (!$row) {
                return null;
            }

            $options = [];
            if (isset($row->options) && $row->options !== null && $row->options !== '') {
                // Stored as JSON in the CustomFields module.
                $decoded = \Helper::jsonDecode((string)$row->options, true);
                if (is_array($decoded)) {
                    $options = $decoded;
                }
            }

            return [
                'id' => (int)$row->id,
                'name' => (string)$row->name,
                'mailbox_id' => (int)$row->mailbox_id,
                'type' => (int)($row->type ?? 0),
                'options' => $options,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getAssignedOnlyUserId($user = null)
    {
        try {
            if (!$user) {
                $user = Auth::user();
            }
            if (!$user) {
                return 0;
            }

            if (method_exists($user, 'isAdmin')) {
                if ($user->isAdmin()) {
                    return 0;
                }
            } else {
                $adminRole = defined(User::class.'::ROLE_ADMIN')
                    ? (int)constant(User::class.'::ROLE_ADMIN')
                    : 2;
                if ((int)($user->role ?? 0) === $adminRole) {
                    return 0;
                }
            }

            // FreeScout 1.8.225+ exposes this as a user permission.
            if (method_exists($user, 'canSeeOnlyAssignedConversations')) {
                return $user->canSeeOnlyAssignedConversations() ? (int)$user->id : 0;
            }

            if (defined(User::class.'::PERM_ONLY_ASSIGNED_TICKETS') && method_exists($user, 'hasPermission')) {
                $permission = (int)constant(User::class.'::PERM_ONLY_ASSIGNED_TICKETS');
                if ($user->hasPermission($permission)) {
                    return (int)$user->id;
                }
            }

            // Backward compatibility for installations using the legacy config list.
            $raw = config('app.show_only_assigned_conversations');
            $raw = is_string($raw) ? trim($raw) : '';
            if ($raw === '') {
                return 0;
            }

            $ids = preg_split('/[^0-9]+/', $raw);
            foreach ((array)$ids as $id) {
                if ((int)$id === (int)($user->id ?? 0) && (int)$id > 0) {
                    return (int)$id;
                }
            }
        } catch (\Throwable $e) {
            return 0;
        }

        return 0;
    }

    protected function buildAssignedVisibilitySql(int $assignedOnlyUserId, string $alias = 'c')
    {
        if ($assignedOnlyUserId <= 0) {
            return ['sql' => '', 'bindings' => []];
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/i', $alias)) {
            $alias = 'c';
        }

        $bindings = [$assignedOnlyUserId];
        $conditions = [$alias.'.user_id = ?'];
        if ($this->conversationCreatorColumnOk()) {
            $conditions[] = $alias.'.created_by_user_id = ?';
            $bindings[] = $assignedOnlyUserId;
        }

        $result = [
            'sql' => ' AND ('.implode(' OR ', $conditions).')',
            'bindings' => $bindings,
        ];

        // Assignment modules may extend this condition without a core edit.
        try {
            $filtered = \Eventy::filter(
                'smartsearch.assigned_visibility_sql',
                $result,
                Auth::user(),
                $alias
            );
            if (is_array($filtered)
                && isset($filtered['sql'], $filtered['bindings'])
                && is_string($filtered['sql'])
                && is_array($filtered['bindings'])
            ) {
                $result = $filtered;
            }
        } catch (\Throwable $e) {
            // Keep the safe core condition.
        }

        return $result;
    }

    protected function canViewConversation($conversation, $user = null)
    {
        if (!$conversation) {
            return false;
        }
        if (!$user) {
            $user = Auth::user();
        }
        if (!$user) {
            return false;
        }

        try {
            return (bool)$user->can('view', $conversation);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function canViewConversationId(int $conversationId, $user = null)
    {
        if ($conversationId <= 0) {
            return false;
        }

        try {
            return $this->canViewConversation(Conversation::find($conversationId), $user);
        } catch (\Throwable $e) {
            return false;
        }
    }
    protected function formatAssigneeLabel($row)
    {
        try {
            if (is_object($row) && method_exists($row, 'getFullName')) {
                $modelName = trim((string)$row->getFullName());
                if ($modelName !== '') {
                    return $modelName;
                }
            }
        } catch (\Throwable $e) {
            // Fall back to the selected attributes below.
        }

        $firstName = trim((string)($row->first_name ?? ''));
        $lastName = trim((string)($row->last_name ?? ''));
        $email = trim((string)($row->email ?? ''));

        $fullName = trim($firstName.' '.$lastName);
        if ($fullName !== '') {
            return $fullName;
        }

        if ($email !== '') {
            return $email;
        }

        return '#'.((int)($row->id ?? 0));
    }

    protected function isValidAssigneeFilter(int $assigneeId, array $assignees)
    {
        if ($assigneeId === 0 || $assigneeId === -1) {
            return true;
        }

        foreach ($assignees as $assignee) {
            if ((int)($assignee['id'] ?? 0) === $assigneeId) {
                return true;
            }
        }

        return false;
    }

    protected function loadAssignees(array $allowedMailboxIds, int $mailboxId = 0, int $assignedOnlyUserId = 0)
    {
        try {
            $ids = $allowedMailboxIds;
            if ($mailboxId) {
                $ids = [$mailboxId];
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!count($ids)) {
                return [];
            }

            $in = implode(',', array_fill(0, count($ids), '?'));
            $visibility = $this->buildAssignedVisibilitySql($assignedOnlyUserId, 'c');
            $bindings = array_merge($ids, (array)$visibility['bindings']);

            $rows = DB::select(
                "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email\n".
                "FROM conversations c\n".
                "JOIN users u ON u.id = c.user_id\n".
                "WHERE c.mailbox_id IN ($in)\n".
                "  AND c.user_id IS NOT NULL\n".
                "  AND c.user_id > 0\n".
                (string)$visibility['sql']."\n".
                "ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC",
                $bindings
            );

            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'id' => (int)$row->id,
                    'name' => $this->formatAssigneeLabel($row),
                ];
            }

            if ($assignedOnlyUserId > 0 && !count($out)) {
                $userRow = DB::selectOne(
                    "SELECT id, first_name, last_name, email FROM users WHERE id = ? LIMIT 1",
                    [$assignedOnlyUserId]
                );
                if ($userRow) {
                    $out[] = [
                        'id' => (int)$userRow->id,
                        'name' => $this->formatAssigneeLabel($userRow),
                    ];
                }
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Append selected custom field values to results (single query, no N+1).
     */
    protected function appendCustomFieldValues(array $results, int $fieldId, $fieldMeta = null)
    {
        try {
            if (!$fieldId || !count($results)) {
                return $results;
            }

            $convIds = [];
            foreach ($results as $r) {
                $id = (int)($r['id'] ?? 0);
                if ($id) {
                    $convIds[] = $id;
                }
            }
            $convIds = array_values(array_unique($convIds));
            if (!count($convIds)) {
                return $results;
            }

            $in = implode(',', array_fill(0, count($convIds), '?'));
            $sql = "SELECT conversation_id, value FROM conversation_custom_field WHERE custom_field_id = ? AND conversation_id IN ($in)";
            $rows = DB::select($sql, array_merge([$fieldId], $convIds));

            $map = [];
            foreach ($rows as $row) {
                $map[(int)$row->conversation_id] = (string)($row->value ?? '');
            }

            foreach ($results as &$r) {
                $id = (int)($r['id'] ?? 0);
                $raw = $id && array_key_exists($id, $map) ? $map[$id] : '';

                // Convert dropdown stored values (keys) into labels.
                // CustomFields module types:
                //  1 = Dropdown
                //  8 = Multiselect Dropdown
                if ($raw !== '' && $fieldMeta && !empty($fieldMeta['type']) && !empty($fieldMeta['options']) && is_array($fieldMeta['options'])) {
                    $type = (int)$fieldMeta['type'];
                    $options = $fieldMeta['options'];

                    if ($type === 1) {
                        $r['field_value'] = array_key_exists($raw, $options) ? (string)$options[$raw] : $raw;
                        continue;
                    }

                    if ($type === 8) {
                        $parts = array_filter(array_map('trim', explode(',', (string)$raw)), function ($v) {
                            return $v !== '';
                        });
                        $labels = [];
                        foreach ($parts as $p) {
                            $labels[] = array_key_exists($p, $options) ? (string)$options[$p] : $p;
                        }
                        $r['field_value'] = implode(', ', $labels);
                        continue;
                    }
                }

                $r['field_value'] = $raw;
            }
            unset($r);

            return $results;
        } catch (\Throwable $e) {
            // Never break the listing.
            return $results;
        }
    }

    /**
     * Whitelist + normalize sorting.
     *
     * Supported values:
     *  - updated_desc (default)
     *  - updated_asc
     *  - id_desc
     *  - id_asc
     */

    /**
     * Hydrate result rows with values for multiple custom fields.
     *
     * @param array $cfFilters
     */
    protected function appendMultipleCustomFieldValues(array $results, array $cfFilters)
    {
        if (!count($results) || !count($cfFilters)) {
            return $results;
        }

        foreach ($results as $k => $row) {
            $results[$k]['cf_values'] = [];
        }

        foreach ($cfFilters as $filter) {
            $id = (int)$filter['id'];
            $meta = $filter['meta'] ?? null;
            $name = is_array($meta) ? (string)($meta['name'] ?? ('#'.$id)) : ('#'.$id);
            $results = $this->appendCustomFieldValues($results, $id, $meta);
            foreach ($results as $k => $row) {
                $val = (string)($row['field_value'] ?? '');
                $results[$k]['cf_values'][$id] = $val;
                if (!isset($results[$k]['_cf_parts'])) {
                    $results[$k]['_cf_parts'] = [];
                }
                if ($val !== '') {
                    $results[$k]['_cf_parts'][] = $name.': '.$val;
                }
            }
        }

        foreach ($results as $k => $row) {
            $parts = (array)($row['_cf_parts'] ?? []);
            $results[$k]['field_value'] = implode(' · ', $parts);
            unset($results[$k]['_cf_parts']);
        }

        return $results;
    }

    /**
     * @param int[] $allowedCfIds
     * @return array{key:string,order_by:string,join_sql:string,bindings:array}
     */
    protected function resolveSort(string $sort, array $allowedCfIds = [])
    {
        $sort = trim(strtolower($sort));
        if (preg_match('/^cf_(\d+)_(asc|desc)$/', $sort, $m)) {
            $fieldId = (int)$m[1];
            $allowedCfIds = array_values(array_unique(array_map('intval', $allowedCfIds)));
            if ($fieldId > 0 && in_array($fieldId, $allowedCfIds, true) && $this->customFieldsOk()) {
                $dir = $m[2] === 'asc' ? 'ASC' : 'DESC';
                return [
                    'key' => 'cf_'.$fieldId.'_'.$m[2],
                    'order_by' => 'ccf_sort.value '.$dir.', c.id '.$dir,
                    'join_sql' => ' LEFT JOIN conversation_custom_field ccf_sort ON ccf_sort.conversation_id = c.id AND ccf_sort.custom_field_id = ?',
                    'bindings' => [$fieldId],
                ];
            }
        }

        $key = $this->normalizeSort($sort);
        return [
            'key' => $key,
            'order_by' => $this->sortToOrderBy($key),
            'join_sql' => '',
            'bindings' => [],
        ];
    }

    protected function normalizeSort(string $sort)
    {
        $sort = trim(strtolower($sort));
        $allowed = ['updated_desc', 'updated_asc', 'created_desc', 'created_asc', 'id_desc', 'id_asc'];
        return in_array($sort, $allowed, true) ? $sort : 'updated_desc';
    }

    /**
     * Convert sort key into a safe ORDER BY clause.
     */
    protected function sortToOrderBy(string $sort)
    {
        switch ($sort) {
            case 'updated_asc':
                return 'c.updated_at ASC, c.id ASC';
            case 'created_desc':
                return 'c.created_at DESC, c.id DESC';
            case 'created_asc':
                return 'c.created_at ASC, c.id ASC';
            case 'id_desc':
                return 'c.id DESC';
            case 'id_asc':
                return 'c.id ASC';
            case 'updated_desc':
            default:
                return 'c.updated_at DESC, c.id DESC';
        }
    }

    protected function search(string $q, array $allowedMailboxIds, int $mailboxId, array $cfFilters, array $statuses, int $folderId, int $assigneeId, int $assignedOnlyUserId, int $page, int $perPage, string $sort = 'updated_desc', array $advanced = [])
    {
        $offset = ($page - 1) * $perPage;
        $like = '%'.$q.'%';

        // Determine LIKE operator by driver.
        $driver = null;
        try {
            $driver = DB::connection()->getDriverName();
        } catch (\Throwable $e) {
            $driver = null;
        }
        $op = ($driver === 'pgsql') ? 'ILIKE' : 'LIKE';
        $cfOk = $this->customFieldsOk();
        $cfDefsOk = $this->customFieldDefinitionsOk();
        $allowedCfSortIds = [];
        foreach ($cfFilters as $filter) {
            $cid = (int)($filter['id'] ?? 0);
            if ($cid > 0) {
                $allowedCfSortIds[] = $cid;
            }
        }
        $sortInfo = $this->resolveSort($sort, $allowedCfSortIds);
        $orderBy = (string)$sortInfo['order_by'];
        $sortJoinSql = (string)$sortInfo['join_sql'];
        $sortBindings = (array)$sortInfo['bindings'];

        $ids = $allowedMailboxIds;
        if ($mailboxId) {
            $ids = [$mailboxId];
        }
        if (!count($ids)) {
            return [[], 0];
        }

        $in = implode(',', array_fill(0, count($ids), '?'));

        // Strict numeric resolution.
        // If user typed a numeric query (#1234 or 1234), treat it as a conversation/thread ID and jump.
        $numericTicket = $this->normalizeTicketNumberExact($q);
        $isStrictNumeric = (!count($cfFilters) && !$this->advancedFiltersActive($advanced) && $numericTicket !== null && $this->isStrictNumericQuery($q));
        if ($isStrictNumeric) {
            $resolved = $this->resolveNumericConversation(
                $numericTicket,
                $ids,
                $in,
                $statuses,
                $folderId,
                $assigneeId,
                $assignedOnlyUserId,
                $this->isDisplayNumberQuery($q)
            );
            if ($resolved !== null) {
                // Deterministic hit: return a single conversation.
                return [[[
                    'id' => (int)$resolved['id'],
                    'subject' => (string)$resolved['subject'],
                    'mailbox_id' => (int)$resolved['mailbox_id'],
                    'status' => (int)$resolved['status'],
                    'state' => (int)($resolved['state'] ?? 0),
                    'updated_at' => (string)$resolved['updated_at'],
                ]], 1];
            }

            // Compatibility fallback:
            // if the numeric input is not an actual conversation/thread ID,
            // continue into the normal LIKE-based search so order numbers or
            // other numeric values present in the conversation subject/title,
            // preview, email, customer data, or custom fields still match.
        }

        $cfExistsSql = $cfOk ? "EXISTS (\n                    SELECT 1 FROM conversation_custom_field ccf\n                    WHERE ccf.conversation_id = c.id\n                      AND ccf.value $op ?\n                )" : "0=1";

        // Driver-specific concats.
        $fullNameExpr = in_array($driver, ['pgsql', 'sqlite'], true)
            ? "COALESCE(cu.first_name,'') || ' ' || COALESCE(cu.last_name,'')"
            : "CONCAT(COALESCE(cu.first_name,''), ' ', COALESCE(cu.last_name,''))";

        $bindings = [];
        $bindings = array_merge($bindings, $ids);

        // Optional filters (ANDed).
        $filterSql = '';
        $filterBindings = [];
        $statusFilter = $this->buildStatusFilterSql($statuses);
        if ($statusFilter['sql'] !== '') {
            $filterSql .= $statusFilter['sql'];
            $filterBindings = array_merge($filterBindings, $statusFilter['bindings']);
        }
        if ($folderId) {
            $filterSql .= " AND c.folder_id = ?";
            $filterBindings[] = $folderId;
        }
        if ($assigneeId === -1) {
            $filterSql .= " AND (c.user_id IS NULL OR c.user_id <= 0)";
        } elseif ($assigneeId > 0) {
            $filterSql .= " AND c.user_id = ?";
            $filterBindings[] = $assigneeId;
        }

        $visibility = $this->buildAssignedVisibilitySql($assignedOnlyUserId, 'c');
        $filterSql .= (string)$visibility['sql'];
        $filterBindings = array_merge($filterBindings, (array)$visibility['bindings']);

        $advancedFilter = $this->buildAdvancedFilterSql($advanced, $op, $fullNameExpr);
        $filterSql .= (string)$advancedFilter['sql'];
        $filterBindings = array_merge($filterBindings, (array)$advancedFilter['bindings']);
        $needsCustomerJoin = !empty($advancedFilter['needs_customer_join']);

        // If custom field filter(s) are selected, restrict to those fields (AND across fields).
        if (count($cfFilters)) {
            $cfFilter = $this->buildMultiCustomFieldFiltersSql($cfFilters, $q, $op);
            $fromSql = 'conversations c';
            if ($needsCustomerJoin) {
                $fromSql .= ' LEFT JOIN customers cu ON cu.id = c.customer_id';
            }
            $fromSql .= $sortJoinSql;
            $countSql = "SELECT COUNT(DISTINCT c.id) AS cnt
                FROM $fromSql
                WHERE c.mailbox_id IN ($in)
                  $filterSql
                  ".$cfFilter['sql'];

            $sql = "SELECT DISTINCT c.id, c.subject, c.mailbox_id, c.status, c.state, c.updated_at
                FROM $fromSql
                WHERE c.mailbox_id IN ($in)
                  $filterSql
                  ".$cfFilter['sql']."
                ORDER BY $orderBy
                LIMIT $perPage OFFSET $offset";

            $b = array_merge($sortBindings, $bindings, $filterBindings, $cfFilter['bindings']);
            return $this->runSearch($countSql, $sql, $b);
        }

        // Unified search across selected scopes (and full defaults when all scopes selected).
        $searchIn = (array)($advanced['search_in'] ?? ['subject', 'messages', 'notes']);
        $wantSubject = in_array('subject', $searchIn, true);
        $wantMessages = in_array('messages', $searchIn, true);
        $wantNotes = in_array('notes', $searchIn, true);
        $isDefaultScopes = $wantSubject && $wantMessages && $wantNotes;

        $whereParts = [];
        $searchBindings = [];

        if ($isDefaultScopes && $cfOk) {
            $whereParts[] = $cfExistsSql;
            $searchBindings[] = $like;
        }

        if ($wantSubject) {
            $whereParts[] = "COALESCE(c.subject,'') $op ?";
            $searchBindings[] = $like;
            $whereParts[] = "COALESCE(c.preview,'') $op ?";
            $searchBindings[] = $like;
        }

        if ($isDefaultScopes) {
            $needsCustomerJoin = true;
            $whereParts[] = "COALESCE(c.customer_email,'') $op ?";
            $searchBindings[] = $like;
            $whereParts[] = "COALESCE(cu.first_name,'') $op ?";
            $searchBindings[] = $like;
            $whereParts[] = "COALESCE(cu.last_name,'') $op ?";
            $searchBindings[] = $like;
            $whereParts[] = "$fullNameExpr $op ?";
            $searchBindings[] = $like;
            $whereParts[] = "COALESCE(cu.phones,'') $op ?";
            $searchBindings[] = $like;
        }

        // Search published content without joining threads into the result set.
        if (($wantMessages || $wantNotes) && $this->shouldSearchThreadBody() && $this->threadsSearchOk()) {
            $publishedState = defined(Thread::class.'::STATE_PUBLISHED')
                ? (int)constant(Thread::class.'::STATE_PUBLISHED')
                : 2;
            $threadTypes = [];
            if ($wantMessages) {
                foreach (['TYPE_CUSTOMER', 'TYPE_MESSAGE'] as $constantName) {
                    $constant = Thread::class.'::'.$constantName;
                    if (defined($constant)) {
                        $threadTypes[] = (int)constant($constant);
                    }
                }
            }
            if ($wantNotes) {
                $constant = Thread::class.'::TYPE_NOTE';
                if (defined($constant)) {
                    $threadTypes[] = (int)constant($constant);
                }
            }
            $threadTypes = array_values(array_unique(array_filter($threadTypes)));
            if (count($threadTypes)) {
                $threadTypePlaceholders = implode(',', array_fill(0, count($threadTypes), '?'));
                $whereParts[] = "EXISTS (\n                    SELECT 1 FROM threads st\n                    WHERE st.conversation_id = c.id\n                      AND st.state = ?\n                      AND st.type IN ($threadTypePlaceholders)\n                      AND COALESCE(st.body,'') $op ?\n                )";
                $searchBindings[] = $publishedState;
                $searchBindings = array_merge($searchBindings, $threadTypes);
                $searchBindings[] = $like;
            }
        }

        if (!count($whereParts)) {
            // No searchable scope selected and thread search disabled: match nothing.
            return [[], 0];
        }

        $whereSql = implode("\n                OR ", $whereParts);

        $fromSql = 'conversations c';
        if ($needsCustomerJoin) {
            $fromSql .= ' LEFT JOIN customers cu ON cu.id = c.customer_id';
        }
        $fromSql .= $sortJoinSql;

        $countSql = "SELECT COUNT(DISTINCT c.id) AS cnt
            FROM $fromSql
            WHERE c.mailbox_id IN ($in)
              $filterSql
              AND (
                $whereSql
              )";

        $sql = "SELECT DISTINCT c.id, c.subject, c.mailbox_id, c.status, c.state, c.updated_at
            FROM $fromSql
            WHERE c.mailbox_id IN ($in)
              $filterSql
              AND (
                $whereSql
              )
            ORDER BY $orderBy
            LIMIT $perPage OFFSET $offset";

        $b = array_merge($sortBindings, $bindings, $filterBindings, $searchBindings);
        return $this->runSearch($countSql, $sql, $b);
    }

    /**
     * Normalize an exact numeric query into an integer.
     *
     * Plain digits prefer the internal conversation ID. A # prefix prefers
     * FreeScout's display conversation number. Thread IDs remain a fallback.
     */
    protected function normalizeTicketNumberExact(string $q)
    {
        $q = trim($q);
        if ($q === '') {
            return null;
        }

        // Remove common wrappers/prefixes.
        $q2 = preg_replace('/^[\s\[#\(]+/', '', $q) ?? '';
        $q2 = preg_replace('/[\]\)\s]+$/', '', $q2) ?? '';

        if (!preg_match('/^\d{1,12}$/', $q2)) {
            return null;
        }
        $n = (int)$q2;
        return $n > 0 ? $n : null;
    }

    protected function isDisplayNumberQuery(string $q)
    {
        return (bool)preg_match('/^\s*[\[\(]?\s*#\s*\d{1,12}\s*[\]\)]?\s*$/', trim($q));
    }

    protected function conversationNumberFieldName()
    {
        $field = 'number';
        try {
            if (method_exists(Conversation::class, 'numberFieldName')) {
                $candidate = (string)Conversation::numberFieldName();
                if (preg_match('/^[a-z][a-z0-9_]*$/i', $candidate)
                    && Schema::hasColumn('conversations', $candidate)
                ) {
                    $field = $candidate;
                }
            }
        } catch (\Throwable $e) {
            $field = 'number';
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/i', $field)) {
            return null;
        }

        try {
            return Schema::hasColumn('conversations', $field) ? $field : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Strict numeric query heuristic.
     * Treat plain numeric input and common wrappers as an intentional "go to conversation" lookup.
     */
    protected function isStrictNumericQuery(string $q)
    {
        $q = trim($q);
        if ($q === '') {
            return false;
        }
        // Allow: 123, #123, [#123], (123)
        return (bool)preg_match('/^\s*[\[#\(]*\s*\d{1,12}\s*[\]\)]*\s*$/', $q);
    }

    /**
     * Deterministically resolve a numeric query to the correct conversation.
     * Plain digits prefer conversations.id; #digits prefer FreeScout's display number.
     * A matching thread ID is accepted as the final backward-compatible fallback.
     */
    protected function resolveNumericConversation(
        int $number,
        array $mailboxIds,
        string $inPlaceholders,
        array $statuses = [],
        int $folderId = 0,
        int $assigneeId = 0,
        int $assignedOnlyUserId = 0,
        bool $preferDisplayNumber = false
    ) {
        try {
            $filter = $this->buildExactConversationFilterSql(
                $statuses,
                $folderId,
                $assigneeId,
                $assignedOnlyUserId
            );

            $lookups = $preferDisplayNumber ? ['number', 'id'] : ['id', 'number'];
            foreach ($lookups as $lookup) {
                if ($lookup === 'id') {
                    $row = $this->fetchExactConversationById(
                        $number,
                        $mailboxIds,
                        $inPlaceholders,
                        (string)$filter['sql'],
                        (array)$filter['bindings']
                    );
                } else {
                    $row = $this->fetchExactConversationByNumber(
                        $number,
                        $mailboxIds,
                        $inPlaceholders,
                        (string)$filter['sql'],
                        (array)$filter['bindings']
                    );
                }

                if ($row !== null && $this->canViewConversationId((int)$row['id'])) {
                    return $row;
                }
            }

            // A thread ID is also accepted for backward compatibility.
            if ($this->threadsTableOk()) {
                $thread = DB::selectOne(
                    'SELECT conversation_id FROM threads WHERE id = ? LIMIT 1',
                    [$number]
                );
                $conversationId = $thread ? (int)($thread->conversation_id ?? 0) : 0;
                if ($conversationId > 0) {
                    $row = $this->fetchExactConversationById(
                        $conversationId,
                        $mailboxIds,
                        $inPlaceholders,
                        (string)$filter['sql'],
                        (array)$filter['bindings']
                    );
                    if ($row !== null && $this->canViewConversationId((int)$row['id'])) {
                        return $row;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through to normal text search.
        }

        return null;
    }
    protected function buildExactConversationFilterSql(array $statuses, int $folderId, int $assigneeId, int $assignedOnlyUserId = 0)
    {
        $sql = '';
        $bindings = [];

        $statusFilter = $this->buildStatusFilterSql($statuses);
        if ($statusFilter['sql'] !== '') {
            $sql .= $statusFilter['sql'];
            $bindings = array_merge($bindings, $statusFilter['bindings']);
        }

        if ($folderId) {
            $sql .= ' AND c.folder_id = ?';
            $bindings[] = $folderId;
        }

        if ($assigneeId === -1) {
            $sql .= ' AND (c.user_id IS NULL OR c.user_id <= 0)';
        } elseif ($assigneeId > 0) {
            $sql .= ' AND c.user_id = ?';
            $bindings[] = $assigneeId;
        }

        $visibility = $this->buildAssignedVisibilitySql($assignedOnlyUserId, 'c');
        $sql .= (string)$visibility['sql'];
        $bindings = array_merge($bindings, (array)$visibility['bindings']);

        return [
            'sql' => $sql,
            'bindings' => $bindings,
        ];
    }

    protected function fetchExactConversationById(int $conversationId, array $mailboxIds, string $inPlaceholders, string $extraSql = '', array $extraBindings = [])
    {
        $row = DB::select(
            "SELECT c.id, c.subject, c.mailbox_id, c.status, c.state, c.updated_at\n".
            "FROM conversations c\n".
            "WHERE c.mailbox_id IN ($inPlaceholders) AND c.id = ?".$extraSql."\n".
            "LIMIT 1",
            array_merge($mailboxIds, [$conversationId], $extraBindings)
        );

        if (empty($row)) {
            return null;
        }

        $r = $row[0];
        return [
            'id' => (int)$r->id,
            'subject' => (string)$r->subject,
            'mailbox_id' => (int)$r->mailbox_id,
            'status' => (int)$r->status,
            'state' => isset($r->state) ? (int)$r->state : 0,
            'updated_at' => (string)$r->updated_at,
        ];
    }


    protected function fetchExactConversationByNumber(int $number, array $mailboxIds, string $inPlaceholders, string $extraSql = '', array $extraBindings = [])
    {
        $numberField = $this->conversationNumberFieldName();
        if (!$numberField) {
            return null;
        }

        $rows = DB::select(
            "SELECT c.id, c.subject, c.mailbox_id, c.status, c.state, c.updated_at\n".
            "FROM conversations c\n".
            "WHERE c.mailbox_id IN ($inPlaceholders) AND c.$numberField = ?".$extraSql."\n".
            "LIMIT 1",
            array_merge($mailboxIds, [$number], $extraBindings)
        );

        if (empty($rows)) {
            return null;
        }

        $row = $rows[0];
        return [
            'id' => (int)$row->id,
            'subject' => (string)$row->subject,
            'mailbox_id' => (int)$row->mailbox_id,
            'status' => (int)$row->status,
            'state' => isset($row->state) ? (int)$row->state : 0,
            'updated_at' => (string)$row->updated_at,
        ];
    }

    protected function appendStatusMeta(array $results)
    {
        foreach ($results as $key => $row) {
            $status = (int)($row['status'] ?? 0);
            $state = (int)($row['state'] ?? 0);
            $badge = $this->getConversationBadgeMeta($status, $state);

            $results[$key]['status_name'] = (string)($badge['name'] ?? '');
            $results[$key]['status_class'] = (string)($badge['class'] ?? 'default');
        }

        return $results;
    }

    /**
     * Parse status filter from request.
     * Accepts status[] array or legacy single status int.
     * Empty / [-1] => no status filter.
     *
     * @return array
     */
    protected function parseStatusFilter(Request $request)
    {
        $statusOptions = $this->statusOptions();
        $raw = $request->input('status', null);
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $out = [];
        foreach ($raw as $value) {
            $code = (int)$value;
            if ($code === -1) {
                continue;
            }
            if (array_key_exists($code, $statusOptions)) {
                $out[$code] = $code;
            }
        }

        return array_values($out);
    }

    /**
     * Default statuses from Manage → Settings → Smart Search.
     *
     * @param array $statusOptions
     * @return int[]
     */
    protected function getDefaultStatusesSetting(array $statusOptions)
    {
        $raw = \Option::get(
            'smartsearch.default_statuses',
            (array)config('smartsearch.default_statuses', [1, 2])
        );
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $out = [];
        foreach ($raw as $value) {
            $code = (int)$value;
            if (array_key_exists($code, $statusOptions)) {
                $out[$code] = $code;
            }
        }

        return array_values($out);
    }

    /**
     * Default mailbox from Manage → Settings → Smart Search.
     */
    protected function getDefaultMailboxIdSetting()
    {
        return (int)\Option::get(
            'smartsearch.default_mailbox_id',
            (int)config('smartsearch.default_mailbox_id', 0)
        );
    }

    /**
     * Whether message/note body search is enabled.
     */
    protected function shouldSearchThreadBody()
    {
        $value = \Option::get(
            'smartsearch.search_thread_body',
            config('smartsearch.search_thread_body', true)
        );

        return (bool)$value;
    }

    /**
     * Parse custom field value filter from request.
     * Accepts field_values[] array or legacy field_value string.
     *
     * @return array
     */
    /**
     * Parse one or more custom-field filters.
     * New: cf_filters[i][id] + cf_filters[i][values][] + cf_filters[i][op]
     * Legacy: field_id + field_values[]
     *
     * @param array $fieldsById id => field meta
     * @return array<int, array{id:int,values:array,op:string,meta:?array}>
     */
    protected function parseCfFilters(Request $request, array $fieldsById, bool $cfOk)
    {
        if (!$cfOk) {
            return [];
        }

        $out = [];
        $rawRows = $request->input('cf_filters', null);
        if (is_array($rawRows)) {
            foreach ($rawRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int)($row['id'] ?? 0);
                if ($id <= 0 || !isset($fieldsById[$id])) {
                    continue;
                }
                $meta = $fieldsById[$id];
                $type = (int)($meta['type'] ?? 0);
                $filterOp = $this->normalizeCfFilterOp($type, (string)($row['op'] ?? ''));

                $values = $row['values'] ?? [];
                if (!is_array($values)) {
                    $values = ($values === null || $values === '') ? [] : [$values];
                }

                // Legacy checkbox "__empty__" without op=empty → treat as empty op.
                foreach ($values as $rawVal) {
                    if ((string)$rawVal === '__empty__') {
                        $filterOp = 'empty';
                        break;
                    }
                }

                $norm = [];
                if ($filterOp === 'empty') {
                    $norm = [];
                } elseif ($filterOp === 'between') {
                    // values[0]=from, values[1]=to — do not split commas.
                    foreach ($values as $value) {
                        $value = trim((string)$value);
                        if ($value === '' || $value === '__empty__') {
                            continue;
                        }
                        $norm[] = $value;
                        if (count($norm) >= 2) {
                            break;
                        }
                    }
                } elseif (in_array($filterOp, ['contains_any', 'contains'], true) && in_array($type, [7, 2, 3], true)) {
                    // Tags / text: comma-separated → OR values.
                    foreach ($values as $value) {
                        $value = trim((string)$value);
                        if ($value === '' || $value === '__empty__') {
                            continue;
                        }
                        if (strpos($value, ',') !== false) {
                            foreach (preg_split('/\s*,\s*/', $value) ?: [] as $part) {
                                $part = trim((string)$part);
                                if ($part !== '') {
                                    $norm[$part] = $part;
                                }
                            }
                            continue;
                        }
                        $norm[$value] = $value;
                    }
                    $norm = array_values($norm);
                } else {
                    // Exact / single-value ops: keep each submitted value as-is (no comma split).
                    foreach ($values as $value) {
                        $value = trim((string)$value);
                        if ($value === '' || $value === '__empty__') {
                            continue;
                        }
                        $norm[$value] = $value;
                    }
                    $norm = array_values($norm);
                }

                $out[$id] = [
                    'id' => $id,
                    'values' => is_array($norm) ? array_values($norm) : [],
                    'op' => $filterOp,
                    'meta' => $meta,
                ];
            }
        }

        // Legacy single field_id
        if (!count($out)) {
            $fieldId = (int)$request->get('field_id', 0);
            if ($fieldId > 0 && isset($fieldsById[$fieldId])) {
                $meta = $fieldsById[$fieldId];
                $type = (int)($meta['type'] ?? 0);
                $values = $this->parseFieldValues($request);
                $filterOp = $this->normalizeCfFilterOp($type, (string)$request->get('field_op', ''));
                if (in_array('__empty__', $values, true)) {
                    $filterOp = 'empty';
                    $values = [];
                }
                $out[$fieldId] = [
                    'id' => $fieldId,
                    'values' => $values,
                    'op' => $filterOp,
                    'meta' => $meta,
                ];
            }
        }

        return array_values($out);
    }

    /**
     * Default filter operator per CustomFields type.
     */
    protected function defaultCfFilterOp(int $type): string
    {
        switch ($type) {
            case 1:
                return 'is';
            case 3:
                return 'contains';
            case 4:
                return 'equals';
            case 5:
                return 'on';
            case 7:
            case 8:
                return 'contains_any';
            case 2:
            default:
                return 'contains';
        }
    }

    /**
     * Normalize / whitelist cf filter op for a field type. Missing op → type default.
     */
    protected function normalizeCfFilterOp(int $type, string $op): string
    {
        $op = strtolower(trim($op));
        $allowed = [
            1 => ['is', 'empty'],
            2 => ['is', 'contains', 'empty'],
            3 => ['contains', 'empty'],
            4 => ['equals', 'gt', 'lt', 'between', 'empty'],
            5 => ['on', 'before', 'after', 'between', 'empty'],
            7 => ['contains_any', 'empty'],
            8 => ['contains_any', 'empty'],
        ];
        $list = $allowed[$type] ?? ['contains', 'empty'];
        if ($op === '' || !in_array($op, $list, true)) {
            return $this->defaultCfFilterOp($type);
        }

        return $op;
    }

    /**
     * True when field has selectable dropdown/multiselect options (not autosuggest-only).
     */
    protected function cfHasRealOptions($selectedField): bool
    {
        if (!is_array($selectedField)) {
            return false;
        }
        $type = (int)($selectedField['type'] ?? 0);
        if (!in_array($type, [1, 8], true)) {
            return false;
        }
        $options = $selectedField['options'] ?? null;
        if (!is_array($options) || !count($options)) {
            return false;
        }
        $keys = array_keys($options);
        if (count($keys) === 1 && (string)$keys[0] === 'autosuggest') {
            return false;
        }

        return true;
    }

    /**
     * SQL expression to cast CF text value as number (driver-aware).
     */
    protected function cfNumericValueExpr(string $column = 'ccf.value'): string
    {
        $driver = null;
        try {
            $driver = DB::connection()->getDriverName();
        } catch (\Throwable $e) {
            $driver = null;
        }
        if ($driver === 'pgsql') {
            return "CAST(NULLIF(TRIM($column), '') AS NUMERIC)";
        }
        if ($driver === 'sqlite') {
            return "CAST(NULLIF(TRIM($column), '') AS REAL)";
        }

        return "CAST($column AS DECIMAL(20,6))";
    }

    /**
     * SQL expression for date compare as YYYY-MM-DD (driver-aware).
     */
    protected function cfDateValueExpr(string $column = 'ccf.value'): string
    {
        $driver = null;
        try {
            $driver = DB::connection()->getDriverName();
        } catch (\Throwable $e) {
            $driver = null;
        }
        if ($driver === 'pgsql') {
            return "SUBSTRING(TRIM($column) FROM 1 FOR 10)";
        }
        if ($driver === 'sqlite') {
            return "SUBSTR(TRIM($column), 1, 10)";
        }

        return "LEFT(TRIM($column), 10)";
    }

    /**
     * Match a token inside a comma-separated CF value (type 7/8).
     * MySQL: FIND_IN_SET; otherwise LIKE / equality fallback.
     *
     * @return array{sql:string,bindings:array}
     */
    protected function cfCsvContainsSql(string $column, string $token)
    {
        $driver = null;
        try {
            $driver = DB::connection()->getDriverName();
        } catch (\Throwable $e) {
            $driver = null;
        }
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            return [
                'sql' => "($column = ? OR $column LIKE ? OR $column LIKE ? OR $column LIKE ?)",
                'bindings' => [$token, $token.',%', '%,'.$token.',%', '%,'.$token],
            ];
        }

        return [
            'sql' => 'FIND_IN_SET(?, '.$column.')',
            'bindings' => [$token],
        ];
    }

    /**
     * AND together several custom-field filters.
     *
     * @param array $cfFilters
     * @param string $likeOp LIKE/ILIKE for query fallback
     * @return array{sql:string,bindings:array}
     */
    protected function buildMultiCustomFieldFiltersSql(array $cfFilters, string $q, string $likeOp)
    {
        $sql = '';
        $bindings = [];
        $n = count($cfFilters);
        foreach ($cfFilters as $filter) {
            $useQ = ($n === 1) ? $q : '';
            $part = $this->buildCustomFieldValueFilterSql(
                (int)$filter['id'],
                (array)($filter['values'] ?? []),
                $useQ,
                $likeOp,
                $filter['meta'] ?? null,
                (string)($filter['op'] ?? '')
            );
            $sql .= (string)($part['sql'] ?? '');
            $bindings = array_merge($bindings, (array)($part['bindings'] ?? []));
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    protected function parseFieldValues(Request $request)
    {
        $raw = $request->input('field_values', null);
        if ($raw === null) {
            $text = trim((string)$request->input('field_values_text', ''));
            if ($text !== '') {
                $raw = preg_split('/\s*,\s*/', $text) ?: [];
            } else {
                $legacy = $request->input('field_value', null);
                if ($legacy === null || $legacy === '') {
                    return [];
                }
                $legacy = trim((string)$legacy);
                if ($legacy === '') {
                    return [];
                }
                if (strpos($legacy, ',') !== false) {
                    $raw = preg_split('/\s*,\s*/', $legacy);
                } else {
                    $raw = [$legacy];
                }
            }
        }
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $out = [];
        foreach ($raw as $value) {
            $value = (string)$value;
            if ($value === '__empty__') {
                $out['__empty__'] = '__empty__';
                continue;
            }
            $value = trim($value);
            if ($value === '') {
                $out['__empty__'] = '__empty__';
                continue;
            }
            $out[$value] = $value;
        }

        return array_values($out);
    }

    /**
     * Build SQL fragment for multi-status OR filter.
     * Empty / [-1] => no filter.
     * Deleted (-10) uses c.state = deleted and ORs with normal statuses.
     *
     * @param array $statuses
     * @return array
     */
    protected function buildStatusFilterSql(array $statuses)
    {
        $normalized = [];
        $includeDeleted = false;
        $deletedCode = $this->deletedStatusFilterValue();

        foreach ($statuses as $status) {
            $status = (int)$status;
            if ($status === -1) {
                continue;
            }
            if ($status === $deletedCode) {
                $includeDeleted = true;
                continue;
            }
            $normalized[$status] = $status;
        }
        $normalized = array_values($normalized);

        if (!count($normalized) && !$includeDeleted) {
            return ['sql' => '', 'bindings' => []];
        }

        $deletedState = $this->deletedStateValue();

        if (!count($normalized) && $includeDeleted) {
            return [
                'sql' => ' AND c.state = ?',
                'bindings' => [$deletedState],
            ];
        }

        $in = implode(',', array_fill(0, count($normalized), '?'));
        if ($includeDeleted) {
            return [
                'sql' => " AND (c.status IN ($in) OR c.state = ?)",
                'bindings' => array_merge($normalized, [$deletedState]),
            ];
        }

        return [
            'sql' => " AND c.status IN ($in) AND (c.state IS NULL OR c.state <> ?)",
            'bindings' => array_merge($normalized, [$deletedState]),
        ];
    }

    /**
     * Build SQL for custom-field value filtering when a field_id is selected.
     * $likeOp = LIKE/ILIKE (query fallback); $filterOp = cf_filters[i][op].
     *
     * @param int $fieldId
     * @param array $fieldValues
     * @param string $q
     * @param string $likeOp
     * @param array|null $selectedField
     * @param string $filterOp
     * @return array
     */
    protected function buildCustomFieldValueFilterSql(int $fieldId, array $fieldValues, string $q, string $likeOp, $selectedField = null, string $filterOp = '')
    {
        if ($fieldId <= 0) {
            return ['sql' => '', 'bindings' => []];
        }

        if ($likeOp !== 'ILIKE' && $likeOp !== 'LIKE') {
            $likeOp = 'LIKE';
        }

        $fieldType = is_array($selectedField) ? (int)($selectedField['type'] ?? 0) : 0;
        $filterOp = $this->normalizeCfFilterOp($fieldType, $filterOp);
        $hasRealOptions = $this->cfHasRealOptions($selectedField);

        // op=empty → empty / not set (ignore values).
        if ($filterOp === 'empty') {
            return [
                'sql' => " AND (\n"
                    ."                    NOT EXISTS (\n"
                    ."                        SELECT 1 FROM conversation_custom_field ccf_e\n"
                    ."                        WHERE ccf_e.conversation_id = c.id\n"
                    ."                          AND ccf_e.custom_field_id = ?\n"
                    ."                          AND COALESCE(ccf_e.value, '') <> ''\n"
                    ."                    )\n"
                    ."                )",
                'bindings' => [$fieldId],
            ];
        }

        $includeEmpty = false;
        $values = [];
        foreach ($fieldValues as $value) {
            $value = (string)$value;
            if ($value === '__empty__' || trim($value) === '') {
                $includeEmpty = true;
                continue;
            }
            $values[] = $value;
        }

        if (!count($values) && !$includeEmpty) {
            if ($q !== '') {
                return [
                    'sql' => " AND EXISTS (\n"
                        ."                    SELECT 1 FROM conversation_custom_field ccf\n"
                        ."                    WHERE ccf.conversation_id = c.id\n"
                        ."                      AND ccf.custom_field_id = ?\n"
                        ."                      AND ccf.value $likeOp ?\n"
                        ."                )",
                    'bindings' => [$fieldId, '%'.$q.'%'],
                ];
            }

            return [
                'sql' => " AND EXISTS (\n"
                    ."                    SELECT 1 FROM conversation_custom_field ccf\n"
                    ."                    WHERE ccf.conversation_id = c.id\n"
                    ."                      AND ccf.custom_field_id = ?\n"
                    ."                )",
                'bindings' => [$fieldId],
            ];
        }

        $parts = [];
        $bindings = [];

        if (count($values)) {
            $valueSql = $this->buildCfOpValueMatchSql($fieldId, $fieldType, $filterOp, $values, $likeOp, $hasRealOptions);
            if (($valueSql['sql'] ?? '') !== '') {
                $parts[] = $valueSql['sql'];
                $bindings = array_merge($bindings, (array)($valueSql['bindings'] ?? []));
            }
        }

        if ($includeEmpty) {
            $parts[] = "(\n"
                ."                    NOT EXISTS (\n"
                ."                        SELECT 1 FROM conversation_custom_field ccf_e\n"
                ."                        WHERE ccf_e.conversation_id = c.id\n"
                ."                          AND ccf_e.custom_field_id = ?\n"
                ."                          AND COALESCE(ccf_e.value, '') <> ''\n"
                ."                    )\n"
                ."                )";
            $bindings[] = $fieldId;
        }

        if (!count($parts)) {
            return ['sql' => '', 'bindings' => []];
        }

        return [
            'sql' => ' AND ('.implode("\n                OR ", $parts).')',
            'bindings' => $bindings,
        ];
    }

    /**
     * Build EXISTS match for CF values honoring filter op + field type.
     *
     * @param array $values
     * @return array{sql:string,bindings:array}
     */
    protected function buildCfOpValueMatchSql(int $fieldId, int $fieldType, string $filterOp, array $values, string $likeOp, bool $hasRealOptions)
    {
        $existsOpen = "EXISTS (\n"
            ."                    SELECT 1 FROM conversation_custom_field ccf\n"
            ."                    WHERE ccf.conversation_id = c.id\n"
            ."                      AND ccf.custom_field_id = ?\n"
            ."                      AND ";
        $existsClose = "\n                )";

        // Number ops
        if ($fieldType === 4) {
            $numExpr = $this->cfNumericValueExpr('ccf.value');
            if ($filterOp === 'between') {
                $from = $values[0] ?? null;
                $to = $values[1] ?? null;
                if ($from === null || $to === null || $from === '' || $to === '') {
                    return ['sql' => '', 'bindings' => []];
                }
                return [
                    'sql' => $existsOpen."($numExpr >= ? AND $numExpr <= ?)".$existsClose,
                    'bindings' => [$fieldId, $from, $to],
                ];
            }
            $v = $values[0] ?? '';
            if ($v === '') {
                return ['sql' => '', 'bindings' => []];
            }
            if ($filterOp === 'gt') {
                return [
                    'sql' => $existsOpen."$numExpr > ?".$existsClose,
                    'bindings' => [$fieldId, $v],
                ];
            }
            if ($filterOp === 'lt') {
                return [
                    'sql' => $existsOpen."$numExpr < ?".$existsClose,
                    'bindings' => [$fieldId, $v],
                ];
            }
            // equals (default)
            return [
                'sql' => $existsOpen."$numExpr = ?".$existsClose,
                'bindings' => [$fieldId, $v],
            ];
        }

        // Date ops (compare as YYYY-MM-DD strings)
        if ($fieldType === 5) {
            $dateExpr = $this->cfDateValueExpr('ccf.value');
            if ($filterOp === 'between') {
                $from = $values[0] ?? null;
                $to = $values[1] ?? null;
                if ($from === null || $to === null || $from === '' || $to === '') {
                    return ['sql' => '', 'bindings' => []];
                }
                return [
                    'sql' => $existsOpen."($dateExpr >= ? AND $dateExpr <= ?)".$existsClose,
                    'bindings' => [$fieldId, $from, $to],
                ];
            }
            $v = $values[0] ?? '';
            if ($v === '') {
                return ['sql' => '', 'bindings' => []];
            }
            if ($filterOp === 'before') {
                return [
                    'sql' => $existsOpen."$dateExpr < ?".$existsClose,
                    'bindings' => [$fieldId, $v],
                ];
            }
            if ($filterOp === 'after') {
                return [
                    'sql' => $existsOpen."$dateExpr > ?".$existsClose,
                    'bindings' => [$fieldId, $v],
                ];
            }
            // on (default)
            return [
                'sql' => $existsOpen."$dateExpr = ?".$existsClose,
                'bindings' => [$fieldId, $v],
            ];
        }

        // Dropdown (type 1): is = multi-value OR on option keys
        if ($fieldType === 1 && ($filterOp === 'is' || $hasRealOptions)) {
            $in = implode(',', array_fill(0, count($values), '?'));
            return [
                'sql' => $existsOpen."ccf.value IN ($in)".$existsClose,
                'bindings' => array_merge([$fieldId], $values),
            ];
        }

        // Multiselect (type 8): contains_any on stored LABELS via FIND_IN_SET
        if ($fieldType === 8 || ($filterOp === 'contains_any' && $hasRealOptions && $fieldType !== 7)) {
            $orParts = [];
            $bindings = [$fieldId];
            foreach ($values as $token) {
                $csv = $this->cfCsvContainsSql('ccf.value', (string)$token);
                $orParts[] = $csv['sql'];
                $bindings = array_merge($bindings, $csv['bindings']);
            }
            return [
                'sql' => $existsOpen.'('.implode(' OR ', $orParts).')'.$existsClose,
                'bindings' => $bindings,
            ];
        }

        // Tags (type 7): contains_any — CSV membership OR
        if ($fieldType === 7 || $filterOp === 'contains_any') {
            $orParts = [];
            $bindings = [$fieldId];
            foreach ($values as $token) {
                $csv = $this->cfCsvContainsSql('ccf.value', (string)$token);
                $orParts[] = $csv['sql'];
                $bindings = array_merge($bindings, $csv['bindings']);
            }
            return [
                'sql' => $existsOpen.'('.implode(' OR ', $orParts).')'.$existsClose,
                'bindings' => $bindings,
            ];
        }

        // Text: is = exact equality
        if ($filterOp === 'is' || $filterOp === 'equals') {
            $eqParts = [];
            foreach ($values as $value) {
                $eqParts[] = 'ccf.value = ?';
            }
            return [
                'sql' => $existsOpen.'('.implode(' OR ', $eqParts).')'.$existsClose,
                'bindings' => array_merge([$fieldId], $values),
            ];
        }

        // Text: contains (default) — LIKE %v%
        $likeParts = [];
        $bindings = [$fieldId];
        foreach ($values as $value) {
            $likeParts[] = "ccf.value $likeOp ?";
            $bindings[] = '%'.$value.'%';
        }
        return [
            'sql' => $existsOpen.'('.implode(' OR ', $likeParts).')'.$existsClose,
            'bindings' => $bindings,
        ];
    }


    protected function deletedStatusFilterValue()
    {
        return -10;
    }

    protected function deletedStateValue()
    {
        $deletedState = 3;
        try {
            if (defined(Conversation::class.'::STATE_DELETED')) {
                $deletedState = (int)constant(Conversation::class.'::STATE_DELETED');
            }
        } catch (\Throwable $e) {
            $deletedState = 3;
        }

        return $deletedState;
    }

    protected function getConversationBadgeMeta(int $status, int $state = 0)
    {
        if ($this->isDeletedConversationState($state)) {
            return [
                'name' => (string)__('Deleted'),
                'class' => 'danger',
            ];
        }

        $statusName = '';
        $statusClass = 'default';
        try {
            $statusName = (string)Conversation::statusCodeToName($status);
            if (isset(Conversation::$status_classes[$status])) {
                $statusClass = (string)Conversation::$status_classes[$status];
            }
        } catch (\Throwable $e) {
            $statusName = '';
            $statusClass = 'default';
        }

        return [
            'name' => $statusName,
            'class' => $statusClass,
        ];
    }

    protected function isDeletedConversationState(int $state)
    {
        return $state > 0 && $state === $this->deletedStateValue();
    }

    protected function runSearch(string $countSql, string $sql, array $bindings)
    {
        try {
            $cntRow = DB::select($countSql, $bindings);
            $total = (int)($cntRow[0]->cnt ?? 0);

            $rows = DB::select($sql, $bindings);
            $results = [];
            foreach ($rows as $r) {
                $results[] = [
                    'id' => (int)$r->id,
                    'subject' => (string)$r->subject,
                    'mailbox_id' => (int)$r->mailbox_id,
                    'status' => (int)$r->status,
                    'state' => isset($r->state) ? (int)$r->state : 0,
                    'updated_at' => (string)$r->updated_at,
                ];
            }

            return [$results, $total];
        } catch (\Throwable $e) {
            // Fail-safe: return no results, never crash UI.
            try {
                \Helper::logException($e);
            } catch (\Throwable $ignored) {
                // no-op
            }
            return [[], 0];
        }
    }
}

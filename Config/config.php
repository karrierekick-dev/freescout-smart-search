<?php

return [
    // Show an always-visible input in the top navbar (left side).
    'show_topbar' => true,

    // Rewire the existing magnifier dropdown search form to use smart search.
    // Default: disabled (we inject our own compact topbar search bar).
    'use_core_search_icon' => false,

    // Hide the core magnifier icon.
    'hide_core_search_icon' => true,

    // Minimum query length.
    'min_query_len' => 2,

    // Results per page.
    'per_page' => 50,

    // Search published customer messages, agent replies, and internal notes.
    // Overridable via Manage → Settings → Smart Search (Option).
    'search_thread_body' => true,

    // Default filters for the form when Smart Search opens idle (no ss=1).
    // Listing (recent/search) runs only after form submit or with an explicit q.
    // Overridable via Manage → Settings → Smart Search.
    // FreeScout: 1=Active, 2=Pending, 3=Closed, 4=Spam; -10=Deleted (module).
    'default_statuses' => [1, 2],
    'default_mailbox_id' => 0,

    // Bulk action safety limits.
    'bulk_max_selected' => 200,
    'bulk_note_max_length' => 50000,

    // Cache minutes for table existence checks.
    'schema_cache_minutes' => 10,
];

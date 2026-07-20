<?php

namespace Modules\SmartSearch\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

if (!defined('SMART_SEARCH_MODULE')) {
    define('SMART_SEARCH_MODULE', 'smartsearch');
}

class SmartSearchServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'smartsearch');
    }

    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'smartsearch');
        // FreeScout convention: JSON files with English source keys (Resources/lang/de.json).
        $this->loadJsonTranslationsFrom(__DIR__.'/../Resources/lang');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

        $this->registerHooks();
    }

    protected function shouldInjectFrontendAssets()
    {
        try {
            if ($this->app->runningInConsole() || !Auth::check()) {
                return false;
            }

            $request = $this->app['request'];
            if (!$request || $request->ajax() || $request->isXmlHttpRequest()) {
                return false;
            }

            // Navbar/topbar behavior is intentionally available on every normal
            // authenticated page. The JavaScript decides whether to render it.
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function registerHooks()
    {
        \Eventy::addFilter('stylesheets', function ($styles) {
            if ($this->shouldInjectFrontendAssets()) {
                $path = \Module::getPublicPath(SMART_SEARCH_MODULE).'/css/module.css';
                // Skip missing files — FreeScout Minify aborts the whole stylesheet block on errors.
                $full = public_path(ltrim($path, '/'));
                if (is_file($full) && !in_array($path, $styles, true)) {
                    $styles[] = $path;
                }
            }

            return $styles;
        });

        \Eventy::addFilter('javascripts', function ($javascripts) {
            if ($this->shouldInjectFrontendAssets()) {
                $path = \Module::getPublicPath(SMART_SEARCH_MODULE).'/js/module.js';
                $full = public_path(ltrim($path, '/'));
                if (is_file($full) && !in_array($path, $javascripts, true)) {
                    $javascripts[] = $path;
                }
            }

            return $javascripts;
        });

        \Eventy::addAction('layout.body_bottom', function () {
            if ($this->shouldInjectFrontendAssets()) {
                echo \View::make('smartsearch::partials.config')->render();
            }
        }, 5);

        // JS strings for Lang.get('messages.…') — rebuilt with freescout:clear-cache / freescout:build.
        \Eventy::addAction('js.lang.messages', function () {
            ?>
                "ss_choose_field": "<?php echo __j('Choose field…'); ?>",
                "ss_loading_fields": "<?php echo __j('Loading fields…'); ?>",
                "ss_loading_recent": "<?php echo __j('Loading…'); ?>",
    "ss_field_values_hint": "<?php echo __j('Select one or more values (OR).'); ?>",
                "ss_field_values_text_placeholder": "<?php echo __j('Value1, Value2…'); ?>",
                "ss_any_status": "<?php echo __j('Any status'); ?>",
                "ss_show_more": "<?php echo __j('Show more options'); ?>",
                "ss_hide_more": "<?php echo __j('Hide more options'); ?>",
                "ss_operator": "<?php echo __j('Operator'); ?>",
                "ss_empty_not_set": "<?php echo __j('Empty / not set'); ?>",
                "ss_value_placeholder": "<?php echo __j('Value'); ?>",
                "ss_from": "<?php echo __j('From'); ?>",
                "ss_to": "<?php echo __j('To'); ?>",
                "ss_op_is": "<?php echo __j('is'); ?>",
                "ss_op_contains": "<?php echo __j('contains'); ?>",
                "ss_op_equals": "<?php echo __j('equals'); ?>",
                "ss_op_gt": "<?php echo __j('greater than'); ?>",
                "ss_op_lt": "<?php echo __j('less than'); ?>",
                "ss_op_between": "<?php echo __j('between'); ?>",
                "ss_op_on": "<?php echo __j('on'); ?>",
                "ss_op_before": "<?php echo __j('before'); ?>",
                "ss_op_after": "<?php echo __j('after'); ?>",
                "ss_op_contains_any": "<?php echo __j('contains any'); ?>",
                "ss_inline_placeholder": "<?php echo __j('Search…'); ?>",
                "ss_focus_search": "<?php echo __j('Focus search'); ?>",
                "ss_open_smart_search": "<?php echo __j('Open Smart Search'); ?>",
                "ss_search": "<?php echo __j('Search'); ?>",
                "ss_suggestions": "<?php echo __j('Suggestions'); ?>",
                "ss_search_smart_for": "<?php echo __j('Smart Search for “:q”'); ?>",
                "ss_enter": "<?php echo __j('Enter'); ?>",
                "ss_recent_searches": "<?php echo __j('Recent searches'); ?>",
            <?php
        });

        $this->registerSettingsHooks();
    }

    /**
     * Manage → Settings → Smart Search
     */
    protected function registerSettingsHooks()
    {
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['smartsearch'] = [
                'title' => __('Smart Search'),
                'icon' => 'search',
                'order' => 250,
            ];

            return $sections;
        }, 20);

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section !== 'smartsearch') {
                return $settings;
            }

            return \Option::getOptions([
                'smartsearch.default_statuses',
                'smartsearch.default_mailbox_id',
                'smartsearch.search_thread_body',
            ], [
                'smartsearch.default_statuses' => (array)config('smartsearch.default_statuses', [1, 2]),
                'smartsearch.default_mailbox_id' => (int)config('smartsearch.default_mailbox_id', 0),
                'smartsearch.search_thread_body' => (bool)config('smartsearch.search_thread_body', true),
            ]);
        }, 20, 2);

        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section !== 'smartsearch') {
                return $params;
            }

            $statusOptions = [];
            try {
                if (property_exists(\App\Conversation::class, 'statuses') && is_array(\App\Conversation::$statuses)) {
                    foreach (\App\Conversation::$statuses as $code => $name) {
                        $code = (int)$code;
                        if ($code <= 0) {
                            continue;
                        }
                        try {
                            $label = (string)\App\Conversation::statusCodeToName($code);
                        } catch (\Throwable $e) {
                            $label = (string)$name;
                        }
                        $statusOptions[$code] = $label !== '' ? $label : (string)$name;
                    }
                }
            } catch (\Throwable $e) {
                $statusOptions = [];
            }
            ksort($statusOptions);
            $statusOptions[-10] = (string)__('Deleted');

            $mailboxes = collect();
            try {
                $mailboxes = \App\Mailbox::orderBy('name')->get();
            } catch (\Throwable $e) {
                $mailboxes = collect();
            }

            return [
                'template_vars' => [
                    'statusOptions' => $statusOptions,
                    'mailboxes' => $mailboxes,
                ],
                'settings' => [
                    'smartsearch.default_statuses' => [
                        'default' => (array)config('smartsearch.default_statuses', [1, 2]),
                    ],
                    'smartsearch.default_mailbox_id' => [
                        'default' => (int)config('smartsearch.default_mailbox_id', 0),
                    ],
                    'smartsearch.search_thread_body' => [
                        'default' => (bool)config('smartsearch.search_thread_body', true),
                    ],
                ],
            ];
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            if ($section !== 'smartsearch') {
                return $view;
            }

            return 'smartsearch::settings';
        }, 20, 2);
    }
}

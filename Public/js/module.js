(function () {
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function () {
    try {
      var cfg = document.getElementById('smartsearch-config');
      var smartUrl = cfg ? (cfg.getAttribute('data-search-url') || '') : '';
      var suggestUrl = cfg ? (cfg.getAttribute('data-suggest-url') || '') : '';
      // Default to NOT touching core search if config node is missing.
      // (Fail-safe for unexpected layouts / older FreeScout versions.)
      var useCore = cfg ? (cfg.getAttribute('data-use-core') === '1') : false;
      var hideCore = cfg ? (cfg.getAttribute('data-hide-core') === '1') : false;
      var showInline = cfg ? (cfg.getAttribute('data-show-inline') === '1') : true;

      // Lightweight i18n (CSP-safe): strings are rendered server-side into data-* attrs.
      function getCfgAttr(name, fallback) {
        try {
          if (!cfg) return fallback;
          var v = cfg.getAttribute(name);
          if (typeof v !== 'string') return fallback;
          v = v.toString();
          return v ? v : fallback;
        } catch (e) {
          return fallback;
        }
      }

      function clearNode(node) {
        try {
          while (node && node.firstChild) {
            node.removeChild(node.firstChild);
          }
        } catch (e) {}
      }
      var fieldsUrl = cfg ? (cfg.getAttribute('data-fields-url') || '') : '';
      var recentMetaUrl = cfg ? (cfg.getAttribute('data-recent-meta-url') || '') : '';

      // Lightweight i18n: prefer FreeScout Lang.get (js.lang.messages), fallback to data-* attrs.
      function langMsg(key, fallback) {
        try {
          if (typeof Lang !== 'undefined' && typeof Lang.get === 'function') {
            var v = Lang.get('messages.' + key);
            if (typeof v === 'string' && v && v.indexOf('messages.') !== 0) {
              return v;
            }
          }
        } catch (e0) {}
        return fallback;
      }

      var I18N = {
        inlinePlaceholder: langMsg('ss_inline_placeholder', getCfgAttr('data-i18n-inline-placeholder', 'Search…')),
        focusSearch: langMsg('ss_focus_search', getCfgAttr('data-i18n-focus-search', 'Focus search')),
        opensmartsearch: langMsg('ss_open_smart_search', getCfgAttr('data-i18n-open-smart-search', 'Open Smart Search')),
        suggestions: langMsg('ss_suggestions', getCfgAttr('data-i18n-suggestions', 'Suggestions')),
        searchSmartFor: langMsg('ss_search_smart_for', getCfgAttr('data-i18n-search-smart-for', 'Search Smart Search for “:q”')),
        enter: langMsg('ss_enter', getCfgAttr('data-i18n-enter', 'Enter')),
        recentSearches: langMsg('ss_recent_searches', getCfgAttr('data-i18n-recent-searches', 'Recent searches')),
        search: langMsg('ss_search', getCfgAttr('data-i18n-search', 'Search')),
        chooseField: langMsg('ss_choose_field', getCfgAttr('data-i18n-choose-field', 'Choose field…')),
        loadingFields: langMsg('ss_loading_fields', getCfgAttr('data-i18n-loading-fields', 'Loading fields…')),
        loadingRecent: langMsg('ss_loading_recent', getCfgAttr('data-i18n-loading-recent', 'Loading…')),
        fieldValuesHint: langMsg('ss_field_values_hint', getCfgAttr('data-i18n-field-values-hint', 'Select one or more values (OR).')),
        fieldValuesTextPlaceholder: langMsg('ss_field_values_text_placeholder', getCfgAttr('data-i18n-field-values-text-placeholder', 'Value1, Value2…')),
        anyStatus: langMsg('ss_any_status', getCfgAttr('data-i18n-any-status', 'Any status')),
        showMore: langMsg('ss_show_more', getCfgAttr('data-i18n-show-more', 'Show more options')),
        hideMore: langMsg('ss_hide_more', getCfgAttr('data-i18n-hide-more', 'Hide more options')),
        operator: langMsg('ss_operator', getCfgAttr('data-i18n-operator', 'Operator')),
        emptyNotSet: langMsg('ss_empty_not_set', getCfgAttr('data-i18n-empty-not-set', 'Empty / not set')),
        valuePlaceholder: langMsg('ss_value_placeholder', getCfgAttr('data-i18n-value-placeholder', 'Value')),
        fromLabel: langMsg('ss_from', getCfgAttr('data-i18n-from', 'From')),
        toLabel: langMsg('ss_to', getCfgAttr('data-i18n-to', 'To')),
        opIs: langMsg('ss_op_is', getCfgAttr('data-i18n-op-is', 'is')),
        opContains: langMsg('ss_op_contains', getCfgAttr('data-i18n-op-contains', 'contains')),
        opEquals: langMsg('ss_op_equals', getCfgAttr('data-i18n-op-equals', 'equals')),
        opGt: langMsg('ss_op_gt', getCfgAttr('data-i18n-op-gt', 'greater than')),
        opLt: langMsg('ss_op_lt', getCfgAttr('data-i18n-op-lt', 'less than')),
        opBetween: langMsg('ss_op_between', getCfgAttr('data-i18n-op-between', 'between')),
        opOn: langMsg('ss_op_on', getCfgAttr('data-i18n-op-on', 'on')),
        opBefore: langMsg('ss_op_before', getCfgAttr('data-i18n-op-before', 'before')),
        opAfter: langMsg('ss_op_after', getCfgAttr('data-i18n-op-after', 'after')),
        opContainsAny: langMsg('ss_op_contains_any', getCfgAttr('data-i18n-op-contains-any', 'contains any'))
      };

      function formatSearchSmartFor(q) {
        try {
          if (typeof Lang !== 'undefined' && typeof Lang.get === 'function') {
            var v = Lang.get('messages.ss_search_smart_for', { q: q });
            if (typeof v === 'string' && v && v.indexOf('messages.') !== 0) {
              return v;
            }
          }
        } catch (e1) {}
        return (I18N.searchSmartFor || '').replace(':q', q);
      }

      function bindsmartsearchMailboxFields() {
        try {
          var form = document.querySelector('form.smartsearch-form');
          if (!form) return;

          var grid = form.querySelector('.smartsearch-filter-grid');
          var fieldsUrlLocal = fieldsUrl;
          var fieldsCache = [];
          var openTilePanel = null;
          try {
            var fieldsHost = form.querySelector('.smartsearch-cf-rows[data-fields]')
              || form.querySelector('[data-fields]')
              || grid;
            if (fieldsHost && fieldsHost.getAttribute('data-fields')) {
              fieldsCache = JSON.parse(fieldsHost.getAttribute('data-fields') || '[]') || [];
            }
          } catch (e0) {
            fieldsCache = [];
          }

          function resetTilePanelStyle(panel) {
            if (!panel) return;
            panel.style.position = '';
            panel.style.left = '';
            panel.style.right = '';
            panel.style.top = '';
            panel.style.bottom = '';
            panel.style.width = '';
            panel.style.maxWidth = '';
            panel.style.maxHeight = '';
            panel.style.zIndex = '';
          }

          function placeTilePanel(tile, panel) {
            if (!tile || !panel) return;
            var btn = tile.querySelector('.smartsearch-tile-btn') || tile;
            var rect = btn.getBoundingClientRect();
            var vpW = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
            var vpH = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
            var margin = 12;
            var gap = 6;
            var isCf = tile.classList.contains('smartsearch-tile-cf');
            var width = Math.round(rect.width);
            if (isCf) {
              width = Math.max(width, Math.min(420, vpW - (margin * 2)));
            }
            width = Math.min(width, vpW - (margin * 2));
            var left = Math.round(rect.left);
            if (left + width > vpW - margin) {
              left = Math.max(margin, vpW - margin - width);
            }
            if (left < margin) left = margin;

            // Measure preferred height with temporary visibility.
            panel.style.position = 'fixed';
            panel.style.left = left + 'px';
            panel.style.right = 'auto';
            panel.style.width = width + 'px';
            panel.style.maxWidth = (vpW - (margin * 2)) + 'px';
            panel.style.top = '-9999px';
            panel.style.bottom = 'auto';
            panel.style.maxHeight = '';
            panel.hidden = false;
            var panelH = Math.ceil(panel.getBoundingClientRect().height) || 240;
            var spaceBelow = vpH - rect.bottom - margin;
            var spaceAbove = rect.top - margin;
            var openUp = spaceBelow < Math.min(panelH + gap, 280) && spaceAbove > spaceBelow;
            var maxH = Math.max(160, openUp ? spaceAbove - gap : spaceBelow - gap);
            panel.style.maxHeight = maxH + 'px';
            panel.style.overflowY = 'auto';
            panel.style.zIndex = '10050';
            if (openUp) {
              panel.style.top = 'auto';
              panel.style.bottom = Math.max(margin, vpH - rect.top + gap) + 'px';
            } else {
              panel.style.bottom = 'auto';
              panel.style.top = Math.round(rect.bottom + gap) + 'px';
            }
            openTilePanel = panel;
          }

          function closeAllTiles(except) {
            var tiles = form.querySelectorAll('.smartsearch-tile');
            var i;
            for (i = 0; i < tiles.length; i++) {
              if (except && tiles[i] === except) continue;
              tiles[i].classList.remove('is-open');
              var btn = tiles[i].querySelector('.smartsearch-tile-btn');
              var panel = tiles[i].querySelector('.smartsearch-tile-panel');
              if (btn) btn.setAttribute('aria-expanded', 'false');
              if (panel) {
                panel.hidden = true;
                resetTilePanelStyle(panel);
                panel.style.overflowY = '';
              }
            }
            if (!except) openTilePanel = null;
          }

          function repositionOpenTile() {
            var tile = form.querySelector('.smartsearch-tile.is-open');
            if (!tile) return;
            var panel = tile.querySelector('.smartsearch-tile-panel');
            if (!panel || panel.hidden) return;
            placeTilePanel(tile, panel);
          }

          function bindTiles() {
            var tiles = form.querySelectorAll('.smartsearch-tile');
            var i;
            for (i = 0; i < tiles.length; i++) {
              (function (tile) {
                var btn = tile.querySelector('.smartsearch-tile-btn');
                var panel = tile.querySelector('.smartsearch-tile-panel');
                if (!btn || !panel) return;
                btn.addEventListener('click', function (ev) {
                  ev.preventDefault();
                  ev.stopPropagation();
                  var open = tile.classList.contains('is-open');
                  closeAllTiles(null);
                  if (!open) {
                    tile.classList.add('is-open');
                    btn.setAttribute('aria-expanded', 'true');
                    placeTilePanel(tile, panel);
                  }
                });
                panel.addEventListener('click', function (ev) {
                  ev.stopPropagation();
                });
              })(tiles[i]);
            }
            document.addEventListener('click', function () {
              closeAllTiles(null);
            });
            window.addEventListener('resize', repositionOpenTile);
            window.addEventListener('scroll', repositionOpenTile, true);
          }

          function selectedStatusLabels() {
            var checks = form.querySelectorAll('.smartsearch-status-checks input[type="checkbox"]:checked');
            var out = [];
            var i;
            for (i = 0; i < checks.length; i++) {
              var lab = checks[i].parentNode ? checks[i].parentNode.textContent.replace(/^\s+|\s+$/g, '') : checks[i].value;
              out.push(lab);
            }
            return out;
          }

          function refreshStatusPills() {
            var host = form.querySelector('.smartsearch-status-pills');
            if (!host) return;
            var labels = selectedStatusLabels();
            clearNode(host);
            if (!labels.length) {
              var muted = document.createElement('span');
              muted.className = 'smartsearch-muted';
              muted.appendChild(document.createTextNode(I18N.anyStatus || 'Any status'));
              host.appendChild(muted);
              return;
            }
            var i;
            for (i = 0; i < labels.length; i++) {
              var pill = document.createElement('span');
              pill.className = 'smartsearch-pill';
              pill.appendChild(document.createTextNode(labels[i]));
              host.appendChild(pill);
            }
          }

          function refreshSelectTile(name, labelSelector) {
            var checked = form.querySelector('input[type="radio"][name="' + name + '"]:checked');
            var label = form.querySelector(labelSelector);
            if (!label) return;
            if (!checked) {
              label.textContent = '';
              return;
            }
            var lab = checked.parentNode ? checked.parentNode.textContent.replace(/^\s+|\s+$/g, '') : checked.value;
            label.textContent = lab;
          }

          function selectedRadioValue(name, fallback) {
            var checked = form.querySelector('input[type="radio"][name="' + name + '"]:checked');
            return checked ? (checked.value || fallback || '0') : (fallback || '0');
          }

          function bindOptionList(fieldName, labelSelector, onChange) {
            var list = form.querySelector('.smartsearch-option-list[data-tile-field="' + fieldName + '"]');
            if (!list) return;
            list.addEventListener('change', function (ev) {
              var t = ev.target;
              if (!t || t.name !== fieldName) return;
              refreshSelectTile(fieldName, labelSelector);
              closeAllTiles(null);
              if (typeof onChange === 'function') onChange();
            });
          }

          function cfOpLabel(op) {
            var map = {
              is: I18N.opIs || 'is',
              contains: I18N.opContains || 'contains',
              equals: I18N.opEquals || 'equals',
              gt: I18N.opGt || 'greater than',
              lt: I18N.opLt || 'less than',
              between: I18N.opBetween || 'between',
              on: I18N.opOn || 'on',
              before: I18N.opBefore || 'before',
              after: I18N.opAfter || 'after',
              contains_any: I18N.opContainsAny || 'contains any',
              empty: I18N.emptyNotSet || 'Empty / not set'
            };
            return map[op] || op;
          }

          function cfOpBrief(op) {
            if (op === 'is' || op === 'equals' || op === 'on') return '=';
            if (op === 'gt') return '>';
            if (op === 'lt') return '<';
            return cfOpLabel(op);
          }

          function defaultCfOp(type) {
            type = parseInt(type, 10) || 0;
            if (type === 1) return 'is';
            if (type === 3) return 'contains';
            if (type === 4) return 'equals';
            if (type === 5) return 'on';
            if (type === 7 || type === 8) return 'contains_any';
            return 'contains';
          }

          function opsForType(type) {
            type = parseInt(type, 10) || 0;
            if (type === 1) return ['is', 'empty'];
            if (type === 2) return ['is', 'contains', 'empty'];
            if (type === 3) return ['contains', 'empty'];
            if (type === 4) return ['equals', 'gt', 'lt', 'between', 'empty'];
            if (type === 5) return ['on', 'before', 'after', 'between', 'empty'];
            if (type === 7) return ['contains_any', 'empty'];
            if (type === 8) return ['contains_any', 'empty'];
            return ['contains', 'empty'];
          }

          function cfHasRealOptions(field) {
            if (!field) return false;
            var type = parseInt(field.type, 10) || 0;
            if (type !== 1 && type !== 8) return false;
            var options = field.options && typeof field.options === 'object' ? field.options : null;
            if (!options) return false;
            var keys = Object.keys(options);
            if (!keys.length) return false;
            if (keys.length === 1 && String(keys[0]) === 'autosuggest') return false;
            return true;
          }

          function refreshCfSummary() {
            var host = form.querySelector('.smartsearch-cf-summary');
            var rows = form.querySelectorAll('.smartsearch-cf-row');
            if (!host) return;
            clearNode(host);
            var pills = [];
            var i;
            for (i = 0; i < rows.length; i++) {
              var row = rows[i];
              var sel = row.querySelector('select.smartsearch-cf-id');
              if (!sel || !sel.value || sel.value === '0') continue;
              var opt = sel.options[sel.selectedIndex];
              var fieldName = opt ? String(opt.text || '').replace(/^\s+|\s+$/g, '') : '';
              if (!fieldName) continue;

              var field = fieldById(sel.value);
              var type = field ? (parseInt(field.type, 10) || 0) : (parseInt(row.getAttribute('data-type'), 10) || 0);
              var opSel = row.querySelector('select.smartsearch-cf-op');
              var op = opSel && opSel.value ? String(opSel.value) : defaultCfOp(type);

              if (op === 'empty') {
                pills.push(fieldName + ' ' + cfOpLabel('empty'));
                continue;
              }

              var valueLabels = [];
              var checks = row.querySelectorAll('.smartsearch-cf-value-ui input[type="checkbox"]:checked');
              var ci;
              for (ci = 0; ci < checks.length; ci++) {
                var labNode = checks[ci].parentNode;
                var labText = labNode ? String(labNode.textContent || '').replace(/^\s+|\s+$/g, '') : '';
                if (labText) valueLabels.push(labText);
              }
              var textInputs = row.querySelectorAll('.smartsearch-cf-value-ui input[type="text"], .smartsearch-cf-value-ui input[type="number"], .smartsearch-cf-value-ui input[type="date"]');
              var ti;
              for (ti = 0; ti < textInputs.length; ti++) {
                var raw = String(textInputs[ti].value || '').replace(/^\s+|\s+$/g, '');
                if (!raw) continue;
                if (type === 7 && textInputs[ti].type === 'text') {
                  var parts = raw.split(/\s*,\s*/);
                  var pi;
                  for (pi = 0; pi < parts.length; pi++) {
                    var part = String(parts[pi] || '').replace(/^\s+|\s+$/g, '');
                    if (part) valueLabels.push(part);
                  }
                } else {
                  valueLabels.push(raw);
                }
              }

              var pillText = fieldName;
              if (valueLabels.length) {
                var brief = cfOpBrief(op);
                if (op === 'between' && valueLabels.length >= 2) {
                  pillText += ' ' + brief + ' ' + valueLabels[0] + '–' + valueLabels[1];
                } else {
                  pillText += ' ' + brief + ' ' + valueLabels.join(' | ');
                }
              }
              pills.push(pillText);
            }
            if (!pills.length) {
              var muted = document.createElement('span');
              muted.className = 'smartsearch-muted';
              muted.appendChild(document.createTextNode('—'));
              host.appendChild(muted);
              return;
            }
            for (i = 0; i < pills.length; i++) {
              var pill = document.createElement('span');
              pill.className = 'smartsearch-pill';
              pill.appendChild(document.createTextNode(pills[i]));
              host.appendChild(pill);
            }
          }

          function fieldById(id) {
            var i;
            id = parseInt(id, 10) || 0;
            for (i = 0; i < fieldsCache.length; i++) {
              if (parseInt(fieldsCache[i].id, 10) === id) return fieldsCache[i];
            }
            return null;
          }

          function rebuildCfValueUi(row, preferredOp) {
            var sel = row.querySelector('select.smartsearch-cf-id');
            var valuesHost = row.querySelector('.smartsearch-cf-values');
            if (!sel || !valuesHost) return;
            var idx = row.getAttribute('data-index') || '0';
            var id = sel.value || '0';
            var valueUi = valuesHost.querySelector('.smartsearch-cf-value-ui');
            var opSel = valuesHost.querySelector('select.smartsearch-cf-op');
            if (!valueUi || !opSel) return;

            var field = fieldById(id);
            var type = field ? (parseInt(field.type, 10) || 0) : 0;
            var op = preferredOp || opSel.value || defaultCfOp(type);
            var allowed = opsForType(type);
            if (allowed.indexOf(op) < 0) op = defaultCfOp(type);
            opSel.value = op;

            clearNode(valueUi);
            if (op === 'empty') {
              valueUi.style.display = 'none';
              refreshCfSummary();
              repositionOpenTile();
              return;
            }
            valueUi.style.display = '';

            var hasOpts = cfHasRealOptions(field);
            var options = hasOpts && field.options ? field.options : null;

            if (hasOpts && (op === 'is' || op === 'contains_any') && options) {
              var list = document.createElement('div');
              list.className = 'smartsearch-check-list';
              var key;
              for (key in options) {
                if (!Object.prototype.hasOwnProperty.call(options, key)) continue;
                if (String(key) === 'autosuggest') continue;
                var lab = document.createElement('label');
                lab.className = 'checkbox';
                var inp = document.createElement('input');
                inp.type = 'checkbox';
                inp.name = 'cf_filters[' + idx + '][values][]';
                // Type 1 stores keys; type 8 stores labels.
                inp.value = (type === 8) ? String(options[key]) : String(key);
                lab.appendChild(inp);
                lab.appendChild(document.createTextNode(' ' + String(options[key])));
                list.appendChild(lab);
              }
              valueUi.appendChild(list);
            } else if (type === 4) {
              if (op === 'between') {
                var wrapN = document.createElement('div');
                wrapN.className = 'smartsearch-cf-between';
                var fromN = document.createElement('input');
                fromN.type = 'number';
                fromN.step = 'any';
                fromN.className = 'form-control';
                fromN.name = 'cf_filters[' + idx + '][values][]';
                fromN.placeholder = I18N.fromLabel || 'From';
                var sepN = document.createElement('span');
                sepN.className = 'smartsearch-cf-between-sep';
                sepN.appendChild(document.createTextNode('–'));
                var toN = document.createElement('input');
                toN.type = 'number';
                toN.step = 'any';
                toN.className = 'form-control';
                toN.name = 'cf_filters[' + idx + '][values][]';
                toN.placeholder = I18N.toLabel || 'To';
                wrapN.appendChild(fromN);
                wrapN.appendChild(sepN);
                wrapN.appendChild(toN);
                valueUi.appendChild(wrapN);
              } else {
                var numInp = document.createElement('input');
                numInp.type = 'number';
                numInp.step = 'any';
                numInp.className = 'form-control';
                numInp.name = 'cf_filters[' + idx + '][values][]';
                numInp.placeholder = I18N.valuePlaceholder || 'Value';
                valueUi.appendChild(numInp);
              }
            } else if (type === 5) {
              if (op === 'between') {
                var wrapD = document.createElement('div');
                wrapD.className = 'smartsearch-cf-between';
                var fromD = document.createElement('input');
                fromD.type = 'date';
                fromD.className = 'form-control';
                fromD.name = 'cf_filters[' + idx + '][values][]';
                var sepD = document.createElement('span');
                sepD.className = 'smartsearch-cf-between-sep';
                sepD.appendChild(document.createTextNode('–'));
                var toD = document.createElement('input');
                toD.type = 'date';
                toD.className = 'form-control';
                toD.name = 'cf_filters[' + idx + '][values][]';
                wrapD.appendChild(fromD);
                wrapD.appendChild(sepD);
                wrapD.appendChild(toD);
                valueUi.appendChild(wrapD);
              } else {
                var dateInp = document.createElement('input');
                dateInp.type = 'date';
                dateInp.className = 'form-control';
                dateInp.name = 'cf_filters[' + idx + '][values][]';
                valueUi.appendChild(dateInp);
              }
            } else if (type === 7) {
              var tagInp = document.createElement('input');
              tagInp.type = 'text';
              tagInp.className = 'form-control';
              tagInp.name = 'cf_filters[' + idx + '][values][]';
              tagInp.placeholder = I18N.fieldValuesTextPlaceholder || 'Value1, Value2…';
              valueUi.appendChild(tagInp);
            } else {
              // Type 2/3 text (+ autosuggest-only options for type 2)
              var textInp = document.createElement('input');
              textInp.type = 'text';
              textInp.className = 'form-control';
              textInp.name = 'cf_filters[' + idx + '][values][]';
              textInp.placeholder = I18N.valuePlaceholder || 'Value';
              valueUi.appendChild(textInp);
            }
            refreshCfSummary();
            repositionOpenTile();
          }

          function collectCfRowState(row) {
            var state = { op: '', values: [] };
            if (!row) return state;
            var opSel = row.querySelector('select.smartsearch-cf-op');
            if (opSel && opSel.value) state.op = String(opSel.value);
            var checks = row.querySelectorAll('.smartsearch-cf-value-ui input[type="checkbox"]:checked');
            var i;
            for (i = 0; i < checks.length; i++) {
              state.values.push(String(checks[i].value || ''));
            }
            var inputs = row.querySelectorAll('.smartsearch-cf-value-ui input[type="text"], .smartsearch-cf-value-ui input[type="number"], .smartsearch-cf-value-ui input[type="date"]');
            for (i = 0; i < inputs.length; i++) {
              state.values.push(String(inputs[i].value || ''));
            }
            return state;
          }

          function applyCfRowState(row, state) {
            if (!row || !state) return;
            var opSel = row.querySelector('select.smartsearch-cf-op');
            if (opSel && state.op) {
              var opts = opSel.options;
              var found = false;
              var i;
              for (i = 0; i < opts.length; i++) {
                if (opts[i].value === state.op) { found = true; break; }
              }
              if (found) {
                opSel.value = state.op;
                rebuildCfValueUi(row, state.op);
              }
            }
            var vals = state.values || [];
            if (!vals.length) {
              refreshCfSummary();
              return;
            }
            var checks = row.querySelectorAll('.smartsearch-cf-value-ui input[type="checkbox"]');
            for (i = 0; i < checks.length; i++) {
              if (vals.indexOf(String(checks[i].value || '')) >= 0) {
                checks[i].checked = true;
              }
            }
            var inputs = row.querySelectorAll('.smartsearch-cf-value-ui input[type="text"], .smartsearch-cf-value-ui input[type="number"], .smartsearch-cf-value-ui input[type="date"]');
            for (i = 0; i < inputs.length; i++) {
              if (typeof vals[i] !== 'undefined') {
                inputs[i].value = vals[i];
              }
            }
            refreshCfSummary();
          }

          function rebuildCfValues(row, preserveState) {
            var prev = preserveState ? collectCfRowState(row) : null;
            var sel = row.querySelector('select.smartsearch-cf-id');
            var valuesHost = row.querySelector('.smartsearch-cf-values');
            if (!sel || !valuesHost) return;
            var idx = row.getAttribute('data-index') || '0';
            var id = sel.value || '0';
            clearNode(valuesHost);
            if (!id || id === '0') {
              valuesHost.style.display = 'none';
              row.setAttribute('data-type', '0');
              refreshCfSummary();
              return;
            }
            valuesHost.style.display = '';
            var field = fieldById(id);
            var type = field ? (parseInt(field.type, 10) || 0) : 0;
            row.setAttribute('data-type', String(type));

            var opWrap = document.createElement('div');
            opWrap.className = 'smartsearch-cf-op-wrap';
            var opLab = document.createElement('label');
            opLab.className = 'smartsearch-cf-op-label';
            opLab.appendChild(document.createTextNode(I18N.operator || 'Operator'));
            var opSel = document.createElement('select');
            opSel.className = 'form-control smartsearch-cf-op';
            opSel.name = 'cf_filters[' + idx + '][op]';
            var allowed = opsForType(type);
            var defOp = (prev && prev.op && allowed.indexOf(prev.op) >= 0) ? prev.op : defaultCfOp(type);
            var ai;
            for (ai = 0; ai < allowed.length; ai++) {
              var o = document.createElement('option');
              o.value = allowed[ai];
              o.appendChild(document.createTextNode(cfOpLabel(allowed[ai])));
              if (allowed[ai] === defOp) o.selected = true;
              opSel.appendChild(o);
            }
            opWrap.appendChild(opLab);
            opWrap.appendChild(opSel);
            valuesHost.appendChild(opWrap);

            var valueUi = document.createElement('div');
            valueUi.className = 'smartsearch-cf-value-ui';
            valuesHost.appendChild(valueUi);

            opSel.addEventListener('change', function () {
              rebuildCfValueUi(row, opSel.value);
            });

            rebuildCfValueUi(row, defOp);
            if (prev) applyCfRowState(row, prev);
          }

          function reindexCfRows() {
            var rows = form.querySelectorAll('.smartsearch-cf-row');
            var i;
            for (i = 0; i < rows.length; i++) {
              rows[i].setAttribute('data-index', String(i));
              var sel = rows[i].querySelector('select.smartsearch-cf-id');
              if (sel) sel.name = 'cf_filters[' + i + '][id]';
              var inputs = rows[i].querySelectorAll('[name^="cf_filters["]');
              var j;
              for (j = 0; j < inputs.length; j++) {
                var n = inputs[j].name || '';
                if (n.indexOf('[id]') !== -1) inputs[j].name = 'cf_filters[' + i + '][id]';
                else if (n.indexOf('[op]') !== -1) inputs[j].name = 'cf_filters[' + i + '][op]';
                else if (n.indexOf('[values]') !== -1) inputs[j].name = 'cf_filters[' + i + '][values][]';
              }
            }
          }

          function bindCfRow(row) {
            var sel = row.querySelector('select.smartsearch-cf-id');
            var removeBtn = row.querySelector('.smartsearch-cf-remove');
            var valuesHost = row.querySelector('.smartsearch-cf-values');
            if (sel) {
              sel.addEventListener('change', function () {
                rebuildCfValues(row);
              });
            }
            if (valuesHost && !valuesHost.getAttribute('data-bound-summary')) {
              valuesHost.setAttribute('data-bound-summary', '1');
              valuesHost.addEventListener('change', function (ev) {
                var t = ev && ev.target ? ev.target : null;
                if (t && t.classList && t.classList.contains('smartsearch-cf-op')) {
                  rebuildCfValueUi(row, t.value);
                  return;
                }
                refreshCfSummary();
              });
              valuesHost.addEventListener('input', refreshCfSummary);
            }
            if (removeBtn) {
              removeBtn.addEventListener('click', function () {
                var rows = form.querySelectorAll('.smartsearch-cf-row');
                if (rows.length <= 1) {
                  if (sel) sel.value = '0';
                  rebuildCfValues(row);
                  return;
                }
                row.parentNode.removeChild(row);
                reindexCfRows();
                refreshCfSummary();
              });
            }
          }

          function addCfRow() {
            var wrap = form.querySelector('.smartsearch-cf-rows');
            if (!wrap) return;
            var rows = wrap.querySelectorAll('.smartsearch-cf-row');
            var template = rows[0];
            if (!template) return;
            var clone = template.cloneNode(true);
            var sel = clone.querySelector('select.smartsearch-cf-id');
            if (sel) sel.value = '0';
            var opSel = clone.querySelector('select.smartsearch-cf-op');
            if (opSel) opSel.value = '';
            var values = clone.querySelector('.smartsearch-cf-values');
            if (values) {
              clearNode(values);
              values.style.display = 'none';
              values.removeAttribute('data-bound-summary');
            }
            clone.setAttribute('data-type', '0');
            wrap.appendChild(clone);
            reindexCfRows();
            bindCfRow(clone);
            refreshCfSummary();
            repositionOpenTile();
          }

          function bindMoreToggle() {
            var btn = form.querySelector('.smartsearch-more-toggle');
            var wrap = form.querySelector('.smartsearch-more-wrap');
            if (!btn || !wrap) return;
            var label = btn.querySelector('.smartsearch-more-toggle-label');
            btn.addEventListener('click', function () {
              var expanded = btn.getAttribute('data-expanded') === '1';
              expanded = !expanded;
              btn.setAttribute('data-expanded', expanded ? '1' : '0');
              btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
              if (label) {
                label.textContent = expanded ? (I18N.hideMore || 'Hide more options') : (I18N.showMore || 'Show more options');
              }
              wrap.setAttribute('aria-hidden', expanded ? 'false' : 'true');
              if (expanded) {
                wrap.classList.remove('is-collapsed');
              } else {
                closeAllTiles(null);
                wrap.classList.add('is-collapsed');
              }
            });
          }

          function fetchFields() {
            if (!fieldsUrlLocal) return;
            var mailboxId = selectedRadioValue('mailbox_id', '0');
            var xhr = new XMLHttpRequest();
            xhr.open('GET', fieldsUrlLocal + '?mailbox_id=' + encodeURIComponent(mailboxId), true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Cache-Control', 'no-cache');
            xhr.onreadystatechange = function () {
              if (xhr.readyState !== 4) return;
              var payload = {};
              try { payload = JSON.parse(xhr.responseText || '{}'); } catch (e) { payload = {}; }
              fieldsCache = (payload && payload.fields) ? payload.fields : [];
              // Refresh option lists in existing CF rows (keep selected ids).
              var rows = form.querySelectorAll('.smartsearch-cf-row');
              var i;
              for (i = 0; i < rows.length; i++) {
                var sel = rows[i].querySelector('select.smartsearch-cf-id');
                if (!sel) continue;
                var selected = sel.value || '0';
                while (sel.firstChild) sel.removeChild(sel.firstChild);
                var empty = document.createElement('option');
                empty.value = '0';
                empty.appendChild(document.createTextNode(I18N.chooseField || 'Choose field…'));
                sel.appendChild(empty);
                var j;
                for (j = 0; j < fieldsCache.length; j++) {
                  var opt = document.createElement('option');
                  opt.value = String(fieldsCache[j].id || 0);
                  opt.setAttribute('data-type', String(fieldsCache[j].type || 0));
                  opt.appendChild(document.createTextNode(String(fieldsCache[j].name || '')));
                  if (opt.value === selected) opt.selected = true;
                  sel.appendChild(opt);
                }
                if (!sel.value) sel.value = '0';
                rebuildCfValues(rows[i], true);
              }
              refreshCfSummary();
            };
            xhr.send(null);
          }

          bindTiles();
          bindMoreToggle();

          var statusChecks = form.querySelector('.smartsearch-status-checks');
          if (statusChecks) {
            statusChecks.addEventListener('change', refreshStatusPills);
          }
          bindOptionList('mailbox_id', '.smartsearch-tile-mailbox-label', fetchFields);
          bindOptionList('assignee_id', '.smartsearch-tile-assignee-label');
          bindOptionList('folder_id', '.smartsearch-tile-folder-label');
          bindOptionList('sort', '.smartsearch-tile-sort-label');

          var cfRows = form.querySelectorAll('.smartsearch-cf-row');
          var ri;
          for (ri = 0; ri < cfRows.length; ri++) bindCfRow(cfRows[ri]);
          var addBtn = form.querySelector('.smartsearch-cf-add');
          if (addBtn) addBtn.addEventListener('click', addCfRow);

          // On submit, drop empty CF rows (id=0) so they don't pollute the query.
          form.addEventListener('submit', function () {
            var rows = form.querySelectorAll('.smartsearch-cf-row');
            var i;
            for (i = 0; i < rows.length; i++) {
              var sel = rows[i].querySelector('select.smartsearch-cf-id');
              if (sel && (!sel.value || sel.value === '0')) {
                var inputs = rows[i].querySelectorAll('input, select');
                var j;
                for (j = 0; j < inputs.length; j++) inputs[j].disabled = true;
              }
            }
          });

          refreshStatusPills();
          refreshCfSummary();
          if (fieldsUrlLocal) fetchFields();
        } catch (e) {
          // Never break the Smart Search page.
        }
      }

      function isTypingTarget(el) {
        if (!el) return false;
        var tag = (el.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable;
      }

      function debounce(fn, wait) {
        var t;
        return function () {
          var args = arguments;
          clearTimeout(t);
          t = setTimeout(function () {
            fn.apply(null, args);
          }, wait);
        };
      }

      // Last 5 searches (per browser) — simple, private, and useful.
      // v2 stores structured items so we can enrich "recent searches" when we
      // *know* a query led to a real conversation (Option A: confirmed-valid only).
      // Backward compatible with v1 (array of strings).
      var HISTORY_KEY = 'smart_search_recent_v2';

      function normalizeHistory(arr) {
        try {
          if (!Array.isArray(arr)) return [];
          var out = [];
          for (var i = 0; i < arr.length; i++) {
            var it = arr[i];
            if (!it) continue;
            // v1: string
            if (typeof it === 'string') {
              var qs = it.toString().trim();
              if (!qs) continue;
              out.push({ q: qs, ts: null, kind: 'q' });
              continue;
            }
            // v2: object
            if (typeof it === 'object') {
              var q = (it.q || '').toString().trim();
              if (!q) continue;
              out.push({
                q: q,
                ts: (typeof it.ts === 'number' ? it.ts : null),
                kind: (it.kind || 'q'),
                conv: it.conv || null,
                refreshed_at: (typeof it.refreshed_at === 'number' ? it.refreshed_at : null)
              });
            }
          }
          return out;
        } catch (e) {
          return [];
        }
      }

      function loadHistory() {
        try {
          if (!window.localStorage) return [];
          var raw = window.localStorage.getItem(HISTORY_KEY);
          // Also read legacy key if present.
          if (!raw) {
            raw = window.localStorage.getItem('smart_search_recent_v1') || window.localStorage.getItem('adam_smart_search_ui_recent_v2') || window.localStorage.getItem('adam_smart_search_ui_recent_v1');
          }
          if (!raw) return [];
          var arr = JSON.parse(raw);
          return normalizeHistory(arr);
        } catch (e) {
          return [];
        }
      }

      function saveHistory(arr) {
        try {
          if (!window.localStorage) return;
          window.localStorage.setItem(HISTORY_KEY, JSON.stringify(arr || []));
        } catch (e) {}
      }

      function rememberHistory(item) {
        try {
          if (!item) return;
          var q = (item.q || '').toString().trim();
          if (!q || q.length < 2) return;

          var arr = loadHistory();
          var ql = q.toLowerCase();

          // Prepend new item, dedupe by query (case-insensitive).
          var next = [];
          next.push({
            q: q,
            ts: (typeof item.ts === 'number' ? item.ts : Date.now()),
            kind: item.kind || 'q',
            conv: item.conv || null,
            refreshed_at: (typeof item.refreshed_at === 'number' ? item.refreshed_at : null)
          });
          for (var i = 0; i < arr.length; i++) {
            var it = arr[i];
            if (!it || !it.q) continue;
            if (it.q.toLowerCase() === ql) continue;
            next.push(it);
            if (next.length >= 5) break;
          }
          saveHistory(next.slice(0, 5));
        } catch (e) {}
      }

      function rememberQuery(q) {
        rememberHistory({ q: q, kind: 'q', ts: Date.now(), conv: null });
      }

      var historyRefreshInFlight = false;

      function isRefreshableHistoryConversation(it) {
        try {
          return !!(it && it.kind === 'conv' && it.conv && it.conv.id);
        } catch (e) {
          return false;
        }
      }

      function refreshHistoryConversations(done) {
        try {
          if (historyRefreshInFlight || !recentMetaUrl || !window.localStorage) {
            if (typeof done === 'function') done(false);
            return;
          }

          var arr = loadHistory();
          if (!arr || !arr.length) {
            if (typeof done === 'function') done(false);
            return;
          }

          var ids = [];
          var touched = false;
          for (var i = 0; i < arr.length; i++) {
            var it = arr[i];
            if (!isRefreshableHistoryConversation(it)) {
              continue;
            }
            touched = true;
            var convId = parseInt((it.conv && it.conv.id) || 0, 10);
            if (convId > 0 && ids.indexOf(convId) < 0) {
              ids.push(convId);
            }
          }

          if (!touched || !ids.length) {
            if (typeof done === 'function') done(false);
            return;
          }

          historyRefreshInFlight = true;
          var xhr = new XMLHttpRequest();
          var cacheBust = 'ts=' + encodeURIComponent(String(Date.now()));
          var joiner = recentMetaUrl.indexOf('?') >= 0 ? '&' : '?';
          xhr.open('GET', recentMetaUrl + joiner + 'ids=' + encodeURIComponent(ids.join(',')) + '&' + cacheBust, true);
          xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
          xhr.setRequestHeader('Cache-Control', 'no-cache');
          xhr.onreadystatechange = function () {
            var payload = {};
            var byId = {};
            var changed = false;
            var nowTs = Date.now();

            if (xhr.readyState !== 4) {
              return;
            }

            historyRefreshInFlight = false;

            if (xhr.status < 200 || xhr.status >= 300) {
              if (typeof done === 'function') done(false);
              return;
            }

            try {
              payload = JSON.parse(xhr.responseText || '{}');
            } catch (e) {
              payload = {};
            }

            var items = (payload && payload.items && payload.items.length) ? payload.items : [];
            for (var j = 0; j < items.length; j++) {
              var meta = items[j] || {};
              var metaId = parseInt(meta.id || 0, 10);
              if (metaId > 0) {
                byId[metaId] = meta;
              }
            }

            for (var k = 0; k < arr.length; k++) {
              var hist = arr[k];
              if (!hist || hist.kind !== 'conv' || !hist.conv || !hist.conv.id) {
                continue;
              }
              var histId = parseInt(hist.conv.id || 0, 10);
              if (histId <= 0 || !isRefreshableHistoryConversation(hist)) {
                continue;
              }

              hist.refreshed_at = nowTs;
              if (byId[histId]) {
                hist.conv = {
                  id: byId[histId].id || hist.conv.id || null,
                  url: byId[histId].url || hist.conv.url || null,
                  subject: byId[histId].subject || hist.conv.subject || hist.q || '',
                  mailbox_name: byId[histId].mailbox_name || '',
                  updated_human: byId[histId].updated_human || '',
                  status_name: byId[histId].status_name || '',
                  status_class: byId[histId].status_class || ''
                };
                changed = true;
              }
            }

            if (changed) {
              saveHistory(arr);
            }
            if (typeof done === 'function') done(changed);
          };
          xhr.send(null);
        } catch (e) {
          historyRefreshInFlight = false;
          if (typeof done === 'function') done(false);
        }
      }

      function rememberConversationFromSuggest(q, it) {
        try {
          if (!it) {
            rememberQuery(q);
            return;
          }
          rememberHistory({
            q: q,
            kind: 'conv',
            ts: Date.now(),
            conv: {
              id: it.id || null,
              url: it.url || null,
              subject: it.subject || '',
              mailbox_name: it.mailbox_name || '',
              updated_human: it.updated_human || '',
              status_name: it.status_name || '',
              status_class: it.status_class || ''
            },
            refreshed_at: Date.now()
          });
        } catch (e) {
          rememberQuery(q);
        }
      }

      bindsmartsearchMailboxFields();

      function ensureInlineTopbarSearch() {
        try {
          if (!showInline || !smartUrl) {
            return;
          }

          // Insert next to the notifications icon (right side navbar)
          var navRight = document.querySelector('ul.nav.navbar-nav.navbar-right');
          if (!navRight) {
            return;
          }

          // Avoid duplicates
          if (document.getElementById('smartsearch-inline-li')) {
            return;
          }

          var notifLi = navRight.querySelector('li.web-notifications');
          var navCollapse = document.querySelector('.navbar-collapse');
          var navMobileTop = navCollapse ? navCollapse.querySelector('ul.nav.navbar-nav') : null;
          if (!navMobileTop) {
            navMobileTop = navRight;
          }

          var li = document.createElement('li');
          li.id = 'smartsearch-inline-li';
          li.className = 'smartsearch-inline';

          // Bootstrap/FreeScout-friendly inline form
          (function buildInlineForm() {
            var form = document.createElement('form');
            var group = document.createElement('div');
            var input = document.createElement('input');
            var btnWrap = document.createElement('span');
            var searchButton = document.createElement('button');
            var searchIcon = document.createElement('i');
            var moreButton = document.createElement('button');
            var moreIcon = document.createElement('i');
            var hiddenSubmit = document.createElement('button');

            form.className = 'navbar-form smartsearch-inline-form';
            form.setAttribute('role', 'search');
            form.setAttribute('method', 'GET');
            form.setAttribute('action', smartUrl);

            group.className = 'input-group input-group-sm';

            input.type = 'text';
            input.className = 'form-control smartsearch-inline-input';
            input.name = 'q';
            input.autocomplete = 'off';
            input.placeholder = I18N.inlinePlaceholder;

            btnWrap.className = 'input-group-btn';

            searchButton.className = 'btn btn-default smartsearch-search-btn';
            searchButton.type = 'button';
            searchButton.setAttribute('aria-label', I18N.focusSearch);
            searchButton.setAttribute('title', I18N.focusSearch);
            searchIcon.className = 'glyphicon glyphicon-search';
            searchButton.appendChild(searchIcon);

            moreButton.className = 'btn btn-default smartsearch-more';
            moreButton.type = 'button';
            moreButton.setAttribute('aria-label', I18N.opensmartsearch);
            moreButton.setAttribute('title', I18N.opensmartsearch);
            moreIcon.className = 'glyphicon glyphicon-option-vertical';
            moreButton.appendChild(moreIcon);

            hiddenSubmit.type = 'submit';
            hiddenSubmit.className = 'hidden';
            hiddenSubmit.setAttribute('tabindex', '-1');
            hiddenSubmit.setAttribute('aria-hidden', 'true');

            btnWrap.appendChild(searchButton);
            btnWrap.appendChild(moreButton);
            group.appendChild(input);
            group.appendChild(btnWrap);
            form.appendChild(group);
            form.appendChild(hiddenSubmit);
            li.appendChild(form);
          })();

          function isMobileTopbarLayout() {
            try {
              return window.innerWidth <= 767;
            } catch (e) {
              return false;
            }
          }

          function placeInlineSearchItem() {
            try {
              if (isMobileTopbarLayout()) {
                // In the collapsed/mobile navbar, keep Smart Search at the very top
                // of the menu, above mailbox/manage links and before the right-side items.
                if (navMobileTop.firstChild !== li) {
                  navMobileTop.insertBefore(li, navMobileTop.firstChild);
                }
                return;
              }

              if (notifLi && notifLi.parentNode === navRight) {
                // Desktop: keep it beside notifications, matching the normal topbar flow.
                if (notifLi.nextSibling !== li) {
                  navRight.insertBefore(li, notifLi.nextSibling);
                }
              } else if (navRight.firstChild !== li) {
                // Fallback: prepend to right nav.
                navRight.insertBefore(li, navRight.firstChild);
              }
            } catch (e) {
              try { navRight.insertBefore(li, navRight.firstChild); } catch (e2) {}
            }
          }

          placeInlineSearchItem();

          // Autosuggest dropdown (lightweight).
          try {
            var formEl = li.querySelector('form.smartsearch-inline-form');
            var inputEl = li.querySelector('input.smartsearch-inline-input');
            var moreBtn = li.querySelector('button.smartsearch-more');
            var searchBtn = li.querySelector('button.smartsearch-search-btn');
            if (formEl && inputEl) {
              // "More" opens the Smart Search page only (no q/ss — idle form, no search).
              if (moreBtn) {
                moreBtn.addEventListener('click', function (ev) {
                  try { ev.preventDefault(); } catch (e) {}
                  window.location.href = smartUrl;
                });
              }

              // Attach dropdown to <body> instead of the navbar/form.
              // This avoids dashboard/navbar overflow clipping on mobile and custom themes.
              var dd = document.createElement('div');
              dd.className = 'dropdown-menu smartsearch-suggest smartsearch-suggest-expandable';
              dd.style.display = 'none';
              dd.setAttribute('role', 'listbox');
              document.body.appendChild(dd);

              function getViewportSize() {
                return {
                  w: Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0),
                  h: Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0)
                };
              }

              function positionDd() {
                try {
                  var rect = formEl.getBoundingClientRect();
                  var anchorEl = formEl.querySelector('.input-group') || formEl;
                  var anchorRect = anchorEl.getBoundingClientRect();
                  var vp = getViewportSize();
                  var isMobile = isMobileTopbarLayout();
                  var margin = isMobile ? 12 : 12;
                  var gap = isMobile ? 3 : 6;
                  var baseWidth = Math.round(anchorRect.width || rect.width || 260);
                  var expandedWidth = 560;
                  var left = Math.round(anchorRect.left || rect.left || margin);
                  var maxBelow = Math.max(isMobile ? 120 : 160, vp.h - rect.bottom - margin);

                  if (dd.classList) {
                    if (isMobile) {
                      dd.classList.add('smartsearch-suggest-mobile');
                    } else {
                      dd.classList.remove('smartsearch-suggest-mobile');
                    }
                  }

                  if (isMobile) {
                    var mobileMaxWidth = Math.max(220, vp.w - (margin * 2));
                    baseWidth = Math.max(220, Math.min(baseWidth, mobileMaxWidth));
                    expandedWidth = baseWidth;
                    left = Math.max(margin, Math.min(left, vp.w - baseWidth - margin));
                    dd.style.left = left + 'px';
                    dd.style.right = 'auto';
                  } else {
                    var maxWidth = Math.max(260, vp.w - (margin * 2));
                    baseWidth = Math.max(260, Math.min(baseWidth, maxWidth));
                    expandedWidth = Math.max(baseWidth, Math.min(expandedWidth, maxWidth));

                    // Anchor the right edge to the input group so desktop hover expansion
                    // grows left, not off-screen to the right. This keeps the body-level
                    // portal dropdown expandable even though it is positioned outside the navbar.
                    var rightEdge = Math.round(anchorRect.right || rect.right || (left + baseWidth));
                    rightEdge = Math.min(rightEdge, vp.w - margin);
                    if (rightEdge - expandedWidth < margin) {
                      rightEdge = Math.min(vp.w - margin, margin + expandedWidth);
                    }

                    dd.style.left = 'auto';
                    dd.style.right = Math.max(margin, vp.w - rightEdge) + 'px';
                  }

                  dd.style.position = 'fixed';
                  dd.style.top = Math.round(rect.bottom + gap) + 'px';
                  dd.style.removeProperty('width');
                  dd.style.setProperty('--smartsearch-suggest-width', baseWidth + 'px');
                  dd.style.setProperty('--smartsearch-suggest-expanded-width', expandedWidth + 'px');
                  dd.style.maxHeight = Math.min(isMobile ? Math.floor(vp.h * 0.42) : 320, maxBelow) + 'px';
                } catch (e) {}
              }

              function showDd() {
                positionDd();
                dd.style.display = 'block';
              }

              // Magnifier is intentionally NOT a navigation affordance.
              if (searchBtn) {
                searchBtn.addEventListener('click', function (ev) {
                  try { ev.preventDefault(); } catch (e) {}
                  try { inputEl.focus(); } catch (e) {}
                });
              }

              var activeIndex = -1;
              var lastItems = [];
              var abortCtl = null;

              function setActive(idx) {
                var links = dd.querySelectorAll('.smartsearch-suggest-item');
                for (var j = 0; j < links.length; j++) {
                  if (j === idx) links[j].classList.add('active');
                  else links[j].classList.remove('active');
                }
                activeIndex = idx;
              }


              function hideDd() {
                dd.style.display = 'none';
                clearNode(dd);
                activeIndex = -1;
                lastItems = [];
                if (abortCtl) { try { abortCtl.abort(); } catch(e) {} }
                abortCtl = null;
              }

              function appendText(parent, text) {
                parent.appendChild(document.createTextNode((text || '').toString()));
              }

              function appendHighlightedText(parent, text, rawQuery) {
                try {
                  text = (text || '').toString();
                  rawQuery = (rawQuery || '').toString().trim();
                  if (!rawQuery || rawQuery.length < 2) {
                    appendText(parent, text);
                    return;
                  }
                  var q = rawQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                  var re = new RegExp(q, 'ig');
                  var lastIndex = 0;
                  var match;
                  while ((match = re.exec(text)) !== null) {
                    if (match.index > lastIndex) {
                      appendText(parent, text.slice(lastIndex, match.index));
                    }
                    var hl = document.createElement('span');
                    hl.className = 'smartsearch-hl';
                    appendText(hl, match[0]);
                    parent.appendChild(hl);
                    lastIndex = match.index + match[0].length;
                    if (match[0].length === 0) {
                      break;
                    }
                  }
                  if (lastIndex < text.length) {
                    appendText(parent, text.slice(lastIndex));
                  }
                } catch (e) {
                  appendText(parent, text);
                }
              }

              function buildSuggestItem(opts) {
                var link = document.createElement('a');
                var num = document.createElement('span');
                var main = document.createElement('span');
                var row1 = document.createElement('span');
                var subj = document.createElement('span');

                link.className = 'smartsearch-suggest-item' + (opts.extraClass ? (' ' + opts.extraClass) : '');
                link.setAttribute('data-kind', opts.kind || 'conv');
                link.setAttribute('href', (opts.href || '').toString());
                if (typeof opts.idx === 'number') {
                  link.setAttribute('data-idx', String(opts.idx));
                }

                num.className = 'smartsearch-suggest-num';
                if (opts.numIconClass) {
                  var icon = document.createElement('i');
                  icon.className = opts.numIconClass;
                  num.appendChild(icon);
                } else {
                  appendText(num, opts.numText || '');
                }

                main.className = 'smartsearch-suggest-main';
                row1.className = 'smartsearch-suggest-row1';
                subj.className = 'smartsearch-suggest-subj';
                if (opts.highlight) {
                  appendHighlightedText(subj, opts.subjectText || '', opts.highlightQuery || '');
                } else {
                  appendText(subj, opts.subjectText || '');
                }
                row1.appendChild(subj);

                if (opts.statusName) {
                  var safeStatusClass = ((opts.statusClass || 'default').toString().replace(/[^a-z0-9_-]/ig, '') || 'default');
                  var badge = document.createElement('span');
                  badge.className = 'label label-' + safeStatusClass + ' smartsearch-suggest-status';
                  appendText(badge, opts.statusName);
                  row1.appendChild(badge);
                }

                if (opts.hintText) {
                  var hint = document.createElement('span');
                  hint.className = 'smartsearch-suggest-hint';
                  appendText(hint, opts.hintText);
                  row1.appendChild(hint);
                }

                main.appendChild(row1);

                if (opts.metaText) {
                  var row2 = document.createElement('span');
                  row2.className = 'smartsearch-suggest-row2';
                  appendText(row2, opts.metaText);
                  main.appendChild(row2);
                }

                link.appendChild(num);
                link.appendChild(main);
                return link;
              }

              function appendSeparator(parent) {
                var sep = document.createElement('div');
                sep.className = 'smartsearch-suggest-sep';
                parent.appendChild(sep);
              }

              function appendHeading(parent, text) {
                var head = document.createElement('div');
                head.className = 'smartsearch-suggest-head';
                appendText(head, text || '');
                parent.appendChild(head);
              }

              function renderDd(items) {
                items = items || [];
                var qNow = (inputEl && inputEl.value) ? (inputEl.value || '') : '';
                var qTrim = (qNow || '').toString().trim();
                var frag = document.createDocumentFragment();
                var any = false;

                clearNode(dd);
                appendHeading(frag, I18N.suggestions || 'Suggestions');

                if (smartUrl && qTrim) {
                  frag.appendChild(buildSuggestItem({
                    kind: 'searchall',
                    href: smartUrl + (smartUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(qTrim),
                    extraClass: 'smartsearch-suggest-searchall',
                    numIconClass: 'glyphicon glyphicon-search',
                    subjectText: formatSearchSmartFor(qTrim),
                    highlight: true,
                    highlightQuery: qNow,
                    hintText: I18N.enter || 'Enter'
                  }));
                  appendSeparator(frag);
                }

                for (var i = 0; i < items.length; i++) {
                  var it = items[i] || {};
                  var url = (it.url || '').toString();
                  if (!url) {
                    continue;
                  }
                  any = true;
                  var mb = (it.mailbox_name || '').toString();
                  var upd = (it.updated_human || '').toString();
                  var meta = '';
                  if (mb && upd) meta = mb + ' • ' + upd;
                  else if (mb) meta = mb;
                  else if (upd) meta = upd;

                  frag.appendChild(buildSuggestItem({
                    kind: 'conv',
                    idx: i,
                    href: url,
                    numText: '#' + (it.id || '').toString(),
                    subjectText: (it.subject || '').toString(),
                    highlight: true,
                    highlightQuery: qNow,
                    statusName: (it.status_name || '').toString(),
                    statusClass: (it.status_class || '').toString(),
                    metaText: meta
                  }));
                }

                if (!any && !(smartUrl && qTrim)) {
                  hideDd();
                  return;
                }
                dd.appendChild(frag);
                showDd();
                lastItems = items || [];
                setActive(-1);
              }

              function renderHistoryList(arr) {
                arr = arr || [];
                if (!arr.length || !smartUrl) {
                  hideDd();
                  return;
                }
                clearNode(dd);
                var frag = document.createDocumentFragment();
                appendHeading(frag, I18N.recentSearches || 'Recent searches');
                appendSeparator(frag);
                var any = false;
                for (var i = 0; i < arr.length; i++) {
                  var hi = arr[i] || null;
                  var q = hi && hi.q ? hi.q.toString() : '';
                  if (!q) {
                    continue;
                  }
                  any = true;
                  var href = smartUrl + (smartUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
                  var conv = (hi && hi.kind === 'conv' && hi.conv) ? hi.conv : null;
                  if (conv && conv.url) {
                    var mb = (conv.mailbox_name || '').toString();
                    var upd = (conv.updated_human || '').toString();
                    var meta = '';
                    if (mb && upd) meta = mb + ' • ' + upd;
                    else if (mb) meta = mb;
                    else if (upd) meta = upd;
                    frag.appendChild(buildSuggestItem({
                      kind: 'history-conv',
                      href: (conv.url || href).toString(),
                      numText: '#' + (conv.id || '').toString(),
                      subjectText: (conv.subject || q).toString(),
                      statusName: (conv.status_name || '').toString(),
                      statusClass: (conv.status_class || '').toString(),
                      metaText: meta
                    }));
                  } else {
                    frag.appendChild(buildSuggestItem({
                      kind: 'history-q',
                      href: href,
                      subjectText: q
                    }));
                  }
                }
                if (!any) {
                  hideDd();
                  return;
                }
                dd.appendChild(frag);
                showDd();
                lastItems = [];
                setActive(-1);
              }

              function renderHistoryLoading() {
                clearNode(dd);
                var frag = document.createDocumentFragment();
                appendHeading(frag, I18N.recentSearches || 'Recent searches');
                appendSeparator(frag);
                frag.appendChild(buildSuggestItem({
                  kind: 'history-loading',
                  href: '#',
                  numIconClass: 'glyphicon glyphicon-refresh',
                  subjectText: I18N.loadingRecent || 'Loading…',
                  extraClass: 'disabled'
                }));
                dd.appendChild(frag);
                showDd();
                lastItems = [];
                setActive(-1);
              }

              function renderHistory() {
                try {
                  var arr = loadHistory();
                  if (!arr || !arr.length || !smartUrl) {
                    hideDd();
                    return;
                  }

                  var hasRefreshable = false;
                  for (var i = 0; i < arr.length; i++) {
                    if (isRefreshableHistoryConversation(arr[i])) {
                      hasRefreshable = true;
                      break;
                    }
                  }

                  if (!hasRefreshable || !recentMetaUrl) {
                    renderHistoryList(arr);
                    return;
                  }

                  renderHistoryLoading();
                  refreshHistoryConversations(function () {
                    try {
                      var qCurrent = ((inputEl && inputEl.value) || '').toString().trim();
                      if (qCurrent || document.activeElement !== inputEl) {
                        hideDd();
                        return;
                      }
                      renderHistoryList(loadHistory());
                    } catch (e) {
                      hideDd();
                    }
                  });
                } catch (e) {
                  hideDd();
                }
              }

              var fetchSuggest = debounce(function () {
                try {
                  var q = (inputEl.value || '').trim();
                  // Empty input: show last 5 searches.
                  if (!q) {
                    renderHistory();
                    return;
                  }
                  // Too short: hide dropdown (keep it simple).
                  if (q.length < 2 || !suggestUrl) {
                    hideDd();
                    return;
                  }
                  var url = suggestUrl + (suggestUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
                  if (abortCtl) { try { abortCtl.abort(); } catch(e) {} }
                  abortCtl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
                  // Prefer fetch(), but fall back to jQuery if needed (older browsers).
                  if (typeof fetch === 'function') {
                    fetch(url, {
                      credentials: 'same-origin',
                      headers: { 'X-Requested-With': 'XMLHttpRequest' },
                      signal: abortCtl ? abortCtl.signal : undefined
                    })
                      .then(function (r) {
                        return r.json();
                      })
                      .then(function (data) {
                        renderDd((data && data.items) || []);
                      })
                      .catch(function () {
                        hideDd();
                      });
                  } else if (window.jQuery && typeof window.jQuery.getJSON === 'function') {
                    window.jQuery.getJSON(url)
                      .done(function (data) {
                        renderDd((data && data.items) || []);
                      })
                      .fail(function () {
                        hideDd();
                      });
                  } else {
                    hideDd();
                  }
                } catch (e) {
                  hideDd();
                }
              }, 180);

              inputEl.addEventListener('input', fetchSuggest);
              inputEl.addEventListener('focus', function () {
                // Re-render if user focuses back in.
                fetchSuggest();
              });

              // Store history on submit.
              formEl.addEventListener('submit', function () {
                try { rememberQuery(inputEl.value || ''); } catch (e) {}
              });

              inputEl.addEventListener('keydown', function (ev) {
                if (!ev) return;
                // ESC closes suggestions.
                if (ev.key === 'Escape') {
                  hideDd();
                  return;
                }
                if (dd.style.display !== 'block') return;

                var links = dd.querySelectorAll('.smartsearch-suggest-item');
                if (!links || !links.length) return;

                if (ev.key === 'ArrowDown') {
                  ev.preventDefault();
                  var next = activeIndex + 1;
                  if (next >= links.length) next = 0;
                  setActive(next);
                  return;
                }
                if (ev.key === 'ArrowUp') {
                  ev.preventDefault();
                  var prev = activeIndex - 1;
                  if (prev < 0) prev = links.length - 1;
                  setActive(prev);
                  return;
                }
                if (ev.key === 'Enter' && activeIndex >= 0) {
                  ev.preventDefault();
                  try {
                    var qNow2 = (inputEl.value || '').toString();
                    var kind2 = links[activeIndex].getAttribute('data-kind') || '';
                    if (kind2 === 'conv') {
                      var idx2 = parseInt(links[activeIndex].getAttribute('data-idx') || '-1', 10);
                      if (!isNaN(idx2) && idx2 >= 0 && lastItems && lastItems[idx2]) {
                        rememberConversationFromSuggest(qNow2, lastItems[idx2]);
                      } else {
                        rememberQuery(qNow2);
                      }
                    } else {
                      rememberQuery(qNow2);
                    }
                    window.location.href = links[activeIndex].getAttribute('href');
                  } catch(e) {}
                }
              });

              // Robust selection: navigate on mousedown so blur/hide can't cancel the click.
              // (Some FreeScout layouts re-render/hide navbar elements on blur/click.)
              dd.addEventListener('mousedown', function (ev) {
                try {
                  var a = ev && ev.target && ev.target.closest ? ev.target.closest('a.smartsearch-suggest-item') : null;
                  if (!a) {
                    // Still remember typed query when interacting with dropdown.
                    try { rememberQuery(inputEl.value || ''); } catch (e) {}
                    return;
                  }
                  ev.preventDefault();
                  ev.stopPropagation();
                  try {
                    var qNow3 = (inputEl.value || '').toString();
                    var kind = a.getAttribute('data-kind') || '';
                    if (kind === 'conv') {
                      var idx = parseInt(a.getAttribute('data-idx') || '-1', 10);
                      if (!isNaN(idx) && idx >= 0 && lastItems && lastItems[idx]) {
                        rememberConversationFromSuggest(qNow3, lastItems[idx]);
                      } else {
                        rememberQuery(qNow3);
                      }
                    } else {
                      rememberQuery(qNow3);
                    }
                  } catch (e) {
                    try { rememberQuery(inputEl.value || ''); } catch (e2) {}
                  }
                  var href = a.getAttribute('href');
                  if (href) {
                    window.location.href = href;
                  }
                } catch (e) {}
              });
              // Click-away hide
              document.addEventListener('click', function (ev) {
                try {
                  if (!li.contains(ev.target) && !dd.contains(ev.target)) {
                    hideDd();
                  }
                } catch (e) {}
              });
              // Slight blur delay so interactions inside dropdown don't instantly close it.
              inputEl.addEventListener('blur', function () {
                setTimeout(function () {
                  try {
                    // If focus moved into the dropdown, keep it open.
                    var ae = document.activeElement;
                    if (ae && dd.contains(ae)) {
                      return;
                    }
                  } catch (e) {}
                  hideDd();
                }, 200);
              });

              window.addEventListener('resize', function () {
                try {
                  placeInlineSearchItem();
                  if (dd.style.display === 'block') {
                    positionDd();
                  }
                } catch (e) {}
              });

              window.addEventListener('scroll', function () {
                try {
                  if (dd.style.display === 'block') {
                    positionDd();
                  }
                } catch (e) {}
              }, true);
            }
          } catch (e) {
            // no-op
          }
        } catch (e) {
          // no-op
        }
      }

      // Core magnifier dropdown form
      var form = document.querySelector('form.form-nav-search');
      if (form) {
        if (hideCore) {
          // Hide the core magnifier icon/trigger when explicitly configured.
          var iconHide = document.getElementById('search-dt');
          if (iconHide) {
            iconHide.style.display = 'none';
            // Also hide the closest <li> if present (varies by FreeScout version/theme).
            try {
              var li2 = iconHide.closest && iconHide.closest('li');
              if (li2) li2.style.display = 'none';
            } catch (e) {}
          }
        }

        if (useCore) {
          if (smartUrl) {
            form.setAttribute('action', smartUrl);
          }
          form.removeAttribute('target');

          var input = form.querySelector('input[name="q"]');
          if (input) {
            input.setAttribute('placeholder', I18N.inlinePlaceholder || 'Search...');
          }
          var btn = form.querySelector('button[type="submit"]');
          if (btn) {
            btn.textContent = I18N.search || 'Search';
          }
        }
      }

      // Add the always-visible inline bar (right side).
      ensureInlineTopbarSearch();

      // Keyboard shortcut: '/' focuses the topbar search (like GitHub, Slack, etc.)
      document.addEventListener('keydown', function (ev) {
        try {
          if (!ev || ev.defaultPrevented) return;
          var isSlash = (ev.key === '/');
          var isCtrlK = (ev.key && (ev.key.toLowerCase ? ev.key.toLowerCase() : ev.key) === 'k') && (ev.ctrlKey || ev.metaKey);
          if (!isSlash && !isCtrlK) return;
          if (isSlash && (ev.metaKey || ev.ctrlKey || ev.altKey)) return;
          if (isTypingTarget(ev.target)) return;

          var inlineInput = document.querySelector('#smartsearch-inline-li input.smartsearch-inline-input');
          if (inlineInput) {
            ev.preventDefault();
            inlineInput.focus();
            inlineInput.select();
          }
        } catch (e) {}
      });

      // If user clicks the magnifier icon, focus the input.
      var icon = document.getElementById('search-dt');
      if (icon && form && useCore) {
        icon.addEventListener('click', function () {
          setTimeout(function () {
            try {
              var input = form.querySelector('input[name="q"]');
              if (input) input.focus();
            } catch (e) {}
          }, 50);
        });
      }
    } catch (e) {
      // never break UI
    }
  });
})();

(function () {
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function () {
    try {
      var form = document.getElementById('smartsearch-bulk-form');
      if (!form) {
        return;
      }

      var actionSelect = form.querySelector('.smartsearch-bulk-action');
      var assignTarget = form.querySelector('.smartsearch-bulk-target-assign');
      var statusTarget = form.querySelector('.smartsearch-bulk-target-status');
      var noteTarget = form.querySelector('.smartsearch-bulk-target-note');
      var checkAll = form.querySelector('.smartsearch-check-all');
      var countNode = form.querySelector('.smartsearch-bulk-count');
      var submitButton = form.querySelector('.smartsearch-bulk-submit');
      var countTemplate = form.getAttribute('data-count-template') || '__COUNT__ selected';

      function rowChecks() {
        return form.querySelectorAll('.smartsearch-row-check:not(:disabled)');
      }

      function setTarget(target, show) {
        if (!target) {
          return;
        }
        target.disabled = !show;
        target.style.display = show ? 'inline-block' : 'none';
      }

      function trimValue(value) {
        return String(value || '').replace(/^\s+|\s+$/g, '');
      }

      function selectedCount() {
        var checks = rowChecks();
        var count = 0;
        for (var i = 0; i < checks.length; i++) {
          if (checks[i].checked) {
            count++;
          }
        }
        return count;
      }

      function syncState() {
        var checks = rowChecks();
        var count = selectedCount();
        var action = actionSelect ? (actionSelect.value || '') : '';

        if (countNode) {
          countNode.textContent = countTemplate.replace('__COUNT__', String(count));
        }
        if (checkAll) {
          checkAll.checked = checks.length > 0 && count === checks.length;
          checkAll.indeterminate = count > 0 && count < checks.length;
        }
        if (submitButton) {
          submitButton.disabled = !(count > 0 && action);
        }
      }

      function syncTargets(focusTarget) {
        var value = actionSelect ? (actionSelect.value || '') : '';
        var targetToFocus = null;

        setTarget(assignTarget, value === 'assign');
        setTarget(statusTarget, value === 'status');
        setTarget(noteTarget, value === 'note');

        if (value === 'assign') {
          targetToFocus = assignTarget;
        } else if (value === 'status') {
          targetToFocus = statusTarget;
        } else if (value === 'note') {
          targetToFocus = noteTarget;
        }

        syncState();

        if (focusTarget && targetToFocus && typeof targetToFocus.focus === 'function') {
          window.setTimeout(function () {
            targetToFocus.focus();
          }, 0);
        }
      }

      if (actionSelect) {
        actionSelect.addEventListener('change', function () {
          syncTargets(true);
        });
      }

      if (checkAll) {
        checkAll.addEventListener('change', function () {
          var checks = rowChecks();
          for (var i = 0; i < checks.length; i++) {
            checks[i].checked = checkAll.checked;
          }
          syncState();
        });
      }

      form.addEventListener('change', function (event) {
        var target = event && event.target ? event.target : null;
        if (target && target.className && String(target.className).indexOf('smartsearch-row-check') !== -1) {
          syncState();
        }
      });

      form.addEventListener('submit', function (event) {
        var action = actionSelect ? (actionSelect.value || '') : '';
        var message = '';

        if (selectedCount() < 1) {
          message = form.getAttribute('data-select-one-message') || 'Select at least one conversation.';
        } else if (!action) {
          message = form.getAttribute('data-action-message') || 'Choose a bulk action.';
        } else if (action === 'assign' && assignTarget && assignTarget.value === '0') {
          message = form.getAttribute('data-assignee-message') || 'Choose an assignee.';
        } else if (action === 'status' && statusTarget && statusTarget.value === '0') {
          message = form.getAttribute('data-status-message') || 'Choose a status.';
        } else if (action === 'note' && noteTarget && !trimValue(noteTarget.value)) {
          message = form.getAttribute('data-note-message') || 'Enter an internal note.';
        }

        if (message) {
          event.preventDefault();
          window.alert(message);
          return;
        }

        form.setAttribute('aria-busy', 'true');
        if (submitButton) {
          submitButton.disabled = true;
          var processingLabel = form.getAttribute('data-processing-label');
          if (processingLabel) {
            submitButton.textContent = processingLabel;
          }
        }
      });

      syncTargets(false);
      syncState();
    } catch (e) {
      // Page-only enhancement; never break navbar search.
    }
  });
})();

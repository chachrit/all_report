(function () {
    'use strict';

    var SECTION_PRESETS = {
        'index.php': {
            sections: ['annual-goal', 'monthly-target-progress', 'kpi-cards', 'total-sales-trend', 'revenue-trend-channel-mix', 'top-products-geo', 'top-offline-products'],
            roles: {
                ceo: ['annual-goal', 'kpi-cards', 'monthly-target-progress', 'total-sales-trend', 'revenue-trend-channel-mix'],
                manager: ['annual-goal', 'kpi-cards', 'monthly-target-progress', 'total-sales-trend', 'revenue-trend-channel-mix', 'top-products-geo', 'top-offline-products'],
                operation: ['kpi-cards', 'monthly-target-progress', 'total-sales-trend', 'revenue-trend-channel-mix', 'top-products-geo', 'top-offline-products']
            }
        },
        'dashboard_online.php': {
            sections: ['kpi-cards', 'performance-diagnosis', 'product-mix-grid', 'platform-top-products-tables', 'daily-control', 'discount-anomaly', 'customer-insights'],
            roles: {
                ceo: ['kpi-cards', 'performance-diagnosis'],
                manager: ['kpi-cards', 'performance-diagnosis', 'product-mix-grid', 'platform-top-products-tables', 'customer-insights'],
                operation: ['kpi-cards', 'daily-control', 'discount-anomaly', 'performance-diagnosis']
            }
        },
        'dashboard_offline.php': {
            sections: ['kpi-cards', 'monthly-trend', 'performance-diagnosis', 'zone-product-discount-mix', 'branch-products-tables', 'daily-control', 'discount-anomaly-heatmap'],
            roles: {
                ceo: ['kpi-cards', 'monthly-trend'],
                manager: ['kpi-cards', 'monthly-trend', 'performance-diagnosis', 'zone-product-discount-mix', 'branch-products-tables'],
                operation: ['kpi-cards', 'daily-control', 'discount-anomaly-heatmap', 'performance-diagnosis']
            }
        },
        'dashboard_consignment.php': {
            sections: ['kpi-cards', 'charts-section', 'partner-performance', 'branch-performance', 'top-products', 'inventory-status'],
            roles: {
                ceo: ['kpi-cards', 'charts-section'],
                manager: ['kpi-cards', 'charts-section', 'partner-performance', 'branch-performance', 'top-products'],
                operation: ['kpi-cards', 'inventory-status', 'partner-performance', 'branch-performance']
            }
        }
    };

    var page = (document.body.dataset.currentPage || location.pathname.split('/').pop() || 'index.php');
    var storageKey = 'sectionPrefs:' + page;
    var uiLang = document.body.dataset.uiLang === 'en' ? 'en' : 'th';
    var preset = SECTION_PRESETS[page];

    var backdrop, modal, list, resetBtn, roleSelect;
    var dragEl = null;

    function getSections() {
        return Array.prototype.slice.call(document.querySelectorAll('.dash-section'));
    }

    function sectionLabel(el) {
        return uiLang === 'en'
            ? (el.dataset.sectionLabelEn || el.dataset.sectionId)
            : (el.dataset.sectionLabelTh || el.dataset.sectionLabelEn || el.dataset.sectionId);
    }

    function readPrefs() {
        try {
            var raw = localStorage.getItem(storageKey);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function writePrefs(order, hidden, role) {
        try {
            localStorage.setItem(storageKey, JSON.stringify({ order: order, hidden: hidden, role: role || 'custom' }));
        } catch (e) { /* ignore quota/private-mode errors */ }
    }

    function applyOrderAndVisibility(order, hidden) {
        var sections = getSections();
        var byId = {};
        sections.forEach(function (el) { byId[el.dataset.sectionId] = el; });
        hidden = hidden || [];

        var seen = {};
        order.forEach(function (id) {
            var el = byId[id];
            if (!el) return;
            seen[id] = true;
            el.parentNode.appendChild(el);
            el.hidden = hidden.indexOf(id) !== -1;
        });
        sections.forEach(function (el) {
            var id = el.dataset.sectionId;
            if (seen[id]) return;
            el.parentNode.appendChild(el);
            el.hidden = hidden.indexOf(id) !== -1;
        });
    }

    // A saved order must contain exactly the sections currently on the page — if a
    // section was added/removed since the prefs were saved, the cache is stale and
    // would either silently show the new section (missing from a saved "hidden"
    // list) or reference one that no longer exists. Discard rather than half-apply.
    function prefsMatchCurrentSections(prefs) {
        var currentIds = getSections().map(function (el) { return el.dataset.sectionId; }).sort();
        var savedIds = (prefs.order || []).slice().sort();
        if (currentIds.length !== savedIds.length) return false;
        for (var i = 0; i < currentIds.length; i++) {
            if (currentIds[i] !== savedIds[i]) return false;
        }
        return true;
    }

    function applySavedOrDefault() {
        var prefs = readPrefs();
        if (!prefs) return;
        if (prefs.order && prefsMatchCurrentSections(prefs)) {
            applyOrderAndVisibility(prefs.order, prefs.hidden);
        } else {
            try { localStorage.removeItem(storageKey); } catch (e) { /* ignore */ }
        }
    }

    function currentEffectiveOrder() {
        return getSections().map(function (el) { return el.dataset.sectionId; });
    }

    function persistFromDom(role) {
        var order = [];
        var hidden = [];
        Array.prototype.slice.call(list.querySelectorAll('.sm-item')).forEach(function (li) {
            var id = li.dataset.sectionId;
            order.push(id);
            if (!li.querySelector('input').checked) hidden.push(id);
        });
        writePrefs(order, hidden, role);
        applyOrderAndVisibility(order, hidden);
    }

    function renderList() {
        if (!list) return;
        var prefs = readPrefs();
        var hidden = (prefs && prefs.hidden) || [];
        var activeRole = prefs && prefs.role;

        list.innerHTML = '';
        var sections = getSections();
        sections.forEach(function (el) {
            var id = el.dataset.sectionId;
            var li = document.createElement('li');
            li.className = 'sm-item' + (hidden.indexOf(id) !== -1 ? ' is-hidden' : '');
            li.dataset.sectionId = id;
            li.draggable = true;

            var handle = document.createElement('span');
            handle.className = 'sm-handle';
            handle.textContent = '⋮⋮';
            li.appendChild(handle);

            var label = document.createElement('label');
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = hidden.indexOf(id) === -1;
            checkbox.addEventListener('change', function () {
                li.classList.toggle('is-hidden', !checkbox.checked);
                persistFromDom('custom');
                syncRoleButtons(null);
            });
            var text = document.createElement('span');
            text.textContent = sectionLabel(el);
            label.appendChild(checkbox);
            label.appendChild(text);
            li.appendChild(label);

            li.addEventListener('dragstart', function () {
                dragEl = li;
                li.classList.add('dragging');
            });
            li.addEventListener('dragend', function () {
                li.classList.remove('dragging');
                dragEl = null;
                persistFromDom('custom');
                syncRoleButtons(null);
            });
            li.addEventListener('dragover', function (e) {
                e.preventDefault();
                if (!dragEl || dragEl === li) return;
                var rect = li.getBoundingClientRect();
                var before = (e.clientY - rect.top) < rect.height / 2;
                list.insertBefore(dragEl, before ? li : li.nextSibling);
            });

            list.appendChild(li);
        });

        syncRoleButtons(activeRole);
    }

    function syncRoleButtons(role) {
        if (modal) {
            Array.prototype.slice.call(modal.querySelectorAll('.sm-role-btn')).forEach(function (btn) {
                btn.classList.toggle('active', btn.dataset.role === role);
            });
        }
        if (roleSelect) {
            roleSelect.value = role || '';
        }
    }

    function applyRole(role) {
        if (!preset || !preset.roles[role]) return;
        var wanted = preset.roles[role];
        var all = preset.sections.slice();
        var order = wanted.concat(all.filter(function (id) { return wanted.indexOf(id) === -1; }));
        var hidden = all.filter(function (id) { return wanted.indexOf(id) === -1; });
        writePrefs(order, hidden, role);
        applyOrderAndVisibility(order, hidden);
        renderList();
    }

    function resetToDefault() {
        try { localStorage.removeItem(storageKey); } catch (e) { /* ignore */ }
        if (preset && preset.sections) {
            applyOrderAndVisibility(preset.sections, []);
        } else {
            applyOrderAndVisibility(currentEffectiveOrder(), []);
        }
        renderList();
    }

    function open() {
        if (!backdrop) return;
        renderList();
        backdrop.classList.add('open');
    }

    function close() {
        if (!backdrop) return;
        backdrop.classList.remove('open');
    }

    document.addEventListener('DOMContentLoaded', function () {
        applySavedOrDefault();

        backdrop = document.getElementById('sectionManagerBackdrop');
        modal = document.getElementById('sectionManagerModal');
        list = document.getElementById('sectionManagerList');
        resetBtn = document.getElementById('sectionManagerReset');
        roleSelect = document.getElementById('roleQuickSelect');

        var savedPrefs = readPrefs();
        syncRoleButtons(savedPrefs && savedPrefs.role !== 'custom' ? savedPrefs.role : null);

        if (!backdrop) return;

        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) close();
        });
        Array.prototype.slice.call(modal.querySelectorAll('.sm-role-btn')).forEach(function (btn) {
            btn.addEventListener('click', function () { applyRole(btn.dataset.role); });
        });
        if (resetBtn) resetBtn.addEventListener('click', resetToDefault);
    });

    window.SectionManager = { open: open, close: close, applyRole: applyRole };
})();

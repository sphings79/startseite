(() => {
    'use strict';

    const boot = JSON.parse(document.getElementById('boot').textContent);
    const S = {
        base: boot.base, csrf: boot.csrf, username: boot.username, settings: boot.settings,
        groups: boot.groups, sections: boot.sections, dials: boot.dials, jobs: boot.jobs, screenshots: boot.screenshots,
    };
    const ui = { group: null, query: '', tags: new Set(), sel: 0, cleanSel: new Set(), checkFilter: 'problems', checkSel: new Set(), onState: null };
    const root = document.documentElement;

    // ---------- helpers ----------

    const $ = (sel, el = document) => el.querySelector(sel);

    const h = (tag, props, ...kids) => {
        const el = document.createElement(tag);
        for (const [k, v] of Object.entries(props || {})) {
            if (v === null || v === undefined || v === false) continue;
            if (k === 'class') el.className = v;
            else if (k === 'text') el.textContent = v;
            else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
            else if (k in el) el[k] = v;
            else el.setAttribute(k, v === true ? '' : v);
        }
        for (const kid of kids.flat(Infinity)) {
            if (kid === null || kid === undefined || kid === false) continue;
            el.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
        }
        return el;
    };

    const ICONS = {
        search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        auto: '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor"/>',
        menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
        x: '<path d="M6 6l12 12M18 6 6 18"/>',
        sparkle: '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>',
        shot: '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M3 9h18"/>',
        link: '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        upload: '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
        none: '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
        up: '<path d="M12 19V5M6 11l6-6 6 6"/>',
        down: '<path d="M12 5v14M6 13l6 6 6-6"/>',
        grip: '<circle cx="9" cy="6" r="1.3"/><circle cx="15" cy="6" r="1.3"/><circle cx="9" cy="12" r="1.3"/><circle cx="15" cy="12" r="1.3"/><circle cx="9" cy="18" r="1.3"/><circle cx="15" cy="18" r="1.3"/>',
        gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    };
    const icon = (name) => {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        svg.innerHTML = ICONS[name]; // static, trusted markup
        return svg;
    };

    const store = {
        get: (k) => { try { return localStorage.getItem('startseite.' + k); } catch { return null; } },
        set: (k, v) => { try { localStorage.setItem('startseite.' + k, v); } catch { /* storage unavailable */ } },
    };

    async function api(method, path, body) {
        const opts = { method, credentials: 'same-origin', headers: { 'X-CSRF-Token': S.csrf, Accept: 'application/json' } };
        if (body instanceof FormData) {
            opts.body = body;
        } else if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(S.base + '/api' + path, opts);
        if (res.status === 401) { location.reload(); throw new Error('Sitzung abgelaufen'); }
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || 'Fehler ' + res.status);
        return data;
    }

    let toastTimer;
    function toast(msg) {
        $('.toast')?.remove();
        const t = h('div', { class: 'toast', role: 'status', text: msg });
        document.body.append(t);
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => t.remove(), 3500);
    }

    // ---------- derived data ----------

    const groupsSorted = () => [...S.groups].sort((a, b) => a.position - b.position || a.id - b.id);
    const sectionsOf = (gid) => S.sections.filter((x) => x.group_id === gid).sort((a, b) => a.position - b.position || a.id - b.id);
    const secOf = (d) => S.sections.find((x) => x.id === d.section_id && x.group_id === d.group_id) || sectionsOf(d.group_id)[0];
    // "Tab" or "Tab › Section" for lists outside the start page.
    const placeOf = (d) => {
        const g = S.groups.find((x) => x.id === d.group_id)?.title || '';
        const sec = secOf(d);
        return sec?.title ? `${g} › ${sec.title}` : g;
    };

    function sortDials(list) {
        const by = S.settings.order_by;
        const l = [...list];
        if (by === 'visits') l.sort((a, b) => b.visits - a.visits || a.position - b.position);
        else if (by === 'title') l.sort((a, b) => a.title.localeCompare(b.title, 'de', { sensitivity: 'base' }));
        else if (by === 'recent') l.sort((a, b) => (b.last_visit || 0) - (a.last_visit || 0) || a.position - b.position);
        else if (by === 'created') l.sort((a, b) => b.created_at - a.created_at);
        else l.sort((a, b) => a.position - b.position || a.id - b.id);
        return l;
    }
    // Automatic groups are virtual tabs with a string id; real groups have numeric ids.
    const VIRTUAL = { top: ['auto_top', 'Meist besucht'], recent: ['auto_recent', 'Zuletzt hinzugefügt'] };
    const virtualTabs = () => Object.entries(VIRTUAL).filter(([, [setting]]) => S.settings[setting]).map(([id, [, title]]) => ({ id, title }));
    const validGroup = (id) => S.groups.some((g) => g.id === id) || virtualTabs().some((v) => v.id === id);
    const realGroupId = () => (typeof ui.group === 'number' ? ui.group : groupsSorted()[0]?.id);
    const searching = () => !!ui.query || ui.tags.size > 0;
    function dialsOf(gid) {
        const n = S.settings.auto_count;
        if (gid === 'top') return [...S.dials].filter((d) => d.visits > 0).sort((a, b) => b.visits - a.visits || (b.last_visit || 0) - (a.last_visit || 0)).slice(0, n);
        if (gid === 'recent') return [...S.dials].sort((a, b) => b.created_at - a.created_at || b.id - a.id).slice(0, n);
        return sortDials(S.dials.filter((d) => d.group_id === gid));
    }
    const reorderAllowed = () => S.settings.order_by === 'manual' && !searching() && typeof ui.group === 'number';
    const phone = matchMedia('(max-width: 640px)');
    const listMode = () => S.settings.mobile_list && phone.matches; // one row per bookmark: small icon left, title right
    phone.addEventListener('change', () => renderGrid());

    // ---------- theme / settings application ----------

    const dark = matchMedia('(prefers-color-scheme: dark)');
    const effectiveTheme = () => (S.settings.theme === 'auto' ? (dark.matches ? 'dark' : 'light') : S.settings.theme);

    function applyTheme() {
        const s = S.settings;
        const th = effectiveTheme();
        const set = (k, v) => root.style.setProperty(k, v);
        root.dataset.theme = th;
        set('--accent', s.accent);
        set('--tile-bg', s['tile_bg_' + th]);
        set('--tile-text', s['tile_text_' + th]);
        set('--cols', String(s.columns));
        set('--gap', s.spacing + 'px');
        set('--radius', s.border_radius + 'px');
        set('--ratio', s.ratio.replace(':', ' / '));
        set('--maxw', s.max_width === 0 ? 'none' : s.max_width + 'px');
        set('--fs', String(s.font_scale / 100));
        set('--align', s.title_align);
        const bg = s['bg_' + th];
        set('--bg', bg.color || (th === 'dark' ? '#0f1115' : '#eeede8'));
        const el = $('#bg');
        el.style.backgroundColor = bg.color || 'transparent';
        el.style.backgroundImage = bg.url ? `url("${bg.url}")` : 'none';
        el.style.backgroundSize = bg.size;
        el.style.backgroundPosition = bg.position;
        el.style.backgroundRepeat = bg.repeat;
        el.style.opacity = String(bg.opacity / 100);
        document.title = s.app_name;
        const content = $('#content');
        if (content) {
            content.classList.toggle('compact', s.compact);
            content.classList.toggle('mlist', s.mobile_list);
            content.classList.toggle('vcenter', s.center_vertical);
        }
        const web = $('#websearch');
        if (web) { web.hidden = !s.show_web_search; web.sync(); }
        const themeBtn = $('#theme-btn');
        if (themeBtn) {
            themeBtn.replaceChildren(icon(s.theme === 'auto' ? 'auto' : s.theme === 'dark' ? 'moon' : 'sun'));
            themeBtn.title = 'Thema: ' + { auto: 'Automatisch', light: 'Hell', dark: 'Dunkel' }[s.theme];
        }
    }
    dark.addEventListener('change', applyTheme);

    const saveTimers = {};
    function setSetting(key, value, { render = true, save = true } = {}) {
        S.settings[key] = value;
        applyTheme();
        if (key.startsWith('auto_')) {
            if (!validGroup(ui.group)) ui.group = groupsSorted()[0].id;
            renderTabs();
            renderGrid();
        } else if (render) renderGrid();
        if (!save) return;
        clearTimeout(saveTimers[key]);
        saveTimers[key] = setTimeout(() => api('PUT', '/settings', { [key]: value }).catch((e) => toast(e.message)), 250);
    }

    // ---------- shell ----------

    function buildShell() {
        const search = h('input', {
            class: 'search', type: 'search', id: 'search', placeholder: 'Suchen …', hidden: true, 'aria-label': 'Suche',
            oninput: (e) => { ui.query = e.target.value.trim().toLowerCase(); ui.sel = 0; renderTabs(); renderGrid(); },
            onkeydown: searchKeys,
        });
        $('#app').append(
            h('header', { class: 'top', id: 'top' },
                h('button', { class: 'icon-btn nav-btn', title: 'Menü', 'aria-label': 'Menü', onclick: openDrawer }, icon('menu')),
                h('span', { class: 'cur-group', id: 'cur-group' }),
                h('nav', { class: 'tabs', id: 'tabs', 'aria-label': 'Gruppen' }),
                search,
                h('button', { class: 'icon-btn', title: 'Suche', 'aria-label': 'Suche', onclick: () => toggleSearch() }, icon('search')),
                h('button', {
                    class: 'icon-btn', id: 'theme-btn', 'aria-label': 'Thema wechseln',
                    onclick: () => setSetting('theme', { auto: 'light', light: 'dark', dark: 'auto' }[S.settings.theme], { render: false }),
                }),
                h('button', { class: 'icon-btn menu-btn', title: 'Menü', 'aria-label': 'Menü', onclick: (e) => showMenu([
                    { label: 'Lesezeichen hinzufügen', fn: () => editDial(null) },
                    { label: 'Einstellungen', fn: () => openOptions() },
                    '-',
                    { label: 'Abmelden', fn: logout },
                ], e.currentTarget.getBoundingClientRect().right - 190, e.currentTarget.getBoundingClientRect().bottom + 4) }, icon('menu')),
            ),
            h('main', { class: 'content', id: 'content' }, webSearchForm(), h('div', { class: 'tagbar', id: 'tagbar', hidden: true }), h('div', { class: 'sections', id: 'grid' })),
        );
    }

    const ENGINES = {
        google: ['Google', 'https://www.google.com/search?q=%s'],
        duckduckgo: ['DuckDuckGo', 'https://duckduckgo.com/?q=%s'],
        bing: ['Bing', 'https://www.bing.com/search?q=%s'],
        brave: ['Brave', 'https://search.brave.com/search?q=%s'],
        startpage: ['Startpage', 'https://www.startpage.com/sp/search?query=%s'],
        ecosia: ['Ecosia', 'https://www.ecosia.org/search?q=%s'],
        qwant: ['Qwant', 'https://www.qwant.com/?q=%s'],
    };
    const engineList = () => Object.entries(ENGINES).concat(S.settings.web_search_custom ? [['custom', ['Eigene', S.settings.web_search_custom]]] : []);
    const currentEngine = () => (engineList().find(([k]) => k === S.settings.web_search_engine) || engineList()[0]);

    // Opens the search in the chosen engine; done in script because the CSP only allows forms to post to this site.
    function webSearchForm() {
        const input = h('input', { type: 'search', name: 'q', autocomplete: 'off', enterKeyHint: 'search' });
        const picker = h('select', { class: 'engine', 'aria-label': 'Suchanbieter', onchange: (e) => {
            setSetting('web_search_engine', e.target.value, { render: false });
            syncWebSearch();
        } });
        const form = h('form', {
            class: 'websearch', id: 'websearch', role: 'search', hidden: !S.settings.show_web_search,
            onsubmit: (e) => {
                e.preventDefault();
                const q = input.value.trim();
                if (!q) return;
                const url = currentEngine()[1][1].replace('%s', encodeURIComponent(q));
                if (S.settings.open_new_tab) window.open(url, '_blank', 'noopener,noreferrer'); else location.href = url;
                input.value = '';
            },
        }, icon('search'), input, picker);
        form.sync = () => {
            const [key, [name]] = currentEngine();
            picker.replaceChildren(...engineList().map(([k, [n]]) => h('option', { value: k, text: n, selected: k === key })));
            input.placeholder = name + ' durchsuchen …';
            input.setAttribute('aria-label', name + '-Suche');
        };
        form.sync();
        return form;
    }
    const syncWebSearch = () => $('#websearch')?.sync();

    function toggleSearch(force) {
        const el = $('#search');
        const show = force ?? el.hidden;
        el.hidden = !show;
        $('#top').classList.toggle('searching', show);
        if (show) {
            el.focus();
            renderGrid();
        } else {
            el.value = '';
            ui.query = '';
            ui.tags.clear();
            renderTabs();
            renderGrid();
        }
    }

    async function logout() {
        try { await api('POST', '/logout'); } catch { /* ignore */ }
        location.href = S.base + '/login';
    }

    function renderTabs() {
        const nav = $('#tabs');
        const groups = groupsSorted();
        nav.replaceChildren(...groups.map((g) => h('button', {
            class: 'tab' + (g.id === ui.group && !searching() ? ' active' : ''), 'data-gid': g.id, text: g.title,
            onclick: () => selectGroup(g.id),
        })).concat(virtualTabs().map((v) => h('button', {
            class: 'tab virtual' + (v.id === ui.group && !searching() ? ' active' : ''), 'data-vid': v.id, text: v.title,
            onclick: () => selectGroup(v.id),
        }))));
        $('.tab.active', nav)?.scrollIntoView({ inline: 'center', block: 'nearest' });
        const cur = $('#cur-group');
        if (cur) cur.textContent = groups.find((g) => g.id === ui.group)?.title || virtualTabs().find((v) => v.id === ui.group)?.title || S.settings.app_name;
    }

    function selectGroup(id) {
        ui.group = id;
        if (S.settings.keep_active_group) store.set('group', String(id));
        if (searching()) toggleSearch(false);
        renderTabs();
        renderGrid();
    }

    // Keeps the page behind a dialog or the drawer from scrolling (counted, dialogs can stack).
    let scrollLocks = 0;
    function lockScroll(on) {
        scrollLocks = Math.max(0, scrollLocks + (on ? 1 : -1));
        document.documentElement.classList.toggle('scroll-locked', scrollLocks > 0);
    }

    // Mobile navigation: groups and actions in a drawer behind the burger button.
    function closeDrawer() { if ($('.drawer-wrap')) { $('.drawer-wrap').remove(); lockScroll(false); } }
    function openDrawer() {
        closeDrawer();
        const groups = groupsSorted();
        const item = (label, fn, cls = '') => h('button', { type: 'button', class: 'drawer-item ' + cls, text: label, onclick: () => { closeDrawer(); fn(); } });
        const wrap = h('div', { class: 'drawer-wrap' },
            h('div', { class: 'drawer-backdrop', onclick: closeDrawer }),
            h('aside', { class: 'drawer', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Menü' },
                h('div', { class: 'drawer-scroll' },
                    h('div', { class: 'drawer-title', text: S.settings.app_name }),
                    h('div', { class: 'drawer-label', text: 'Gruppen' }),
                    groups.concat(virtualTabs()).map((g) => item(g.title, () => { selectGroup(g.id); window.scrollTo(0, 0); },
                        g.id === ui.group && !searching() ? 'active' : ''))),
                h('div', { class: 'drawer-foot' },
                    item('Lesezeichen hinzufügen', () => editDial(null)),
                    item('Einstellungen', () => openOptions()),
                    item('Abmelden', logout, 'danger'))));
        document.body.append(wrap);
        lockScroll(true);
        $('.drawer-item.active', wrap)?.scrollIntoView({ block: 'nearest' });
        $('.drawer-item', wrap)?.focus();
    }
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDrawer(); });

    // ---------- tiles ----------

    // Automatic never crops: logos sit padded in the tile, wide images are shown whole whatever the tile ratio.
    const fitOf = (d) => (d.fit !== 'auto' ? d.fit : d.thumb_kind === 'shot' ? 'whole' : 'contain');

    function placeholder(d) {
        let host = d.title;
        try { host = new URL(d.url).hostname.replace(/^www\./, ''); } catch { /* keep title */ }
        let hash = 0;
        for (const c of host) hash = (hash * 31 + c.charCodeAt(0)) >>> 0;
        // IP addresses and local names make poor initials; use the title then.
        const letterSource = /^[\d.:[\]]+$/.test(host) || !host.includes('.') ? d.title : host;
        const el = h('div', { class: 'letter', text: (letterSource.match(/[\p{L}\p{N}]/u)?.[0] || '?') });
        el.style.background = d.color || `hsl(${hash % 360} 45% 48%)`;
        if (d.color) el.style.color = textOn(d.color);
        return el;
    }

    // Readable text colour for a background.
    function textOn(hex) {
        const n = parseInt(hex.slice(1), 16);
        const [r, g, b] = [n >> 16 & 255, n >> 8 & 255, n & 255].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.4 ? '#111827' : '#ffffff';
    }

    function tileEl(d, list = listMode()) {
        const thumb = h('div', { class: 'thumb' }, d.thumb
            ? h('img', { class: fitOf(d), src: d.thumb, alt: '', loading: 'lazy', draggable: false })
            : placeholder(d));
        if (d.check_status === 'dead') thumb.append(h('span', { class: 'tdead', title: 'Nicht erreichbar: ' + (d.check_reason || '') }));
        if (d.thumb_status === 'pending' || d.thumb_status === 'running') thumb.append(h('span', { class: 'spin', title: 'Vorschau wird erzeugt' }));
        const a = h('a', {
            class: 'tile', href: d.url, rel: 'noopener noreferrer', draggable: false, 'data-id': d.id,
            target: S.settings.open_new_tab ? '_blank' : null,
        },
        thumb,
        d.badge ? badgeEl(d) : null,
        S.settings.show_visits && d.visits > 0 ? h('span', { class: 'visits', text: d.visits }) : null,
        S.settings.show_title || list ? h('div', { class: 'title', text: d.title, title: d.title }) : null);

        a.addEventListener('click', (e) => {
            if (suppressClick) { e.preventDefault(); return; }
            d.visits++;
            d.last_visit = Math.floor(Date.now() / 1000);
            fetch(`${S.base}/api/dials/${d.id}/visit`, { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'X-CSRF-Token': S.csrf } });
        });
        if (d.color) {
            a.style.setProperty('--tile-bg', d.color);
            a.style.setProperty('--tile-text', textOn(d.color));
        }
        a.addEventListener('dragstart', (e) => e.preventDefault());
        a.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            if (lastPointerType === 'mouse') tileMenu(d, e.clientX, e.clientY);
        });
        a.addEventListener('pointerdown', (e) => dragStart(e, a, d));
        return a;
    }

    // ---------- search ----------

    const BANGS = { g: 'google', ddg: 'duckduckgo', b: 'bing', br: 'brave', sp: 'startpage', e: 'ecosia', q: 'qwant', x: 'custom' };

    /** "g wetter" -> web search with that engine instead of filtering bookmarks. */
    function parseBang(raw) {
        const m = raw.trim().match(/^(\S+)\s+(.+)$/);
        const key = m && BANGS[m[1].toLowerCase()];
        const engine = key && engineList().find(([k]) => k === key);
        return engine ? { engine, text: m[2].trim() } : null;
    }

    const searchUrl = (engine, text) => engine[1][1].replace('%s', encodeURIComponent(text));
    const openWeb = (url) => { if (S.settings.open_new_tab) window.open(url, '_blank', 'noopener,noreferrer'); else location.href = url; };

    /** Words must all appear in title, URL, tags, note or page description; "#tag" words match tags. */
    function matcher() {
        const words = ui.query.split(/\s+/).filter(Boolean);
        const text = words.filter((w) => !(w.startsWith('#') && w.length > 1));
        const tagTerms = words.filter((w) => w.startsWith('#') && w.length > 1).map((w) => w.slice(1));
        return (d) => {
            const tags = d.tags.map((t) => t.toLowerCase());
            if (![...ui.tags].every((t) => tags.includes(t))) return false;
            if (!tagTerms.every((t) => tags.some((x) => x.includes(t)))) return false;
            const hay = `${d.title} ${d.url} ${tags.join(' ')} ${d.note} ${d.meta_desc}`.toLowerCase();
            return text.every((w) => hay.includes(w));
        };
    }

    function renderTagbar() {
        const bar = $('#tagbar');
        if (!bar) return;
        const open = !$('#search').hidden;
        const counts = new Map();
        for (const d of S.dials) for (const t of d.tags) counts.set(t.toLowerCase(), { name: counts.get(t.toLowerCase())?.name || t, n: (counts.get(t.toLowerCase())?.n || 0) + 1 });
        const list = [...counts.entries()].sort((a, b) => b[1].n - a[1].n || a[1].name.localeCompare(b[1].name, 'de')).slice(0, 40);
        bar.hidden = !open || !list.length;
        bar.replaceChildren(...list.map(([key, { name, n }]) => h('button', {
            type: 'button', class: 'tagchip' + (ui.tags.has(key) ? ' on' : ''), text: `${name} ${n}`,
            onclick: () => { ui.tags.has(key) ? ui.tags.delete(key) : ui.tags.add(key); ui.sel = 0; renderTabs(); renderGrid(); },
        })));
    }

    function markSelection() {
        const tiles = [...document.querySelectorAll('#grid .tile')];
        ui.sel = Math.max(0, Math.min(ui.sel, tiles.length - 1));
        tiles.forEach((t, i) => t.classList.toggle('sel', i === ui.sel));
        tiles[ui.sel]?.scrollIntoView({ block: 'nearest' });
    }

    function searchKeys(e) {
        if (e.key === 'Escape') { toggleSearch(false); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            ui.sel += e.key === 'ArrowDown' ? 1 : -1;
            markSelection();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const raw = e.target.value.trim();
            if (!raw) return;
            const bang = parseBang(raw);
            const tile = document.querySelectorAll('#grid .tile')[ui.sel];
            if (bang) openWeb(searchUrl(bang.engine, bang.text));
            else if (tile) tile.click();
            else openWeb(searchUrl(currentEngine(), raw));
            toggleSearch(false);
        }
    }

    document.addEventListener('keydown', (e) => {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || document.querySelector('dialog[open]') || e.target.closest?.('input, textarea, select, [contenteditable]')) return;
        e.preventDefault();
        toggleSearch(true);
    });

    function badgeEl(d) {
        const el = h('span', { class: 'tbadge', text: d.badge });
        const bg = d.badge_color || S.settings.accent;
        el.style.background = bg;
        el.style.color = textOn(bg);
        return el;
    }

    const SIZES = { s: 80, m: 110, l: 160 };
    const rgba = (hex, pct) => {
        const n = parseInt(hex.slice(1), 16);
        return `rgba(${n >> 16 & 255}, ${n >> 8 & 255}, ${n & 255}, ${pct / 100})`;
    };

    /** One section of a tab: frame, optional title, and its tiles as grid or list. */
    function sectionEl(sec, items, first) {
        const phoneList = phone.matches && S.settings.mobile_list; // the phone list switch overrides every section
        const list = phoneList || sec.mode === 'list';
        const el = h('section', { class: 'sec' + (sec.collapsed ? ' collapsed' : ''), 'data-sid': sec.id });
        el.style.setProperty('--frac', String(sec.width / 100));
        if (sec.bg_color) el.style.setProperty('--sec-bg', rgba(sec.bg_color, sec.bg_opacity));
        if (sec.border_width && sec.border_color) el.style.setProperty('--sec-border', `${sec.border_width}px solid ${sec.border_color}`);
        if (sec.bg_color && sec.bg_opacity >= 60) el.style.setProperty('--sec-text', textOn(sec.bg_color)); // a solid frame needs its own title colour
        el.style.setProperty('--sec-radius', sec.radius + 'px');
        el.style.setProperty('--sec-pad', sec.padding + 'px');
        const gear = h('button', { type: 'button', class: 'gear icon-btn', title: 'Abschnitt bearbeiten', 'aria-label': 'Abschnitt bearbeiten', onclick: (e) => { e.stopPropagation(); editSection(sec); } }, icon('gear'));
        const grip = h('button', { type: 'button', class: 'grip icon-btn', title: 'Abschnitt verschieben', 'aria-label': 'Abschnitt verschieben',
            onclick: (e) => e.stopPropagation(), onpointerdown: (e) => sectionDragStart(e, el) }, icon('grip'));
        if (sec.title) {
            const head = h('h3', { class: 'sec-title' + (sec.title_center ? ' c' : ''), title: sec.collapsed ? 'Ausklappen' : 'Einklappen',
                onclick: async () => {
                    sec.collapsed = !sec.collapsed;
                    renderGrid();
                    try { await api('PUT', `/sections/${sec.id}`, { collapsed: sec.collapsed }); } catch (err) { toast(err.message); }
                } }, h('span', { class: 'caret', text: sec.collapsed ? '▸' : '▾' }), sec.title, grip, gear);
            el.append(head);
        } else {
            const corner = h('div', { class: 'corner' }, grip, gear);
            el.append(corner);
        }
        const body = h('div', { class: 'grid sbody' + (list ? ' listv' : '') + (sec.tile_size && !list ? ' sized' : '') });
        if (!phone.matches && list) body.style.setProperty('--lcols', String(sec.cols));
        if (sec.tile_size) body.style.setProperty('--tile-min', SIZES[sec.tile_size] + 'px');
        if (sec.ratio) body.style.setProperty('--ratio', sec.ratio.replace(':', ' / '));
        body.append(...items.map((d) => tileEl(d, list)));
        if (first && S.settings.show_add_button) {
            body.append(h('button', { class: 'add-tile', title: 'Lesezeichen hinzufügen', 'aria-label': 'Lesezeichen hinzufügen', text: '+', onclick: () => editDial(null, { section_id: sec.id }) }));
        }
        if (!items.length && !body.children.length) body.append(h('div', { class: 'empty small', text: 'Leer: Lesezeichen hierher ziehen.' }));
        el.append(body);
        return el;
    }

    // Move a section by its grip: the element follows the pointer through the other sections, the new order is saved on release.
    function sectionDragStart(e, el) {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        e.preventDefault();
        e.stopPropagation();
        const grip = e.currentTarget;
        grip.setPointerCapture(e.pointerId);
        el.classList.add('sec-dragging');
        let y = e.clientY;
        const scroller = setInterval(() => { if (y < 90) scrollBy(0, -14); else if (y > innerHeight - 90) scrollBy(0, 14); }, 16);
        const move = (ev) => {
            y = ev.clientY;
            const over = document.elementsFromPoint(ev.clientX, ev.clientY).map((n) => n.closest?.('.sec[data-sid]')).find((n) => n && n !== el);
            if (!over) return;
            const r = over.getBoundingClientRect();
            const sideBySide = r.width < over.parentNode.getBoundingClientRect().width * 0.9;
            const before = sideBySide ? ev.clientX < r.left + r.width / 2 : ev.clientY < r.top + r.height / 2;
            const ref = before ? over : over.nextElementSibling;
            if (ref !== el && ref !== el.nextElementSibling) over.parentNode.insertBefore(el, ref);
        };
        const end = async (ev, cancelled) => {
            clearInterval(scroller);
            grip.removeEventListener('pointermove', move);
            grip.removeEventListener('pointerup', up);
            grip.removeEventListener('pointercancel', cancel);
            el.classList.remove('sec-dragging');
            const ids = [...document.querySelectorAll('#grid .sec[data-sid]')].map((n) => Number(n.dataset.sid));
            const gid = realGroupId();
            const changed = ids.some((id, i) => sectionsOf(gid)[i]?.id !== id);
            if (cancelled || !changed) { renderGrid(); return; }
            try {
                await api('POST', '/sections/order', { group_id: gid, ids });
                ids.forEach((id, i) => { S.sections.find((x) => x.id === id).position = i; });
            } catch (err) { toast(err.message); }
            renderGrid();
        };
        const up = (ev) => end(ev, false);
        const cancel = (ev) => end(ev, true);
        grip.addEventListener('pointermove', move);
        grip.addEventListener('pointerup', up);
        grip.addEventListener('pointercancel', cancel);
    }

    function renderGrid() {
        const grid = $('#grid');
        if (!grid) return;
        grid.replaceChildren();
        renderTagbar();
        if (searching()) {
            const bang = parseBang($('#search').value);
            if (bang) {
                grid.append(h('div', { class: 'empty', text: `Enter: „${bang.text}“ bei ${bang.engine[1][0]} suchen` }));
                return;
            }
            const match = matcher();
            const box = h('div', { class: 'grid plain' + (listMode() ? ' listv' : '') });
            let found = 0;
            for (const g of groupsSorted()) {
                const hits = dialsOf(g.id).filter(match);
                if (!hits.length) continue;
                found += hits.length;
                box.append(h('div', { class: 'group-label', text: g.title }), ...hits.map((d) => tileEl(d)));
            }
            if (!found) {
                grid.append(h('div', { class: 'empty', text: ui.query && !ui.tags.size ? `Keine Treffer. Enter sucht bei ${currentEngine()[1][0]}.` : 'Keine Treffer.' }));
            } else {
                grid.append(box);
            }
            markSelection();
            return;
        }
        if (typeof ui.group !== 'number') { // automatic tabs have no sections
            const box = h('div', { class: 'grid plain' + (listMode() ? ' listv' : '') }, dialsOf(ui.group).map((d) => tileEl(d)));
            grid.append(box.children.length ? box : h('div', { class: 'empty', text: 'Noch nichts zu zeigen.' }));
            return;
        }
        const dials = dialsOf(ui.group);
        const secs = sectionsOf(ui.group);
        secs.forEach((sec, i) => grid.append(sectionEl(sec, dials.filter((d) => secOf(d)?.id === sec.id), i === 0)));
        grid.append(h('button', { type: 'button', class: 'add-section', text: '+ Abschnitt', onclick: () => editSection(null) }));
    }

    // Create or edit a section; the look is stored per section (no light/dark pairs: use opacity to stay readable on both).
    function editSection(sec) {
        const gid = sec ? sec.group_id : realGroupId();
        const st = sec ? { ...sec } : { title: '', title_center: false, width: 50, mode: 'inherit', cols: 1, tile_size: '', ratio: '', bg_color: '#1971c2', bg_opacity: 30, border_color: '#74c0fc', border_width: 1, radius: 14, padding: 14 };
        let dlg;
        const msg = h('p', { class: 'msg err', hidden: true });
        const sel = (label, key, options, num = false) => h('label', { class: 'field' }, h('span', { text: label }),
            h('select', { onchange: (e) => { st[key] = num ? Number(e.target.value) : e.target.value; } },
                options.map(([v, t]) => h('option', { value: v, text: t, selected: String(st[key]) === String(v) }))));
        const num = (label, key, min, max) => h('label', { class: 'field' }, h('span', { text: label }),
            h('input', { type: 'number', min, max, value: st[key], oninput: (e) => { st[key] = e.target.value === '' ? 0 : Number(e.target.value); } }));
        const colorBox = (label, key, fallback) => {
            const inp = h('input', { type: 'color', value: st[key] || fallback, disabled: !st[key], oninput: (e) => { st[key] = e.target.value; } });
            const none = h('input', { type: 'checkbox', checked: !st[key], onchange: (e) => { st[key] = e.target.checked ? '' : inp.value; inp.disabled = e.target.checked; } });
            return h('div', { class: 'field' }, h('span', { text: label }), h('div', { class: 'set inline' }, inp, h('label', { class: 'chk-box' }, none, 'keine')));
        };
        const PALETTE = ['#e03131', '#f08c00', '#2f9e44', '#0c8599', '#1971c2', '#7048e8', '#c2255c', '#495057'];
        const pal = h('div', { class: 'pal' }, PALETTE.map((c) => h('button', { type: 'button', class: 'swatch', 'aria-label': c, onclick: () => {
            st.bg_color = c; st.border_color = c;
            [...dlg.querySelectorAll('input[type=color]')].forEach((i) => { i.value = c; i.disabled = false; });
            dlg.querySelectorAll('.set.inline input[type=checkbox]').forEach((i) => { i.checked = false; });
        } }, (() => { const i = h('i'); i.style.background = c; return i; })())));
        const save = async (btn) => {
            btn.disabled = true; msg.hidden = true;
            const body = { ...st };
            delete body.id; delete body.group_id; delete body.position; delete body.kind;
            try {
                if (sec) {
                    Object.assign(sec, await api('PUT', `/sections/${sec.id}`, body));
                } else {
                    S.sections.push(await api('POST', '/sections', { ...body, group_id: gid }));
                }
                dlg.close(); renderGrid();
            } catch (e) { msg.textContent = e.message; msg.hidden = false; btn.disabled = false; }
        };
        const move = async (dir) => {
            const arr = sectionsOf(gid); const i = arr.findIndex((x) => x.id === sec.id); const j = i + dir;
            if (j < 0 || j >= arr.length) return;
            const ids = arr.map((x) => x.id); [ids[i], ids[j]] = [ids[j], ids[i]];
            await api('POST', '/sections/order', { group_id: gid, ids });
            ids.forEach((id, p) => { S.sections.find((x) => x.id === id).position = p; });
            renderGrid();
        };
        const saveBtn = h('button', { class: 'btn primary', text: 'Speichern', onclick: (e) => save(e.currentTarget) });
        dlg = openDialog(h('form', { class: 'dlg', onsubmit: (e) => { e.preventDefault(); saveBtn.click(); } },
            dlgHead(sec ? 'Abschnitt bearbeiten' : 'Abschnitt hinzufügen', () => dlg),
            h('div', { class: 'dlg-body' },
                h('label', { class: 'field' }, h('span', { text: 'Titel (leer = ohne Titelzeile)' }),
                    h('input', { type: 'text', maxLength: 80, value: st.title, autofocus: !sec, oninput: (e) => { st.title = e.target.value; } })),
                h('div', { class: 'row' },
                    sel('Titel-Ausrichtung', 'title_center', [[false, 'Links'], [true, 'Zentriert']]),
                    sel('Breite', 'width', [[25, '1/4'], [33, '1/3'], [50, '1/2'], [67, '2/3'], [75, '3/4'], [100, 'Volle Breite']], true)),
                h('div', { class: 'row' },
                    sel('Darstellung', 'mode', [['inherit', 'Kacheln (Handy: wie Einstellung)'], ['tiles', 'Kacheln'], ['list', 'Liste']]),
                    sel('Listenspalten (Desktop)', 'cols', [[1, '1'], [2, '2'], [3, '3']], true)),
                h('div', { class: 'row' },
                    sel('Kachelgröße', 'tile_size', [['', 'Wie global'], ['s', 'Klein'], ['m', 'Mittel'], ['l', 'Groß']]),
                    sel('Seitenverhältnis', 'ratio', [['', 'Wie global'], ['1:1', '1 : 1'], ['4:3', '4 : 3'], ['16:10', '16 : 10'], ['16:9', '16 : 9']])),
                h('span', { class: 'lbl', text: 'Aussehen' }),
                pal,
                h('div', { class: 'row' }, colorBox('Hintergrund', 'bg_color', '#1971c2'), num('Deckkraft (0–100 %)', 'bg_opacity', 0, 100)),
                h('div', { class: 'row' }, colorBox('Rahmenfarbe', 'border_color', '#74c0fc'), num('Rahmenstärke (0–8 px)', 'border_width', 0, 8)),
                h('div', { class: 'row' }, num('Eckenradius (0–40 px)', 'radius', 0, 40), num('Innenabstand (0–40 px)', 'padding', 0, 40)),
                sec ? h('div', { class: 'actions' },
                    h('button', { type: 'button', class: 'btn', text: 'Nach vorn', onclick: () => move(-1) }),
                    h('button', { type: 'button', class: 'btn', text: 'Nach hinten', onclick: () => move(1) }),
                    h('button', { type: 'button', class: 'btn danger', text: 'Löschen', onclick: async () => {
                        if (!confirm('Abschnitt löschen? Die Lesezeichen wandern in den ersten Abschnitt des Tabs.')) return;
                        try {
                            await api('DELETE', `/sections/${sec.id}`);
                            await refreshState(); dlg.close(); renderGrid();
                        } catch (e) { msg.textContent = e.message; msg.hidden = false; }
                    } })) : null,
                msg),
            h('div', { class: 'dlg-foot' }, h('button', { class: 'btn', type: 'button', text: 'Abbrechen', onclick: () => dlg.close() }), saveBtn)));
    }

    // ---------- menus ----------

    function closeMenu() { $('.menu')?.remove(); }
    function showMenu(items, x, y) {
        closeMenu();
        const menu = h('div', { class: 'menu', role: 'menu' }, items.map((it) => it === '-' ? h('hr') : h('button', {
            role: 'menuitem', class: it.danger ? 'danger' : '', text: it.label,
            onclick: () => { closeMenu(); it.fn(); },
        })));
        document.body.append(menu);
        const r = menu.getBoundingClientRect();
        menu.style.left = Math.max(8, Math.min(x, innerWidth - r.width - 8)) + 'px';
        menu.style.top = Math.max(8, Math.min(y, innerHeight - r.height - 8)) + 'px';
    }
    document.addEventListener('pointerdown', (e) => { if (!e.target.closest('.menu')) closeMenu(); }, true);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });
    window.addEventListener('scroll', closeMenu, { passive: true });

    function tileMenu(d, x, y) {
        showMenu([
            { label: 'In neuem Tab öffnen', fn: () => window.open(d.url, '_blank', 'noopener,noreferrer') },
            { label: 'Bearbeiten', fn: () => editDial(d) },
            { label: 'URL kopieren', fn: () => copyText(d.url) },
            { label: 'Jetzt prüfen', fn: async () => { await api('POST', '/checks/run', { ids: [d.id] }); await refreshState(); toast('Prüfung läuft …'); } },
            { label: 'Vorschau neu erzeugen', fn: async () => { await api('POST', `/dials/${d.id}/refresh`); await refreshState(); toast('Vorschau wird neu erzeugt …'); } },
            '-',
            { label: 'Entfernen', danger: true, fn: async () => {
                if (!confirm(`„${d.title}“ entfernen?`)) return;
                await api('DELETE', `/dials/${d.id}`);
                S.dials = S.dials.filter((x) => x.id !== d.id);
                renderGrid();
            } },
        ], x, y);
    }

    async function copyText(text, done = 'URL kopiert') {
        try { await navigator.clipboard.writeText(text); toast(done); } catch { prompt('Zum Kopieren:', text); }
    }

    // ---------- drag & drop (mouse: drag; touch: long press, then drag; long press without moving opens the menu) ----------

    let drag = null;
    let suppressClick = false;
    let lastPointerType = 'mouse';

    document.addEventListener('touchmove', (e) => { if (drag && drag.armed) e.preventDefault(); }, { passive: false });

    function dragStart(e, el, d) {
        lastPointerType = e.pointerType;
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        drag = { el, d, id: e.pointerId, x0: e.clientX, y0: e.clientY, x: e.clientX, y: e.clientY, type: e.pointerType, armed: false, active: false };
        if (e.pointerType !== 'mouse') {
            drag.timer = setTimeout(() => {
                if (!drag) return;
                drag.armed = true;
                navigator.vibrate?.(12);
            }, 450);
        }
    }

    document.addEventListener('pointermove', (e) => {
        if (!drag || e.pointerId !== drag.id) return;
        drag.x = e.clientX;
        drag.y = e.clientY;
        if (!drag.active) {
            const dist = Math.hypot(e.clientX - drag.x0, e.clientY - drag.y0);
            if (drag.type === 'mouse') {
                if (dist > 6) beginDrag();
            } else if (!drag.armed) {
                if (dist > 8) { clearTimeout(drag.timer); drag = null; }
            } else if (dist > 6) {
                beginDrag();
            }
            return;
        }
        moveDrag();
    });

    function beginDrag() {
        closeMenu();
        const r = drag.el.getBoundingClientRect();
        drag.active = true;
        drag.dx = drag.x0 - r.left;
        drag.dy = drag.y0 - r.top;
        suppressClick = true;
        drag.ghost = drag.el.cloneNode(true);
        drag.ghost.classList.add('tile-ghost');
        drag.ghost.style.width = r.width + 'px';
        document.body.append(drag.ghost);
        drag.el.classList.add('dragging');
        drag.scroller = setInterval(() => {
            if (drag.y < 90) scrollBy(0, -14);
            else if (drag.y > innerHeight - 90) scrollBy(0, 14);
        }, 16);
        moveDrag();
    }

    function moveDrag() {
        drag.ghost.style.left = drag.x - drag.dx + 'px';
        drag.ghost.style.top = drag.y - drag.dy + 'px';
        const under = document.elementFromPoint(drag.x, drag.y);
        const tab = under?.closest('.tab[data-gid]');
        document.querySelectorAll('.tab.drop-target').forEach((t) => t.classList.remove('drop-target'));
        drag.overTab = null;
        if (tab && Number(tab.dataset.gid) !== ui.group) {
            tab.classList.add('drop-target');
            drag.overTab = Number(tab.dataset.gid);
            return;
        }
        const tile = under?.closest('.tile[data-id]');
        if (!tile) {
            // empty space of a section: append there
            const body = under?.closest('.sbody');
            if (body && reorderAllowed() && drag.el.parentNode !== body) {
                body.querySelector('.empty')?.remove();
                const add = body.querySelector('.add-tile');
                body.insertBefore(drag.el, add);
            }
            return;
        }
        if (tile === drag.el || !reorderAllowed()) return;
        const r = tile.getBoundingClientRect();
        const before = tile.parentNode.classList.contains('listv') ? drag.y < r.top + r.height / 2 : drag.x < r.left + r.width / 2;
        const ref = before ? tile : tile.nextElementSibling;
        if (ref === drag.el || ref === drag.el.nextElementSibling) return;
        tile.parentNode.insertBefore(drag.el, ref);
    }

    async function endDrag(cancelled) {
        if (!drag) return;
        const st = drag;
        drag = null;
        clearTimeout(st.timer);
        clearInterval(st.scroller);
        document.querySelectorAll('.tab.drop-target').forEach((t) => t.classList.remove('drop-target'));
        if (!st.active) {
            if (st.armed && !cancelled) tileMenu(st.d, st.x, st.y); // long press without movement
            return;
        }
        st.ghost.remove();
        st.el.classList.remove('dragging');
        setTimeout(() => { suppressClick = false; }, 60);
        if (cancelled) { renderGrid(); return; }
        try {
            if (st.overTab !== null && st.overTab !== undefined) {
                const ids = [...dialsOf(st.overTab).map((x) => x.id), st.d.id];
                await api('POST', '/dials/order', { group_id: st.overTab, ids });
                const target = sectionsOf(st.overTab)[0]?.id;
                ids.forEach((id, i) => { const x = S.dials.find((y) => y.id === id); x.group_id = st.overTab; x.section_id = target; x.position = i; });
                toast('Verschoben nach „' + S.groups.find((g) => g.id === st.overTab).title + '“');
            } else if (reorderAllowed()) {
                const sections = [...document.querySelectorAll('#grid .sec[data-sid]')].map((sec) => ({
                    id: Number(sec.dataset.sid), ids: [...sec.querySelectorAll('.tile[data-id]')].map((t) => Number(t.dataset.id)),
                }));
                await api('POST', '/dials/layout', { group_id: ui.group, sections });
                let pos = 0;
                for (const sec of sections) for (const id of sec.ids) { const x = S.dials.find((y) => y.id === id); x.position = pos++; x.section_id = sec.id; }
            }
        } catch (err) {
            toast(err.message);
        }
        renderGrid();
    }
    document.addEventListener('pointerup', (e) => { if (drag && e.pointerId === drag.id) endDrag(false); });
    document.addEventListener('pointercancel', (e) => { if (drag && e.pointerId === drag.id && !drag.armed) endDrag(true); });

    // ---------- dialogs ----------

    function openDialog(content, { wide = false, onClose } = {}) {
        const dlg = h('dialog', { class: wide ? 'wide' : '' }, content);
        dlg.addEventListener('close', () => { dlg.remove(); lockScroll(false); onClose?.(); });
        dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
        document.body.append(dlg);
        lockScroll(true);
        dlg.showModal();
        return dlg;
    }

    const dlgHead = (title, dlg) => h('div', { class: 'dlg-head' }, h('h2', { text: title }),
        h('button', { class: 'icon-btn', 'aria-label': 'Schließen', onclick: () => dlg().close() }, icon('x')));

    function editDial(d, pre = {}) {
        const st = {
            title: d?.title || pre.title || '', url: d?.url || pre.url || '', group_id: d?.group_id || pre.group_id || realGroupId(), section_id: d?.section_id || pre.section_id || 0,
            mode: d?.thumb_mode || 'auto', src: d?.thumb_src || '', fit: d?.fit || 'auto', file: null, no_check: !!d?.no_check,
            tags: [...(d?.tags || [])], note: d?.note || '', color: d?.color || '', badge: d?.badge || '', badge_color: d?.badge_color || '',
        };
        let dlg;
        const MODES = [
            ['auto', 'sparkle', 'Automatisch'], ['screenshot', 'shot', 'Screenshot'],
            ['url', 'link', 'Bild-URL'], ['upload', 'upload', 'Hochladen'], ['none', 'none', 'Keins'],
        ];
        const extra = h('div');
        let titleTouched = !!d || !!pre.title;
        const titleEl = h('input', { type: 'text', value: st.title, placeholder: 'Wird von der Seite geholt …', oninput: (e) => { st.title = e.target.value; titleTouched = true; } });
        // Fills an empty title from the page itself (the server fetches it).
        const fetchTitle = async () => {
            if (titleTouched || !st.url.trim()) return;
            try {
                const r = await api('GET', '/page-title?url=' + encodeURIComponent(st.url.trim()));
                if (r.title && !titleTouched) { st.title = r.title; titleEl.value = r.title; }
            } catch { /* optional */ }
        };
        const msg = h('p', { class: 'msg err', hidden: true });
        const preview = h('div', { class: 'preview' });

        const chips = h('div', { class: 'chips' });
        const tagInput = h('input', { type: 'text', placeholder: 'Tag eingeben, Enter zum Hinzufügen', maxLength: 30, autocapitalize: 'none' });
        tagInput.setAttribute('list', 'taglist'); // read-only as a DOM property
        const drawTags = () => chips.replaceChildren(...st.tags.map((t) => h('button', {
            type: 'button', class: 'tagchip on', title: 'Entfernen', text: t + ' ×', onclick: () => { st.tags = st.tags.filter((x) => x !== t); drawTags(); },
        })));
        const addTag = () => {
            const v = tagInput.value.trim().replace(/^#/, '');
            if (v && st.tags.length < 12 && !st.tags.some((x) => x.toLowerCase() === v.toLowerCase())) st.tags.push(v);
            tagInput.value = '';
            drawTags();
        };
        tagInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addTag(); }
            else if (e.key === 'Backspace' && !tagInput.value && st.tags.length) { st.tags.pop(); drawTags(); }
        });
        tagInput.addEventListener('blur', addTag);
        const knownTags = [...new Set(S.dials.flatMap((x) => x.tags))].sort((a, b) => a.localeCompare(b, 'de'));
        drawTags();

        const colorRow = (label, key, fallback) => {
            const row = h('div', { class: 'set' });
            const draw = () => row.replaceChildren(h('span', { text: label }), st[key]
                ? h('span', { class: 'inline' },
                    h('input', { type: 'color', value: st[key], 'aria-label': label, oninput: (e) => { st[key] = e.target.value; } }),
                    h('button', { type: 'button', class: 'btn', text: 'Entfernen', onclick: () => { st[key] = ''; draw(); } }))
                : h('button', { type: 'button', class: 'btn', text: 'Festlegen', onclick: () => { st[key] = fallback; draw(); } }));
            draw();
            return row;
        };

        const renderPreview = () => {
            preview.replaceChildren();
            if (st.file) {
                const thumb = h('div', { class: 'tile' }, h('div', { class: 'thumb' }, h('img', { class: 'cover', src: URL.createObjectURL(st.file), alt: '' })));
                preview.append(thumb);
            } else if (d?.thumb && st.mode !== 'none') {
                preview.append(h('div', { class: 'tile' }, h('div', { class: 'thumb' }, h('img', { class: fitOf(d), src: d.thumb, alt: '' }))));
            }
        };
        const renderExtra = () => {
            extra.replaceChildren();
            if (st.mode === 'url') {
                extra.append(h('label', { class: 'field' }, h('span', { text: 'Bild-URL' }),
                    h('input', { type: 'url', value: st.src, placeholder: 'https://…/logo.png', oninput: (e) => { st.src = e.target.value; } })));
            } else if (st.mode === 'upload') {
                extra.append(h('label', { class: 'field' }, h('span', { text: 'Bilddatei (max. 12 MB)' }),
                    h('input', { type: 'file', accept: 'image/*', onchange: (e) => { st.file = e.target.files[0] || null; renderPreview(); } })));
            } else if ((st.mode === 'auto' || st.mode === 'screenshot') && !S.screenshots) {
                extra.append(h('p', { class: 'msg', text: st.mode === 'screenshot'
                    ? 'Auf diesem Server ist kein Chromium installiert – Screenshots sind nicht verfügbar.'
                    : 'Ohne Chromium gibt es nur Logos; Seiten ohne Logo bekommen eine Buchstaben-Kachel.' }));
            }
            if (['url', 'upload'].includes(st.mode)) {
                extra.append(h('label', { class: 'field' }, h('span', { text: 'Darstellung' }),
                    h('select', { onchange: (e) => { st.fit = e.target.value; } },
                        [['auto', 'Automatisch'], ['cover', 'Füllen'], ['contain', 'Einpassen']].map(([v, t]) => h('option', { value: v, text: t, selected: st.fit === v })))));
            }
        };
        const modes = h('div', { class: 'modes' });
        const renderModes = () => modes.replaceChildren(...MODES.map(([m, ic, label]) => h('button', {
            type: 'button', class: 'mode' + (st.mode === m ? ' on' : ''), onclick: () => { st.mode = m; renderModes(); renderExtra(); },
        }, icon(ic), label)));

        const save = async (btn) => {
            msg.hidden = true;
            btn.disabled = true;
            try {
                const payload = { title: st.title, url: st.url, group_id: Number(st.group_id), section_id: Number(st.section_id) || undefined, thumb_mode: st.mode, fit: st.fit, no_check: st.no_check,
                    tags: st.tags, note: st.note, color: st.color || null, badge: st.badge, badge_color: st.badge_color || null };
                if (st.mode === 'url') payload.thumb_src = st.src;
                if (st.mode === 'upload' && !st.file && !d?.thumb) throw new Error('Bitte eine Bilddatei wählen.');
                const saved = d ? await api('PUT', `/dials/${d.id}`, payload) : await api('POST', '/dials', payload);
                if (st.mode === 'upload' && st.file) {
                    const fd = new FormData();
                    fd.append('file', st.file);
                    await api('POST', `/dials/${saved.id}/thumb`, fd);
                }
                dlg.close();
                await refreshState();
            } catch (e) {
                msg.textContent = e.message;
                msg.hidden = false;
                btn.disabled = false;
            }
        };

        const secSelect = h('select', { onchange: (e) => { st.section_id = Number(e.target.value); } });
        const fillSections = () => {
            const list = sectionsOf(Number(st.group_id));
            if (!list.some((x) => x.id === Number(st.section_id))) st.section_id = list[0]?.id || 0;
            secSelect.replaceChildren(...list.map((x, i) => h('option', { value: x.id, text: x.title || (list.length > 1 ? `Abschnitt ${i + 1}` : 'Standard'), selected: x.id === Number(st.section_id) })));
        };
        fillSections();
        const body = h('div', { class: 'dlg-body' },
            h('div', { class: 'row' },
                h('label', { class: 'field' }, h('span', { text: 'URL' }),
                    h('input', { type: 'text', value: st.url, placeholder: 'https://', autocapitalize: 'none', spellcheck: false, autofocus: !d, oninput: (e) => { st.url = e.target.value; }, onchange: fetchTitle })),
                h('label', { class: 'field' }, h('span', { text: 'Titel' }), titleEl)),
            h('div', { class: 'row' },
                h('label', { class: 'field' }, h('span', { text: 'Gruppe' }),
                    h('select', { onchange: (e) => { st.group_id = e.target.value; st.section_id = 0; fillSections(); } },
                        groupsSorted().map((g) => h('option', { value: g.id, text: g.title, selected: g.id === Number(st.group_id) })))),
                h('label', { class: 'field' }, h('span', { text: 'Abschnitt' }), secSelect)),
            h('div', { class: 'field' }, h('span', { text: 'Tags' }), chips, tagInput, h('datalist', { id: 'taglist' }, knownTags.map((t) => h('option', { value: t })))),
            h('label', { class: 'field' }, h('span', { text: 'Notiz' }),
                h('textarea', { class: 'plain', rows: 2, maxLength: 1000, placeholder: 'Optional, wird bei der Suche mitgefunden', value: st.note, oninput: (e) => { st.note = e.target.value; } })),
            h('span', { class: 'lbl', text: 'Vorschaubild' }), modes, extra, preview,
            h('span', { class: 'lbl', text: 'Aussehen' }),
            colorRow('Kachelfarbe', 'color', '#2563eb'),
            h('div', { class: 'set' }, h('span', { text: 'Plakette (Kurztext)' }),
                h('input', { type: 'text', class: 'short', maxLength: 12, placeholder: 'z. B. NAS', value: st.badge, oninput: (e) => { st.badge = e.target.value; } })),
            colorRow('Plakettenfarbe', 'badge_color', S.settings.accent),
            h('label', { class: 'chk-box' }, h('input', { type: 'checkbox', checked: st.no_check, onchange: (e) => { st.no_check = e.target.checked; } }), 'Nicht auf Erreichbarkeit prüfen'),
            msg);
        const saveBtn = h('button', { class: 'btn primary', text: 'Speichern', onclick: (e) => save(e.currentTarget) });
        dlg = openDialog(h('form', { class: 'dlg', onsubmit: (e) => { e.preventDefault(); saveBtn.click(); } },
            dlgHead(d ? 'Lesezeichen bearbeiten' : 'Lesezeichen hinzufügen', () => dlg), body,
            h('div', { class: 'dlg-foot' }, h('button', { class: 'btn', type: 'button', text: 'Abbrechen', onclick: () => dlg.close() }), saveBtn)));
        renderModes(); renderExtra(); renderPreview();
        if (!d && pre.url && !pre.title) fetchTitle();
    }

    // ---------- quick add (bookmarklet, paste, drop) ----------

    function bookmarkletCode() {
        const target = location.origin + S.base + '/add?popup=1';
        return `javascript:(function(){var u='${target}&url='+encodeURIComponent(location.href)+'&title='+encodeURIComponent(document.title);`
            + `var w=window.open(u,'startseite_add','width=480,height=640');if(!w)location.href=u;})();`;
    }

    document.addEventListener('paste', (e) => {
        if (document.querySelector('dialog[open]') || e.target.closest?.('input, textarea, select, [contenteditable]')) return;
        const text = (e.clipboardData?.getData('text') || '').trim();
        if (/^https?:\/\/\S+$/i.test(text)) { e.preventDefault(); editDial(null, { url: text }); }
    });

    const droppedUrl = (e) => (e.dataTransfer?.getData('text/uri-list') || e.dataTransfer?.getData('text/plain') || '')
        .split(/\r?\n/).map((l) => l.trim()).find((l) => /^https?:\/\/\S+$/i.test(l)) || '';
    const clearDropMarks = () => document.querySelectorAll('.tab.drop-target').forEach((t) => t.classList.remove('drop-target'));
    document.addEventListener('dragover', (e) => {
        const types = [...(e.dataTransfer?.types || [])];
        if (document.querySelector('dialog[open]') || !types.some((t) => t === 'text/uri-list' || t === 'text/plain')) return;
        e.preventDefault();
        const tab = e.target.closest?.('.tab');
        clearDropMarks();
        tab?.classList.add('drop-target');
    });
    document.addEventListener('drop', (e) => {
        clearDropMarks();
        const url = droppedUrl(e);
        if (!url || document.querySelector('dialog[open]')) return;
        e.preventDefault();
        const tab = e.target.closest?.('.tab');
        editDial(null, { url, group_id: tab ? Number(tab.dataset.gid) : undefined });
    });

    // ---------- options ----------

    const toggle = (label, key, extra = {}) => h('div', { class: 'set' }, h('span', { text: label }),
        h('span', { class: 'switch' }, h('input', { type: 'checkbox', checked: !!S.settings[key], 'aria-label': label,
            onchange: (e) => setSetting(key, e.target.checked, extra) }), h('i')));

    const range = (label, key, min, max, step = 1, fmt = (v) => v) => {
        const val = h('span', { class: 'val', text: fmt(S.settings[key]) });
        return h('div', { class: 'set' }, h('span', { text: label }),
            h('input', { type: 'range', min, max, step, value: S.settings[key], 'aria-label': label,
                oninput: (e) => { val.textContent = fmt(Number(e.target.value)); setSetting(key, Number(e.target.value)); } }), val);
    };

    const select = (label, key, options, onchange) => h('div', { class: 'set' }, h('span', { text: label }),
        h('select', { 'aria-label': label, onchange: (e) => (onchange || ((v) => setSetting(key, v)))(e.target.value) },
            options.map(([v, t]) => h('option', { value: v, text: t, selected: String(S.settings[key]) === String(v) }))));

    const color = (label, key) => h('div', { class: 'set' }, h('span', { text: label }),
        h('input', { type: 'color', value: S.settings[key], 'aria-label': label, oninput: (e) => setSetting(key, e.target.value, { render: false }) }));

    const normUrl = (u) => u.toLowerCase().replace(/^https?:\/\//, '').replace(/^www\./, '').replace(/\/+$/, '');

    function openOptions(start = 'general') {
        let section = start;
        let themeTab = effectiveTheme();
        let dlg;
        const body = h('div', { class: 'opts-body' });
        const nav = h('nav', { class: 'opts-nav' });
        const navLabel = h('span');
        const optsEl = h('div', { class: 'opts' });
        const navToggle = h('button', { type: 'button', class: 'opts-toggle', 'aria-label': 'Bereich wählen', onclick: () => optsEl.classList.toggle('nav-open') }, icon('menu'), navLabel);
        const SECTIONS = [['general', 'Allgemein'], ['themes', 'Themen'], ['bookmarks', 'Lesezeichen'], ['check', 'Prüfung'], ['cleanup', 'Aufräumen'], ['groups', 'Gruppen'], ['security', 'Sicherheit'], ['system', 'System']];

        const seg = (items, current, fn) => h('div', { class: 'seg' }, items.map(([v, t]) =>
            h('button', { type: 'button', class: current === v ? 'on' : '', text: t, onclick: () => fn(v) })));

        const views = {
            general: () => [
                h('h3', { text: 'Thema' }),
                seg([['auto', 'Auto'], ['light', 'Hell'], ['dark', 'Dunkel']], S.settings.theme, (v) => { setSetting('theme', v, { render: false }); show(); }),
                h('h3', { text: 'Seite' }),
                h('label', { class: 'field' }, h('span', { text: 'Name der Seite' }),
                    h('input', { type: 'text', value: S.settings.app_name, maxLength: 60,
                        onchange: (e) => { const v = e.target.value.trim(); if (v) setSetting('app_name', v, { render: false }); } })),
                h('h3', { text: 'Raster' }),
                range('Maximale Spalten', 'columns', 2, 20),
                range('Abstand', 'spacing', 0, 40, 1, (v) => v + ' px'),
                h('div', { class: 'set' }, h('span', { text: 'Volle Breite' }),
                    h('span', { class: 'switch' }, h('input', { type: 'checkbox', checked: S.settings.max_width === 0, 'aria-label': 'Volle Breite',
                        onchange: (e) => { setSetting('max_width', e.target.checked ? 0 : 1400); show(); } }), h('i'))),
                S.settings.max_width !== 0 ? range('Maximale Breite', 'max_width', 600, 3000, 50, (v) => v + ' px') : null,
                toggle('Vertikal zentrieren', 'center_vertical'),
                toggle('Kompakte Ansicht', 'compact'),
                toggle('Suchfeld anzeigen', 'show_web_search', { render: false }),
                select('Suchanbieter', 'web_search_engine', engineList().map(([k, [n]]) => [k, n])),
                h('label', { class: 'field' }, h('span', { text: 'Eigener Suchanbieter (URL mit %s)' }),
                    h('input', { type: 'url', value: S.settings.web_search_custom, placeholder: 'https://example.com/search?q=%s', autocapitalize: 'none', spellcheck: false,
                        onchange: async (e) => {
                            try {
                                await api('PUT', '/settings', { web_search_custom: e.target.value });
                                S.settings.web_search_custom = e.target.value.trim();
                                if (!S.settings.web_search_custom && S.settings.web_search_engine === 'custom') setSetting('web_search_engine', 'google', { render: false });
                                applyTheme(); show();
                            } catch (err) { toast(err.message); }
                        } })),
                range('Schriftgröße', 'font_scale', 80, 140, 5, (v) => v + ' %'),
            ],
            themes: () => {
                const key = 'bg_' + themeTab;
                const bg = S.settings[key];
                // Merge into the current value, not the one captured when the view was built: several fields change without a re-render.
                const upd = (patch) => setSetting(key, { ...S.settings[key], ...patch }, { render: false });
                const prev = h('div', { class: 'bgprev' });
                prev.style.backgroundColor = bg.color || 'transparent';
                if (bg.url) prev.style.backgroundImage = `url("${bg.url}")`;
                const file = h('input', { type: 'file', accept: 'image/*', hidden: true, onchange: async (e) => {
                    const f = e.target.files[0];
                    if (!f) return;
                    const fd = new FormData();
                    fd.append('file', f);
                    try { const r = await api('POST', `/background/${themeTab}`, fd); S.settings[key] = { ...S.settings[key], url: r.url, file: 'x' }; applyTheme(); show(); } catch (err) { toast(err.message); }
                } });
                return [
                    seg([['light', 'Helles Design'], ['dark', 'Dunkles Design']], themeTab, (v) => { themeTab = v; show(); }),
                    h('h3', { text: 'Hintergrund' }), prev, file,
                    h('div', { class: 'actions' },
                        h('button', { class: 'btn', text: 'Bild hochladen …', onclick: () => file.click() }),
                        bg.url ? h('button', { class: 'btn danger', text: 'Bild entfernen', onclick: async () => {
                            await api('DELETE', `/background/${themeTab}`);
                            S.settings[key] = { ...S.settings[key], url: '', file: '' };
                            applyTheme(); show();
                        } }) : null),
                    h('div', { class: 'set' }, h('span', { text: 'Hintergrundfarbe' }),
                        h('input', { type: 'color', value: bg.color || '#ffffff', onchange: (e) => upd({ color: e.target.value }) })),
                    h('div', { class: 'set' }, h('span', { text: 'Größe' }), h('select', { onchange: (e) => upd({ size: e.target.value }) },
                        [['cover', 'Füllen'], ['contain', 'Einpassen'], ['auto', 'Original']].map(([v, t]) => h('option', { value: v, text: t, selected: bg.size === v })))),
                    h('div', { class: 'set' }, h('span', { text: 'Position' }), h('select', { onchange: (e) => upd({ position: e.target.value }) },
                        [['center center', 'Mitte'], ['center top', 'Oben'], ['center bottom', 'Unten'], ['left center', 'Links'], ['right center', 'Rechts']]
                            .map(([v, t]) => h('option', { value: v, text: t, selected: bg.position === v })))),
                    h('div', { class: 'set' }, h('span', { text: 'Wiederholen' }), h('select', { onchange: (e) => upd({ repeat: e.target.value }) },
                        [['no-repeat', 'Nicht wiederholen'], ['repeat', 'Wiederholen']].map(([v, t]) => h('option', { value: v, text: t, selected: bg.repeat === v })))),
                    h('div', { class: 'set' }, h('span', { text: 'Deckkraft' }),
                        h('input', { type: 'range', min: 0, max: 100, value: bg.opacity, onchange: (e) => upd({ opacity: Number(e.target.value) }) })),
                    h('h3', { text: 'Kacheln (' + (themeTab === 'dark' ? 'dunkel' : 'hell') + ')' }),
                    color('Kachel-Hintergrund', 'tile_bg_' + themeTab),
                    color('Kachel-Text', 'tile_text_' + themeTab),
                    h('h3', { text: 'Allgemein' }),
                    color('Akzentfarbe', 'accent'),
                ];
            },
            bookmarks: () => {
                const missingList = S.dials.filter((d) => !d.thumb);
                const missing = missingList.length;
                const seen = new Map();
                const dups = [];
                const dupGroups = new Map();
                for (const d of [...S.dials].sort((a, b) => a.id - b.id)) {
                    const k = normUrl(d.url);
                    if (seen.has(k)) { dups.push(d.id); dupGroups.get(k).push(d); } else { seen.set(k, d.id); dupGroups.set(k, [d]); }
                }
                const groupName = (id) => S.groups.find((g) => g.id === id)?.title || '';
                const removeOne = async (d) => {
                    if (!confirm(`„${d.title}“ löschen?`)) return;
                    try { await api('POST', '/dials/bulk-delete', { ids: [d.id] }); await refreshState(); show(); } catch (err) { toast(err.message); }
                };
                const rows = (items, info, extra) => h('div', { class: 'clist' }, items.map((d) => h('div', { class: 'crow nochk' },
                    h('div', { class: 'ct' }, d.title, ' ', h('span', { class: 'badge grp', text: 'Tab: ' + placeOf(d) })),
                    h('div', { class: 'cu' }, h('a', { href: d.url, target: '_blank', rel: 'noopener noreferrer', text: d.url })),
                    h('div', { class: 'cr', text: info(d) }),
                    h('div', { class: 'ca' },
                        h('button', { class: 'btn', type: 'button', text: 'Bearbeiten', onclick: () => editDial(d) }),
                        extra ? extra(d) : null,
                        h('button', { class: 'btn danger', type: 'button', text: 'Löschen', onclick: () => removeOne(d) })))));
                return [
                    h('h3', { text: 'Darstellung' }),
                    toggle('Titel anzeigen', 'show_title'),
                    toggle('Handy: Liste statt Kacheln', 'mobile_list'),
                    toggle('Besuchszähler anzeigen', 'show_visits'),
                    toggle('Links in neuem Tab öffnen', 'open_new_tab'),
                    toggle('„+“-Kachel anzeigen', 'show_add_button'),
                    range('Abgerundete Ecken', 'border_radius', 0, 32, 1, (v) => v + ' px'),
                    select('Titelausrichtung', 'title_align', [['left', 'Links'], ['center', 'Mitte'], ['right', 'Rechts']]),
                    select('Seitenverhältnis', 'ratio', [['1:1', '1 : 1'], ['4:3', '4 : 3'], ['16:10', '16 : 10'], ['16:9', '16 : 9']]),
                    h('h3', { text: 'Sortierung' }),
                    select('Lesezeichen anordnen nach', 'order_by', [['manual', 'Manuell (Drag & Drop)'], ['visits', 'Besuche'], ['recent', 'Zuletzt besucht'], ['title', 'Titel'], ['created', 'Neueste zuerst']]),
                    h('h3', { text: 'Schnell hinzufügen' }),
                    h('p', { class: 'msg', text: 'Lesezeichen-Button: einmal in die Lesezeichenleiste ziehen, danach öffnet ein Klick auf einer beliebigen Seite ein kleines Fenster zum Hinzufügen (mit Gruppenauswahl).' }),
                    h('div', { class: 'actions' },
                        h('a', { class: 'btn primary', href: bookmarkletCode(), draggable: true, text: '+ ' + S.settings.app_name, title: 'In die Lesezeichenleiste ziehen',
                            onclick: (e) => { e.preventDefault(); toast('Nicht anklicken – in die Lesezeichenleiste ziehen.'); } }),
                        h('button', { class: 'btn', text: 'Code kopieren', onclick: () => copyText(bookmarkletCode(), 'Code kopiert – als URL eines neuen Lesezeichens einfügen') })),
                    h('p', { class: 'msg', text: 'Handy (Android): Startseite als App installieren, dann im Teilen-Menü „' + S.settings.app_name + '“ wählen. Am Computer: eine kopierte URL mit Strg+V auf der Startseite einfügen oder einen Link aus der Adressleiste auf die Seite (oder einen Gruppen-Tab) ziehen.' }),
                    h('h3', { text: 'Vorschaubilder' }),
                    h('p', { class: 'msg', text: `${missing} von ${S.dials.length} Lesezeichen ohne Vorschaubild.` + (S.jobs.pending + S.jobs.running ? ` ${S.jobs.pending + S.jobs.running} in Arbeit.` : '') }),
                    h('div', { class: 'actions' }, h('button', { class: 'btn', text: 'Fehlende neu erzeugen', disabled: !missing, onclick: async () => {
                        const r = await api('POST', '/thumbs/missing');
                        toast(r.queued + ' Vorschaubilder werden erzeugt …');
                        await refreshState();
                        show();
                    } })),
                    missing ? rows(missingList, (d) => (['pending', 'running'].includes(d.thumb_status) ? 'wird erzeugt …' : d.thumb_error || 'Buchstaben-Kachel'),
                        (d) => h('button', { class: 'btn', type: 'button', text: 'Neu erzeugen', disabled: ['pending', 'running'].includes(d.thumb_status), onclick: async () => {
                            try { await api('POST', `/dials/${d.id}/refresh`); await refreshState(); show(); } catch (err) { toast(err.message); }
                        } })) : null,
                    h('h3', { text: 'Duplikate' }),
                    h('p', { class: 'msg', text: dups.length ? `${dups.length} doppelte Lesezeichen gefunden.` : 'Keine Duplikate.' }),
                    dups.length ? h('div', { class: 'actions' }, h('button', { class: 'btn danger', text: 'Duplikate entfernen', onclick: async () => {
                        if (!confirm(`${dups.length} doppelte Lesezeichen entfernen (das jeweils älteste bleibt)?`)) return;
                        await api('POST', '/dials/bulk-delete', { ids: dups });
                        await refreshState();
                        show();
                    } })) : null,
                    ...[...dupGroups.values()].filter((g) => g.length > 1).flatMap((g) => [
                        h('p', { class: 'msg', text: `${g.length}× ${normUrl(g[0].url)}` }),
                        rows(g, (d) => (d === g[0] ? 'Ältester Eintrag (bleibt bei „Duplikate entfernen“)' : 'Duplikat')),
                    ]),
                ];
            },
            check: () => checkView(),
            cleanup: () => cleanupView(),
            security: () => securityView(),
            groups: () => {
                const list = h('div', { class: 'glist' });
                const draw = () => list.replaceChildren(...groupsSorted().map((g, i, arr) => h('div', { class: 'gitem' },
                    h('input', { type: 'text', value: g.title, maxLength: 80, 'aria-label': 'Gruppenname', onchange: async (e) => {
                        const v = e.target.value.trim();
                        if (!v) { e.target.value = g.title; return; }
                        await api('PUT', `/groups/${g.id}`, { title: v }); g.title = v; renderTabs();
                    } }),
                    h('label', { class: 'chk-box', title: 'Lesezeichen dieser Gruppe nicht auf Erreichbarkeit prüfen' },
                        h('input', { type: 'checkbox', checked: !!g.no_check, onchange: async (e) => {
                            await api('PUT', `/groups/${g.id}`, { no_check: e.target.checked }); g.no_check = e.target.checked; await refreshState();
                        } }), 'nicht prüfen'),
                    h('button', { class: 'icon-btn', title: 'Nach oben', disabled: i === 0, onclick: () => move(arr, i, -1) }, icon('up')),
                    h('button', { class: 'icon-btn', title: 'Nach unten', disabled: i === arr.length - 1, onclick: () => move(arr, i, 1) }, icon('down')),
                    h('button', { class: 'icon-btn', title: 'Gruppe löschen', onclick: async () => {
                        const n = S.dials.filter((d) => d.group_id === g.id).length;
                        if (!confirm(`Gruppe „${g.title}“ mit ${n} Lesezeichen löschen?`)) return;
                        try {
                            await api('DELETE', `/groups/${g.id}`);
                            S.groups = S.groups.filter((x) => x.id !== g.id);
                            S.dials = S.dials.filter((d) => d.group_id !== g.id);
                            if (ui.group === g.id) ui.group = groupsSorted()[0].id;
                            renderTabs(); renderGrid(); draw();
                        } catch (err) { toast(err.message); }
                    } }, icon('x')))));
                const move = async (arr, i, dir) => {
                    const ids = arr.map((g) => g.id);
                    [ids[i], ids[i + dir]] = [ids[i + dir], ids[i]];
                    await api('POST', '/groups/order', { ids });
                    ids.forEach((id, p) => { S.groups.find((g) => g.id === id).position = p; });
                    renderTabs(); draw();
                };
                draw();
                const secBox = h('div', { class: 'glist' });
                const drawSecs = () => secBox.replaceChildren(...groupsSorted().map((g) => {
                    const arr = sectionsOf(g.id);
                    return h('div', { class: 'secgrp' }, h('div', { class: 'lbl', text: g.title }),
                        arr.map((x, i) => h('div', { class: 'gitem' },
                            h('span', { class: 'secname', text: (x.title || (arr.length > 1 ? 'Abschnitt ' + (i + 1) : 'Standard (ohne Titel)')) + ` · ${S.dials.filter((d) => secOf(d)?.id === x.id).length} Lesezeichen` }),
                            h('button', { class: 'icon-btn', title: 'Nach oben', disabled: i === 0, onclick: () => moveSec(g.id, arr, i, -1) }, icon('up')),
                            h('button', { class: 'icon-btn', title: 'Nach unten', disabled: i === arr.length - 1, onclick: () => moveSec(g.id, arr, i, 1) }, icon('down')),
                            h('button', { class: 'icon-btn', title: 'Abschnitt bearbeiten', onclick: () => editSection(x) }, icon('gear')))));
                }));
                const moveSec = async (gid, arr, i, dir) => {
                    const ids = arr.map((x) => x.id);
                    [ids[i], ids[i + dir]] = [ids[i + dir], ids[i]];
                    await api('POST', '/sections/order', { group_id: gid, ids });
                    ids.forEach((id, p) => { S.sections.find((x) => x.id === id).position = p; });
                    renderGrid(); drawSecs();
                };
                drawSecs();
                const nameInput = h('input', { type: 'text', placeholder: 'Neue Gruppe', maxLength: 80 });
                return [
                    h('h3', { text: 'Gruppen' }), list,
                    h('div', { class: 'gitem' }, nameInput, h('button', { class: 'btn primary', text: 'Hinzufügen', onclick: async () => {
                        const t = nameInput.value.trim();
                        if (!t) return;
                        const g = await api('POST', '/groups', { title: t });
                        S.groups.push(g); nameInput.value = ''; await refreshState(); renderTabs(); draw(); drawSecs();
                    } })),
                    h('h3', { text: 'Abschnitte' }),
                    h('p', { class: 'msg', text: 'Abschnitte legst du auf der Startseite mit „+ Abschnitt“ an. Hier ordnest und bearbeitest du sie.' }), secBox,
                    h('h3', { text: 'Automatische Gruppen' }),
                    toggle('„Meist besucht“ anzeigen', 'auto_top', { render: false }),
                    toggle('„Zuletzt hinzugefügt“ anzeigen', 'auto_recent', { render: false }),
                    range('Einträge pro automatischer Gruppe', 'auto_count', 3, 50, 1),
                    h('h3', { text: 'Verhalten' }),
                    toggle('Zuletzt benutzte Gruppe merken', 'keep_active_group', { render: false }),
                ];
            },
            system: () => {
                const status = h('p', { class: 'msg', hidden: true });
                const mode = h('select', {}, h('option', { value: 'replace', text: 'Alles ersetzen' }), h('option', { value: 'merge', text: 'Zu vorhandenen hinzufügen' }));
                const fileIn = h('input', { type: 'file', accept: '.zip,.json,.html,.htm,application/zip,application/json,text/html' });
                const pw = { cur: '', n1: '', n2: '' };
                const pwMsg = h('p', { class: 'msg', hidden: true });
                const un = { cur: '', name: S.username };
                const unMsg = h('p', { class: 'msg', hidden: true });

                // Scheduled backups: settings plus the list of files on the server.
                const backupList = h('div', { class: 'clist' }, h('p', { class: 'msg', text: 'Lädt …' }));
                const fmtSize = (n) => (n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB');
                const drawBackups = (list) => backupList.replaceChildren(...(list.length ? list.map((b) => h('div', { class: 'crow' },
                    h('span'),
                    h('div', { class: 'ct', text: new Date(b.time * 1000).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) + ' · ' + fmtSize(b.size) }),
                    h('span'),
                    h('div', { class: 'ca' },
                        h('a', { class: 'btn', href: `${S.base}/api/backups/${b.name}`, download: b.name, text: 'Herunterladen' }),
                        h('button', { class: 'btn danger', text: 'Löschen', onclick: async () => {
                            if (!confirm('Diese Sicherung löschen?')) return;
                            try { drawBackups((await api('DELETE', `/backups/${b.name}`)).backups); } catch (err) { toast(err.message); }
                        } })))) : [h('p', { class: 'msg', text: 'Noch keine Sicherungen.' })]));
                api('GET', '/backups').then((r) => drawBackups(r.backups)).catch((err) => { backupList.textContent = err.message; });
                const clampInt = (v, lo, hi) => Math.max(lo, Math.min(hi, Math.round(Number(v)) || lo));
                return [
                    h('h3', { text: 'Automatische Sicherung' }),
                    toggle('Regelmäßig sichern', 'backup_enabled', { render: false }),
                    select('Wie oft', 'backup_interval_hours', [[1, 'Stündlich'], [6, 'Alle 6 Stunden'], [12, 'Alle 12 Stunden'], [24, 'Täglich'], [72, 'Alle 3 Tage'], [168, 'Wöchentlich'], [720, 'Monatlich']],
                        (v) => setSetting('backup_interval_hours', Number(v), { render: false })),
                    h('div', { class: 'set' }, h('span', { text: 'Sicherungen behalten (1–100)' }),
                        h('input', { type: 'number', class: 'short', min: 1, max: 100, value: S.settings.backup_keep, 'aria-label': 'Anzahl Sicherungen',
                            onchange: (e) => { const v = clampInt(e.target.value, 1, 100); e.target.value = v; setSetting('backup_keep', v, { render: false }); } })),
                    h('p', { class: 'msg', text: 'Die Sicherungen liegen als ZIP im Ordner „backups“ des Datenverzeichnisses auf dem Server; ältere werden automatisch gelöscht. Wiederherstellen: unten über „Import“.' }),
                    h('div', { class: 'actions' }, h('button', { class: 'btn', text: 'Jetzt sichern', onclick: async (e) => {
                        e.currentTarget.disabled = true;
                        try { drawBackups((await api('POST', '/backups')).backups); toast('Sicherung erstellt'); } catch (err) { toast(err.message); }
                        e.target.disabled = false;
                    } })),
                    backupList,
                    h('h3', { text: 'Export' }),
                    h('div', { class: 'actions' }, h('a', { class: 'btn', href: S.base + '/api/export', download: '', text: 'Alles exportieren (ZIP)' })),
                    h('h3', { text: 'Import' }),
                    h('p', { class: 'msg', text: 'Akzeptiert eine Startseite-Sicherung (ZIP), einen Speed-Dial-2-Export (JSON) oder einen Lesezeichen-Export aus Chrome, Edge, Firefox oder Safari (HTML; Ordner werden zu Gruppen, vorhandene URLs übersprungen). Gleichnamige Gruppen werden zusammengeführt.' }),
                    h('div', { class: 'set' }, h('span', { text: 'Modus' }), mode),
                    fileIn,
                    h('div', { class: 'actions' }, h('button', { class: 'btn primary', text: 'Importieren', onclick: async (e) => {
                        const f = fileIn.files[0];
                        if (!f) { toast('Bitte eine Datei wählen.'); return; }
                        if (mode.value === 'replace' && !confirm('Alle vorhandenen Lesezeichen und Gruppen werden ersetzt. Fortfahren?')) return;
                        const fd = new FormData();
                        fd.append('file', f); fd.append('mode', mode.value);
                        e.currentTarget.disabled = true;
                        status.hidden = false; status.className = 'msg'; status.textContent = 'Import läuft …';
                        try {
                            const r = await api('POST', '/import', fd);
                            status.textContent = `${r.dials} Lesezeichen in ${r.groups} Gruppen importiert${r.skipped ? `, ${r.skipped} Duplikate übersprungen` : ''}. Seite wird neu geladen …`;
                            setTimeout(() => location.reload(), r.skipped ? 2500 : 1200);
                        } catch (err) { status.className = 'msg err'; status.textContent = err.message; e.target.disabled = false; }
                    } })), status,
                    h('h3', { text: 'Passwort ändern' }),
                    ...[['Aktuelles Passwort', 'cur', 'current-password'], ['Neues Passwort (mind. 12 Zeichen)', 'n1', 'new-password'], ['Neues Passwort wiederholen', 'n2', 'new-password']].map(([l, k, ac]) =>
                        h('label', { class: 'field' }, h('span', { text: l }), h('input', { type: 'password', autocomplete: ac, oninput: (e) => { pw[k] = e.target.value; } }))),
                    pwMsg,
                    h('div', { class: 'actions' }, h('button', { class: 'btn primary', text: 'Passwort speichern', onclick: async () => {
                        pwMsg.hidden = false;
                        if (pw.n1 !== pw.n2) { pwMsg.className = 'msg err'; pwMsg.textContent = 'Die neuen Passwörter stimmen nicht überein.'; return; }
                        try {
                            await api('POST', '/password', { current: pw.cur, new: pw.n1 });
                            pwMsg.className = 'msg'; pwMsg.textContent = 'Passwort geändert; andere Sitzungen wurden beendet.';
                        } catch (err) { pwMsg.className = 'msg err'; pwMsg.textContent = err.message; }
                    } })),
                    h('h3', { text: 'Benutzername ändern' }),
                    h('label', { class: 'field' }, h('span', { text: 'Neuer Benutzername' }), h('input', { type: 'text', value: S.username, maxlength: 64, autocomplete: 'username', oninput: (e) => { un.name = e.target.value; } })),
                    h('label', { class: 'field' }, h('span', { text: 'Aktuelles Passwort' }), h('input', { type: 'password', autocomplete: 'current-password', oninput: (e) => { un.cur = e.target.value; } })),
                    unMsg,
                    h('div', { class: 'actions' }, h('button', { class: 'btn primary', text: 'Benutzername speichern', onclick: async () => {
                        unMsg.hidden = false;
                        try {
                            const r = await api('POST', '/username', { current: un.cur, username: un.name });
                            S.username = r.username;
                            unMsg.className = 'msg'; unMsg.textContent = 'Benutzername geändert; andere Sitzungen wurden beendet.';
                            document.querySelectorAll('.acct-name').forEach((n) => { n.textContent = 'Angemeldet als ' + S.username; });
                        } catch (err) { unMsg.className = 'msg err'; unMsg.textContent = err.message; }
                    } })),
                    h('h3', { text: 'Konto' }),
                    h('p', { class: 'msg acct-name', text: 'Angemeldet als ' + S.username }),
                    h('div', { class: 'actions' }, h('button', { class: 'btn', text: 'Abmelden', onclick: logout })),
                ];
            },
        };

        const checkView = () => {
            const STATUS = {
                dead: ['Nicht erreichbar', 'dead'], suspect: ['Verdächtig', 'suspect'], cert: ['Zertifikat', 'cert'], ok: ['OK', 'ok'],
                skipped: ['Übersprungen', ''], excluded: ['Ausgeschlossen', ''], queued: ['In Arbeit …', ''], none: ['Nie geprüft', ''],
            };
            const stOf = (d) => d.check_status || 'none';
            const FILTERS = {
                problems: ['Probleme', (d) => ['dead', 'suspect', 'cert'].includes(stOf(d))],
                all: ['Alle', () => true],
                dead: ['Nicht erreichbar', (d) => stOf(d) === 'dead'],
                suspect: ['Verdächtig', (d) => stOf(d) === 'suspect'],
                cert: ['Zertifikat', (d) => stOf(d) === 'cert'],
                ok: ['OK', (d) => stOf(d) === 'ok'],
                skipped: ['Übersprungen', (d) => stOf(d) === 'skipped'],
                excluded: ['Ausgeschlossen', (d) => stOf(d) === 'excluded'],
                none: ['Nie geprüft', (d) => stOf(d) === 'none'],
            };
            const counts = {};
            for (const d of S.dials) counts[stOf(d)] = (counts[stOf(d)] || 0) + 1;
            const pending = S.dials.filter((d) => d.check_status === 'queued').length;
            const rows = S.dials.filter(FILTERS[ui.checkFilter][1]).sort((a, b) => a.title.localeCompare(b.title, 'de', { sensitivity: 'base' }));
            const groupName = (id) => S.groups.find((g) => g.id === id)?.title || '';
            const when = (t) => t ? new Date(t * 1000).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : '—';
            const hostOf = (u) => { try { return new URL(u).hostname.toLowerCase(); } catch { return ''; } };
            const picked = () => rows.filter((d) => ui.checkSel.has(d.id));

            const addRules = async (hosts) => {
                const have = new Set(S.settings.check_exclude.split('\n').map((l) => l.trim()));
                const add = [...new Set(hosts.filter(Boolean))].filter((x) => !have.has(x));
                if (!add.length) { toast('Schon in der Ausschlussliste.'); return; }
                try {
                    const r = await api('PUT', '/settings', { check_exclude: [S.settings.check_exclude.trim(), ...add].filter(Boolean).join('\n') });
                    S.settings.check_exclude = r.check_exclude;
                    await refreshState();
                    toast(add.length + ' Eintrag/Einträge zur Ausschlussliste hinzugefügt');
                } catch (err) { toast(err.message); }
            };
            const recheck = async (ids) => { await api('POST', '/checks/run', { ids }); await refreshState(); toast('Prüfung läuft …'); };

            const ruleMsg = h('p', { class: 'msg err', hidden: true });
            const area = h('textarea', { value: S.settings.check_exclude, spellcheck: false, autocapitalize: 'none',
                placeholder: 'proxy.example.de\n*.intern.example.de\n10.20.0.0/16', onchange: async (e) => {
                    ruleMsg.hidden = true;
                    try {
                        const r = await api('PUT', '/settings', { check_exclude: e.target.value });
                        S.settings.check_exclude = r.check_exclude; e.target.value = r.check_exclude; await refreshState();
                    } catch (err) { ruleMsg.textContent = err.message; ruleMsg.hidden = false; }
                } });
            area.value = S.settings.check_exclude;

            return [
                h('h3', { text: 'Einstellungen' }),
                select('Automatisch prüfen', 'check_interval_days', [[0, 'Aus'], [1, 'Täglich'], [3, 'Alle 3 Tage'], [7, 'Wöchentlich'], [14, 'Alle 14 Tage'], [30, 'Monatlich']], (v) => setSetting('check_interval_days', Number(v), { render: false })),
                toggle('Lokale Adressen automatisch ausschließen', 'check_skip_private', { render: false }),
                h('p', { class: 'msg', text: 'Lokal: private IP-Bereiche, localhost, Namen ohne Punkt sowie .local, .lan, .internal, .home.arpa, fritz.box. Automatische Prüfungen melden einen Fehler erst nach 3 Fehlversuchen an verschiedenen Tagen; bei „Jetzt prüfen“ sofort.' }),
                h('label', { class: 'field' }, h('span', { text: 'Ausschlussliste (eine Regel pro Zeile)' }), area),
                h('p', { class: 'msg', text: 'Erlaubt: Hostname (proxy.example.de), Platzhalter (*.intern.example.de) und IP-Bereiche (10.20.0.0/16). # beginnt einen Kommentar. Ausgeschlossene Hosts werden weder geprüft noch an Icon-Dienste gemeldet.' }), ruleMsg,
                h('h3', { text: 'Ergebnisse' }),
                h('div', { class: 'actions' },
                    h('button', { class: 'btn primary', text: 'Alle jetzt prüfen', disabled: pending > 0, onclick: async () => {
                        await api('POST', '/checks/run', {}); await refreshState(); toast('Prüfung läuft …');
                    } })),
                pending ? h('p', { class: 'msg', text: pending + ' Lesezeichen in Arbeit …' }) : null,
                h('div', { class: 'sums' }, Object.entries(FILTERS).filter(([k]) => !['all', 'problems'].includes(k)).map(([k, [label]]) =>
                    h('button', { type: 'button', class: 'badge ' + (STATUS[k]?.[1] || '') + (ui.checkFilter === k ? ' on' : ''), text: `${label}: ${counts[k] || 0}`,
                        onclick: () => { ui.checkFilter = k; ui.checkSel.clear(); show(); } }))),
                h('div', { class: 'set' }, h('span', { text: 'Anzeigen' }), h('select', { 'aria-label': 'Anzeigen', onchange: (e) => { ui.checkFilter = e.target.value; ui.checkSel.clear(); show(); } },
                    Object.entries(FILTERS).map(([k, [label]]) => h('option', { value: k, text: label, selected: ui.checkFilter === k })))),
                h('div', { class: 'bulk' },
                    h('label', { class: 'chk-box' }, h('input', { type: 'checkbox', checked: rows.length > 0 && rows.every((d) => ui.checkSel.has(d.id)),
                        onchange: (e) => { rows.forEach((d) => e.target.checked ? ui.checkSel.add(d.id) : ui.checkSel.delete(d.id)); show(); } }), `Alle ${rows.length} wählen`),
                    h('button', { class: 'btn', text: 'Erneut prüfen', disabled: !picked().length, onclick: () => recheck(picked().map((d) => d.id)) }),
                    h('button', { class: 'btn', text: 'Hosts ausschließen', disabled: !picked().length, onclick: () => addRules(picked().map((d) => hostOf(d.url))) }),
                    h('button', { class: 'btn danger', text: 'Löschen', disabled: !picked().length, onclick: async () => {
                        const ids = picked().map((d) => d.id);
                        if (!confirm(`${ids.length} Lesezeichen löschen?`)) return;
                        await api('POST', '/dials/bulk-delete', { ids }); ui.checkSel.clear(); await refreshState(); show();
                    } })),
                h('div', { class: 'clist' }, rows.length ? rows.map((d) => {
                    const [label, cls] = STATUS[stOf(d)];
                    return h('div', { class: 'crow' },
                        h('input', { type: 'checkbox', checked: ui.checkSel.has(d.id), 'aria-label': 'Auswählen',
                            onchange: (e) => { e.target.checked ? ui.checkSel.add(d.id) : ui.checkSel.delete(d.id); show(); } }),
                        h('div', { class: 'ct' }, d.title, ' ', h('span', { class: 'badge ' + cls, text: label }), ' ', h('span', { class: 'badge grp', text: 'Tab: ' + placeOf(d) })),
                        h('span'),
                        h('div', { class: 'cu' }, h('a', { href: d.url, target: '_blank', rel: 'noopener noreferrer', text: d.url })),
                        h('div', { class: 'cr', text: [d.check_reason, d.check_at ? 'geprüft ' + when(d.check_at) : null].filter(Boolean).join(' · ') }),
                        h('div', { class: 'ca' },
                            h('button', { class: 'btn', text: 'Prüfen', onclick: () => recheck([d.id]) }),
                            h('button', { class: 'btn', text: 'Host ausschließen', onclick: () => addRules([hostOf(d.url)]) }),
                            h('button', { class: 'btn', text: 'Bearbeiten', onclick: () => editDial(d) })));
                }) : h('p', { class: 'msg', text: 'Keine Einträge.' })),
            ];
        };
        ui.onState = () => { if (['check', 'cleanup', 'bookmarks'].includes(section) && dlg?.open && document.activeElement?.tagName !== 'TEXTAREA') show(); };

        const securityView = () => {
            const b64 = {
                dec: (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(s.length / 4) * 4, '=')), (c) => c.charCodeAt(0)),
                enc: (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),
            };
            const pw = h('input', { type: 'password', autocomplete: 'current-password', placeholder: 'Aktuelles Passwort' });
            const msg = h('p', { class: 'msg', hidden: true });
            const box = h('div');
            let st = null;
            let setup = null;   // TOTP setup in progress: { secret, uri }
            let codes = null;   // recovery codes to show once
            const say = (m, bad = false) => { msg.textContent = m || ''; msg.className = 'msg' + (bad ? ' err' : ''); msg.hidden = !m; };
            const call = (path, body = {}) => api('POST', path, { password: pw.value, ...body });
            const guard = (fn) => async (e) => {
                const btn = e?.currentTarget;
                if (btn) btn.disabled = true;
                say('');
                try { await fn(); } catch (err) { say(err.name === 'NotAllowedError' ? 'Abgebrochen oder vom Gerät abgelehnt.' : err.message, true); }
                if (btn) btn.disabled = false;
                draw();
            };
            const take = (r) => { st = r; if (r.recovery_codes) codes = r.recovery_codes; };

            const loadQr = () => new Promise((resolve, reject) => {
                if (window.qrcode) return resolve();
                const s = h('script', { src: S.base + '/assets/vendor/qrcode.js?v=1' });
                s.onload = resolve;
                s.onerror = () => reject(new Error('QR-Code konnte nicht geladen werden.'));
                document.head.append(s);
            });
            const qrCanvas = (text) => {
                const canvas = h('canvas', { class: 'qr', 'aria-label': 'QR-Code zum Einrichten der Authenticator-App' });
                loadQr().then(() => {
                    const qr = window.qrcode(0, 'M');
                    qr.addData(text);
                    qr.make();
                    const n = qr.getModuleCount();
                    const scale = 5;
                    const pad = 4;
                    canvas.width = canvas.height = (n + pad * 2) * scale;
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#fff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    ctx.fillStyle = '#000';
                    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (qr.isDark(r, c)) ctx.fillRect((c + pad) * scale, (r + pad) * scale, scale, scale);
                }).catch((err) => say(err.message, true));
                return canvas;
            };

            // Prints only a temporary sheet: the stylesheet hides everything else while body.printing-codes is set.
            const printCodes = (list) => {
                const sheet = h('div', { id: 'print-codes' },
                    h('h1', { text: `${S.settings.app_name || 'Startseite'} – Wiederherstellungscodes` }),
                    h('p', { text: `Benutzer: ${S.username} · erstellt am ${new Date().toLocaleDateString('de-DE')}. Jeder Code gilt einmal. Sicher aufbewahren.` }),
                    h('ol', {}, list.map((c) => h('li', { text: c }))));
                document.body.append(sheet);
                document.body.classList.add('printing-codes');
                const done = () => { document.body.classList.remove('printing-codes'); sheet.remove(); window.removeEventListener('afterprint', done); };
                window.addEventListener('afterprint', done);
                window.print();
            };

            const draw = () => {
                if (!st) return;
                const totp = [h('h3', { text: 'Authenticator-App (TOTP)' })];
                if (setup) {
                    const code = h('input', { type: 'text', inputMode: 'numeric', autocomplete: 'one-time-code', maxLength: 7, placeholder: '6-stelliger Code', class: 'short' });
                    totp.push(
                        h('p', { class: 'msg', text: 'QR-Code mit einer Authenticator-App scannen (zum Beispiel Aegis, 2FAS, Google Authenticator oder 1Password) oder den Schlüssel von Hand eintragen. Danach den angezeigten Code eingeben.' }),
                        qrCanvas(setup.uri),
                        h('p', { class: 'msg' }, 'Schlüssel: ', h('code', { text: setup.secret.match(/.{1,4}/g).join(' ') })),
                        h('div', { class: 'set' }, h('span', { text: 'Code aus der App' }), code),
                        h('div', { class: 'actions' },
                            h('button', { class: 'btn primary', text: 'Bestätigen und aktivieren', onclick: guard(async () => { take(await api('POST', '/mfa/totp/confirm', { code: code.value })); setup = null; say('Authenticator-App aktiviert. Andere Sitzungen wurden beendet.'); }) }),
                            h('button', { class: 'btn', text: 'Abbrechen', onclick: () => { setup = null; draw(); } })));
                } else if (st.totp) {
                    totp.push(h('p', { class: 'msg', text: 'Aktiv.' }),
                        h('div', { class: 'actions' }, h('button', { class: 'btn danger', text: 'Deaktivieren', onclick: guard(async () => { take(await call('/mfa/totp/disable')); say('Authenticator-App deaktiviert.'); }) })));
                } else {
                    totp.push(h('p', { class: 'msg', text: 'Nicht eingerichtet.' }),
                        h('div', { class: 'actions' }, h('button', { class: 'btn', text: 'Einrichten', onclick: guard(async () => { setup = await call('/mfa/totp/begin'); }) })));
                }

                const name = h('input', { type: 'text', maxLength: 60, placeholder: 'Name, z. B. iPhone', class: 'short' });
                const keys = [h('h3', { text: 'Passkeys' })];
                if (!window.PublicKeyCredential) {
                    keys.push(h('p', { class: 'msg', text: 'Dieser Browser unterstützt keine Passkeys.' }));
                } else {
                    keys.push(h('p', { class: 'msg', text: 'Mit einem Passkey meldest du dich ohne Benutzername und Passwort an (Button „Mit Passkey anmelden“ auf der Anmeldeseite). Fingerabdruck, Gesicht oder PIN gehören dazu. Passkeys funktionieren nur über HTTPS oder localhost und gelten für diese Domain: ' + st.rp_id }));
                    keys.push(...st.passkeys.map((k) => h('div', { class: 'set' },
                        h('span', { text: `${k.name} · ${new Date(k.created_at * 1000).toLocaleDateString('de-DE')}${k.last_used ? ' · zuletzt ' + new Date(k.last_used * 1000).toLocaleDateString('de-DE') : ''}` }),
                        h('button', { class: 'btn danger', text: 'Entfernen', onclick: guard(async () => { if (!confirm(`Passkey „${k.name}“ entfernen?`)) return; take(await call(`/mfa/passkey/${k.id}/delete`)); }) }))));
                    keys.push(h('div', { class: 'set' }, name, h('button', { class: 'btn', text: 'Passkey hinzufügen', onclick: guard(async () => {
                        const o = await call('/mfa/passkey/options');
                        const cred = await navigator.credentials.create({ publicKey: {
                            rp: o.rp, user: { id: b64.dec(o.user.id), name: o.user.name, displayName: o.user.displayName }, challenge: b64.dec(o.challenge),
                            pubKeyCredParams: o.pubKeyCredParams, timeout: o.timeout, attestation: o.attestation, authenticatorSelection: o.authenticatorSelection,
                            excludeCredentials: o.excludeCredentials.map((c) => ({ type: c.type, id: b64.dec(c.id) })),
                        } });
                        const r = cred.response;
                        take(await api('POST', '/mfa/passkey/register', { name: name.value, credential: { id: cred.id, type: cred.type,
                            response: { clientDataJSON: b64.enc(r.clientDataJSON), attestationObject: b64.enc(r.attestationObject) } } }));
                        say('Passkey hinzugefügt. Andere Sitzungen wurden beendet.');
                    }) })));
                }

                const rec = [h('h3', { text: 'Wiederherstellungscodes' })];
                if (codes) {
                    rec.push(h('p', { class: 'msg err', text: 'Jetzt sicher aufbewahren, etwa im Passwortmanager. Jeder Code gilt einmal und wird nicht noch einmal angezeigt.' }),
                        h('div', { class: 'codes' }, codes.map((c) => h('span', { text: c }))),
                        h('div', { class: 'actions' },
                            h('button', { class: 'btn', text: 'Kopieren', onclick: () => copyText(codes.join('\n'), 'Codes kopiert') }),
                            h('button', { class: 'btn', text: 'Drucken', onclick: () => printCodes(codes) }),
                            h('button', { class: 'btn primary', text: 'Gespeichert', onclick: () => { codes = null; draw(); } })));
                } else if (st.totp) {
                    rec.push(h('p', { class: 'msg', text: `${st.recovery_left} von 10 Codes übrig. Sie ersetzen den Code der Authenticator-App, wenn das Gerät verloren ist.` }),
                        h('div', { class: 'actions' }, h('button', { class: 'btn', text: 'Neue Codes erzeugen', onclick: guard(async () => { if (!confirm('Die bisherigen Codes werden ungültig. Fortfahren?')) return; take(await call('/mfa/recovery')); }) })));
                } else {
                    rec.push(h('p', { class: 'msg', text: 'Entstehen automatisch, sobald die Authenticator-App aktiv ist.' }));
                }
                box.replaceChildren(...totp, ...keys, ...rec);
            };
            api('GET', '/mfa').then((r) => { st = r; draw(); }).catch((err) => say(err.message, true));
            return [
                h('h3', { text: 'Sicherheit der Anmeldung (optional)' }),
                h('p', { class: 'msg', text: 'Ohne Zusatz genügt das Passwort. Wer die Startseite nur im Heimnetz betreibt, braucht nichts davon. Authenticator-App: fragt nach dem Passwort zusätzlich einen Code. Passkey: eigener Anmeldeweg ohne Passwort, der Passwort und zweiten Faktor ersetzt (ist die Authenticator-App aktiv, gilt sie nur für die Anmeldung mit Passwort). Bleibt eine Sitzung offen, gilt sie wie bisher bis zum Abmelden.' }),
                h('label', { class: 'field' }, h('span', { text: 'Passwort (für Änderungen nötig)' }), pw),
                msg, box,
            ];
        };

        const cleanupView = () => {
            const now = Date.now() / 1000;
            const days = S.settings.cleanup_days;
            const ago = (t) => { const d = (now - t) / 86400; return d < 60 ? `vor ${Math.max(1, Math.round(d))} Tagen` : `vor ${Math.round(d / 30)} Monaten`; };
            const active = S.dials.filter((d) => !d.keep);
            const hidden = S.dials.filter((d) => d.keep);
            const firstOf = new Map();
            const dupOf = new Map();
            for (const d of [...S.dials].sort((a, b) => a.id - b.id)) {
                const k = normUrl(d.url);
                if (firstOf.has(k)) dupOf.set(d.id, firstOf.get(k)); else firstOf.set(k, d.title);
            }
            const groupName = (id) => S.groups.find((g) => g.id === id)?.title || '';
            const lists = [
                { title: 'Dauerhaft nicht erreichbar', items: active.filter((d) => d.check_status === 'dead'), info: (d) => d.check_reason || '' },
                { title: `Seit mehr als ${Math.round(days / 30)} Monaten nicht besucht`,
                    items: active.filter((d) => d.check_status !== 'dead' && (d.last_visit || d.created_at) < now - days * 86400),
                    info: (d) => (d.last_visit ? 'zuletzt besucht ' + ago(d.last_visit) : 'nie besucht, angelegt ' + ago(d.created_at)) },
                { title: 'Duplikate', items: active.filter((d) => dupOf.has(d.id)), info: (d) => `Doppelt zu „${dupOf.get(d.id)}“` },
                { title: 'Ohne Vorschaubild', items: active.filter((d) => !d.thumb && !['pending', 'running'].includes(d.thumb_status)), regen: true,
                    info: (d) => d.thumb_error || 'Buchstaben-Kachel' },
            ];
            const sel = ui.cleanSel;
            const act = async (fn) => { try { await fn(); sel.clear(); await refreshState(); show(); } catch (err) { toast(err.message); } };

            const block = (l) => {
                const ids = () => l.items.filter((d) => sel.has(d.id)).map((d) => d.id);
                if (!l.items.length) return [h('h3', { text: `${l.title} (0)` }), h('p', { class: 'msg', text: 'Nichts gefunden.' })];
                return [
                    h('h3', { text: `${l.title} (${l.items.length})` }),
                    h('div', { class: 'bulk' },
                        h('label', { class: 'chk-box' }, h('input', { type: 'checkbox', checked: l.items.every((d) => sel.has(d.id)),
                            onchange: (e) => { l.items.forEach((d) => e.target.checked ? sel.add(d.id) : sel.delete(d.id)); show(); } }), 'Alle'),
                        l.regen ? h('button', { class: 'btn', text: 'Vorschau neu erzeugen', disabled: !ids().length, onclick: () => act(async () => {
                            for (const id of ids()) await api('POST', `/dials/${id}/refresh`);
                            toast('Vorschaubilder werden erzeugt …');
                        }) }) : null,
                        h('button', { class: 'btn', text: 'Behalten', title: 'Nicht mehr vorschlagen', disabled: !ids().length,
                            onclick: () => act(() => api('POST', '/dials/keep', { ids: ids(), keep: true })) }),
                        h('button', { class: 'btn danger', text: 'Löschen', disabled: !ids().length, onclick: () => {
                            const n = ids().length;
                            if (!confirm(`${n} Lesezeichen löschen?`)) return;
                            act(() => api('POST', '/dials/bulk-delete', { ids: ids() }));
                        } })),
                    h('div', { class: 'clist' }, l.items.map((d) => h('div', { class: 'crow' },
                        h('input', { type: 'checkbox', checked: sel.has(d.id), 'aria-label': 'Auswählen',
                            onchange: (e) => { e.target.checked ? sel.add(d.id) : sel.delete(d.id); show(); } }),
                        h('div', { class: 'ct' }, d.title, ' ', h('span', { class: 'badge grp', text: 'Tab: ' + placeOf(d) })), h('span'),
                        h('div', { class: 'cu' }, h('a', { href: d.url, target: '_blank', rel: 'noopener noreferrer', text: d.url })),
                        h('div', { class: 'cr', text: l.info(d) })))),
                ];
            };
            return [
                h('h3', { text: 'Einstellung' }),
                select('Als veraltet gilt nach', 'cleanup_days', [[90, '3 Monaten'], [180, '6 Monaten'], [365, '12 Monaten'], [730, '24 Monaten']],
                    (v) => { setSetting('cleanup_days', Number(v), { render: false }); show(); }),
                h('p', { class: 'msg', text: 'Vorschläge zum Aufräumen. Es wird nie automatisch gelöscht; „Behalten“ blendet einen Eintrag aus allen Listen aus.' }),
                ...lists.flatMap(block),
                hidden.length ? h('h3', { text: `Ausgeblendet (${hidden.length})` }) : null,
                hidden.length ? h('div', { class: 'actions' }, h('button', { class: 'btn', text: 'Wieder vorschlagen', onclick: () => act(() => api('POST', '/dials/keep', { ids: hidden.map((d) => d.id), keep: false })) })) : null,
            ];
        };

        function show() {
            nav.replaceChildren(...SECTIONS.map(([k, t]) => h('button', { type: 'button', class: section === k ? 'on' : '', text: t, onclick: () => { section = k; optsEl.classList.remove('nav-open'); show(); } })));
            navLabel.textContent = SECTIONS.find(([k]) => k === section)[1];
            body.replaceChildren(...views[section]().filter(Boolean));
        }
        dlg = openDialog(h('div', { class: 'dlg' }, dlgHead('Einstellungen', () => dlg), optsEl), { wide: true, onClose: () => { ui.onState = null; } });
        optsEl.append(navToggle, nav, body);
        show();
    }

    // ---------- state refresh ----------

    let pollTimer;
    const signature = (dials) => dials.map((d) => [d.id, d.group_id, d.section_id, d.position, d.title, d.url, d.thumb, d.thumb_status, d.fit, d.check_status, d.check_at, d.no_check, d.color, d.badge, d.badge_color, d.tags.join(','), d.visits, d.created_at].join('|')).join('\n');

    async function refreshState() {
        const data = await api('GET', '/state');
        const changed = signature(data.dials) !== signature(S.dials) || data.groups.length !== S.groups.length || JSON.stringify(data.sections) !== JSON.stringify(S.sections);
        Object.assign(S, { groups: data.groups, sections: data.sections, dials: data.dials, jobs: data.jobs, screenshots: data.screenshots });
        if (!validGroup(ui.group)) ui.group = groupsSorted()[0].id;
        if (changed && !drag?.active) { renderTabs(); renderGrid(); }
        ui.onState?.();
        schedulePoll();
    }

    function schedulePoll() {
        clearTimeout(pollTimer);
        if (S.jobs.pending + S.jobs.running + (S.jobs.checks || 0) > 0) pollTimer = setTimeout(() => refreshState().catch(() => {}), 3500);
    }

    // ---------- init ----------

    buildShell();
    const stored = store.get('group');
    const storedId = Number(stored) || stored;
    ui.group = S.settings.keep_active_group && validGroup(storedId) ? storedId : groupsSorted()[0]?.id;
    applyTheme();
    renderTabs();
    renderGrid();
    schedulePoll();
    if ('serviceWorker' in navigator) navigator.serviceWorker.register(S.base + '/sw.js', { scope: S.base + '/' }).catch(() => {});
})();

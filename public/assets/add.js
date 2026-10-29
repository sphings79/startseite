(() => {
    'use strict';

    const boot = JSON.parse(document.getElementById('boot').textContent);
    const $ = (id) => document.getElementById(id);
    const store = {
        get: (k) => { try { return localStorage.getItem('startseite.' + k); } catch { return null; } },
        set: (k, v) => { try { localStorage.setItem('startseite.' + k, v); } catch { /* storage unavailable */ } },
    };
    const url = $('add-url');
    const title = $('add-title');
    const group = $('add-group');
    const form = $('add-form');
    let titleEdited = false;

    url.value = boot.url;
    title.value = boot.title;
    title.addEventListener('input', () => { titleEdited = true; });

    const remembered = Number(store.get('addgroup')) || Number(store.get('group'));
    for (const g of boot.groups) {
        const o = document.createElement('option');
        o.value = String(g.id);
        o.textContent = g.title;
        o.selected = g.id === remembered;
        group.append(o);
    }

    if (boot.existing) {
        $('add-existing').textContent = `Ist bereits vorhanden (Gruppe „${boot.existing}“). Du kannst es trotzdem hinzufügen.`;
        $('add-existing').hidden = false;
    }

    async function fetchTitle() {
        if (!url.value.trim() || (title.value.trim() && title.value.trim() !== url.value.trim())) return;
        try {
            const res = await fetch(`${boot.base}/api/page-title?url=${encodeURIComponent(url.value.trim())}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (data.title && !titleEdited) title.value = data.title;
        } catch { /* optional */ }
    }
    url.addEventListener('change', fetchTitle);
    if (boot.url && !boot.title) fetchTitle();
    (boot.url ? title : url).focus();

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = $('add-submit');
        btn.disabled = true;
        $('add-error').hidden = true;
        try {
            const res = await fetch(`${boot.base}/api/dials`, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': boot.csrf, Accept: 'application/json' },
                body: JSON.stringify({ group_id: Number(group.value), url: url.value, title: title.value }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) { location.reload(); return; }
            if (!res.ok) throw new Error(data.error || 'Fehler ' + res.status);
            store.set('addgroup', group.value);
            form.hidden = true;
            $('add-existing').hidden = true;
            const done = $('add-done');
            done.replaceChildren(`„${data.title}“ wurde zu „${group.selectedOptions[0].textContent}“ hinzugefügt. `);
            const link = document.createElement('a');
            link.href = boot.base + '/';
            link.textContent = 'Zur Startseite';
            done.append(link);
            done.hidden = false;
            if (boot.popup) setTimeout(() => window.close(), 1200);
        } catch (err) {
            $('add-error').textContent = err.message;
            $('add-error').hidden = false;
            btn.disabled = false;
        }
    });
})();

(() => {
    'use strict';

    const btn = document.getElementById('passkey-btn');
    if (!btn) return;
    const err = document.getElementById('passkey-error');
    const show = (msg) => { err.textContent = msg; err.hidden = false; };
    if (!window.PublicKeyCredential) return;
    btn.hidden = false;

    const dec = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(s.length / 4) * 4, '=')), (c) => c.charCodeAt(0));
    const enc = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const post = async (path, body) => {
        const res = await fetch(btn.dataset.base + path, {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || 'Fehler ' + res.status);
        return data;
    };

    btn.addEventListener('click', async () => {
        err.hidden = true;
        btn.disabled = true;
        try {
            const o = await post('/login/passkey/options', {});
            const cred = await navigator.credentials.get({ publicKey: {
                challenge: dec(o.challenge), rpId: o.rpId, timeout: o.timeout, userVerification: o.userVerification,
            } });
            const r = cred.response;
            const done = await post('/login/passkey/verify', { next: btn.dataset.next, credential: {
                id: cred.id, type: cred.type,
                response: {
                    clientDataJSON: enc(r.clientDataJSON), authenticatorData: enc(r.authenticatorData), signature: enc(r.signature),
                    userHandle: r.userHandle ? enc(r.userHandle) : '',
                },
            } });
            location.href = done.redirect;
        } catch (e) {
            show(e.name === 'NotAllowedError' ? 'Abgebrochen oder kein passender Passkey gefunden.' : e.message);
            btn.disabled = false;
        }
    });
})();

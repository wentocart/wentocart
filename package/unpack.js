let done = 0;

function showStop(message) {
    const msg = document.getElementById('msg');
    const help = document.getElementById('help');
    if (msg) {
        msg.textContent = message;
    }
    if (help) {
        help.hidden = false;
        help.textContent = 'Unpacking stopped. Check that this folder is writable, then reload to try again.';
    }
    const btn = document.getElementById('continue');
    if (btn) {
        btn.setAttribute('aria-disabled', 'true');
        btn.classList.add('is-disabled');
    }
}

async function tick() {
    try {
        const res = await fetch('?extract=1&done=' + done, { cache: 'no-store' });
        const text = await res.text();
        let json;
        try {
            json = JSON.parse(text);
        } catch (e) {
            showStop('Unpacking stopped. The installer did not return a progress update.');
            return;
        }
        if (!json.ok) {
            showStop(json.message || 'Unpacking stopped.');
            return;
        }
        done = json.done;
        const bar = document.getElementById('bar');
        const pct = document.getElementById('pct');
        const msg = document.getElementById('msg');
        if (bar) {
            bar.style.width = json.percent + '%';
        }
        if (pct) {
            pct.textContent = json.percent;
        }
        if (msg) {
            msg.textContent = json.finished
                ? 'Unpacking finished. Opening the next step…'
                : 'Unpacked ' + json.done + ' / ' + json.total + ' files';
        }
        if (json.finished) {
            if (!json.redirect) {
                showStop('Unpacking finished but the setup wizard is missing.');
                return;
            }
            const btn = document.getElementById('continue');
            if (btn) {
                btn.classList.remove('is-disabled');
                btn.removeAttribute('aria-disabled');
                btn.removeAttribute('tabindex');
                btn.setAttribute('href', json.redirect);
            }
            window.location.replace(json.redirect);
            return;
        }
        tick();
    } catch (e) {
        showStop('Unpacking stopped. Reload this page to continue.');
    }
}

tick();

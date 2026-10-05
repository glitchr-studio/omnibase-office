/*
 * omnibase/office - the video room when its gateway is a provider's frame
 * or address (glitchr/omnimeet: Access FRAME or LINK). The call itself is
 * the provider's; this keeps what the practice judges the same whatever
 * the gateway:
 *
 *   - "I am here": the room's address is asked every few seconds, which is
 *     also what the waiting room and the agenda read;
 *   - the waiting room: the guest's frame is not loaded before the host is
 *     there;
 *   - the end: the host ends the room for both; a closed room empties the
 *     page;
 *   - consent: a third party is asked nothing before the visitor enters,
 *     and - when the site has omnibase/consent - before they allowed it
 *     (the "VISIO" feature of its panel).
 *
 * A gateway's own script, when it has one (omnimeet/jitsi), drives the
 * frame: it boots on [data-visio-pilot], is told "omnimeet:join", "leave"
 * and "end", and says where it stands with "omnimeet:state".
 */
(function () {
    'use strict';

    function start(root) {
        if (root.dataset.visioFrameBound) return;
        root.dataset.visioFrameBound = '1';

        var isHost = root.dataset.role === 'host';
        var texts = {};
        try { texts = JSON.parse(root.querySelector('[data-visio-texts]').textContent); } catch (e) {}
        var $ = function (name) { return root.querySelector('[data-visio-' + name + ']'); };
        var statusEl = $('status'), joinBtn = $('join'), hangBtn = $('hangup'), stage = $('stage');
        var pilot = $('pilot'), iframe = $('iframe'), link = $('link'), consentBtn = $('consent');

        var joined = false, entered = false, over = false, timer = null;
        var feature = root.dataset.consent || '', allowed = !feature;

        function status(key) {
            root.dataset.visioState = key;
            if (statusEl && texts[key]) statusEl.textContent = texts[key];
        }
        function show(el, on) { if (el) el.hidden = !on; }
        function tell(name) { if (pilot) pilot.dispatchEvent(new CustomEvent(name)); }

        function request(method, url, headers) {
            return fetch(url, { method: method, credentials: 'same-origin', headers: Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, headers || {}) });
        }

        // The provider's interface comes in: for the host at once, for the guest when the host is there.
        function enter() {
            if (entered || over || !allowed) return;
            entered = true;
            show(stage, !!(pilot || iframe));
            if (pilot) { status('connecting'); tell('omnimeet:join'); }
            else if (iframe) { iframe.src = iframe.dataset.src; status('connected'); }
            else if (link) { show(link, true); status('connected'); }
        }
        function leave(everyone) {
            if (pilot) tell(everyone ? 'omnimeet:end' : 'omnimeet:leave');
            if (iframe) iframe.removeAttribute('src');
            show(stage, false);
            show(link, false);
            entered = false;
        }

        function poll() {
            if (over) return Promise.resolve();
            return request('GET', root.dataset.signalUrl + '?after=0')
                .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
                .then(function (data) {
                    if (data.ended || !data.open) { finish(false); return; }
                    if (!joined) return;
                    if (isHost) {
                        enter();
                        // Once in: "connected" while the guest is there too, "waiting" until then and when they left.
                        if (root.dataset.visioState !== 'connecting') status(data.guest ? 'connected' : 'waiting_guest');
                    } else if (data.host) {
                        enter();
                    } else if (!entered) {
                        status('waiting_host');
                    }
                })
                .catch(function () {});
        }
        function schedule() {
            clearTimeout(timer);
            if (over || !joined) return;
            timer = setTimeout(function () { poll().then(schedule); }, entered ? 5000 : 2000);
        }

        function join() {
            if (joined || over) return;
            if (!allowed) { status('consent_needed'); show(consentBtn, true); return; }
            joined = true;
            show(joinBtn, false);
            show(hangBtn, true);
            show(consentBtn, false);
            status(isHost ? 'waiting_guest' : 'waiting_host');
            poll().then(schedule);
        }

        function finish(byMe) {
            if (over) return;
            over = true;
            clearTimeout(timer);
            if (byMe && isHost && root.dataset.endUrl) request('POST', root.dataset.endUrl, { 'X-CSRF-Token': root.dataset.csrf || '' });
            leave(byMe && isHost);
            [joinBtn, hangBtn, consentBtn].forEach(function (el) { show(el, false); });
            status('ended');
        }

        if (joinBtn) joinBtn.addEventListener('click', join);
        if (hangBtn) hangBtn.addEventListener('click', function () { finish(true); });
        if (pilot) pilot.addEventListener('omnimeet:state', function (event) {
            var state = event.detail && event.detail.state;
            if (state === 'joined') status('connected');
            else if (state === 'error') status('lost');
            // Hung up inside the provider's own interface: the same as hanging up here.
            else if (state === 'left' && entered && !over) finish(true);
        });
        window.addEventListener('pagehide', function () { if (entered) leave(false); });

        // A third party: omnibase/consent's panel decides, when the site has it; this page's own "enter" otherwise.
        if (feature && window.Consent && typeof window.Consent.use === 'function') {
            allowed = !!window.Consent.use(feature, { label: texts.consent_label, description: texts.consent_description },
                function () { allowed = true; show(consentBtn, false); if (root.dataset.visioState === 'consent_needed') status('provider_ready'); },
                function () { allowed = false; if (entered && !over) { leave(false); joined = false; clearTimeout(timer); show(joinBtn, true); show(hangBtn, false); } status('consent_needed'); show(consentBtn, true); });
            if (!allowed) { status('consent_needed'); show(consentBtn, true); }
        } else {
            allowed = true;
        }
        if (allowed) status('provider_ready');

        // A room closed while the page sat open says so, joined or not.
        (function watch() { if (over) return; if (!joined) request('GET', root.dataset.signalUrl + '?after=0').then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { if (d && (d.ended || !d.open)) finish(false); }).catch(function () {}); setTimeout(watch, 15000); })();
    }

    function boot() { document.querySelectorAll('[data-office-visio-frame]').forEach(start); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
    window.addEventListener('transparent:load', boot);
})();

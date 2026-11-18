/*
 * vmmngr.js - minimal helpers for the VM Manager GUI.
 * Mutations are POST-only against vmmngr_api.php; long tasks run as jobs.
 */
(function () {
    'use strict';

    function post(data) {
        return fetch('vmmngr_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: new URLSearchParams(data).toString()
        }).then(function (r) { return readJson(r); });
    }

    function readJson(r) {
        // a redirected request means the XigmaNAS session expired; the final
        // response is usually an empty login page, not JSON
        if (r.redirected || r.status === 0) {
            return {ok: false, error: 'session expired - please reload the page and log in again'};
        }
        return r.text().then(function (t) {
            if (!t) {
                return {ok: false, error: 'empty response (HTTP ' + r.status + ')'};
            }
            try {
                return JSON.parse(t);
            } catch (e) {
                return {ok: false, error: 'HTTP ' + r.status + ': ' + t.slice(0, 300)};
            }
        });
    }

    function poll(id, box) {
        var head = box.querySelector('.vmm-job-head');
        var stateEl = box.querySelector('.vmm-job-state');
        var logEl = box.querySelector('pre');
        var timer = setInterval(function () {
            fetch('vmmngr_api.php?action=job&id=' + encodeURIComponent(id), {credentials: 'same-origin'})
                .then(function (r) { return readJson(r); })
                .then(function (d) {
                    if (!d.ok) { return; }
                    var j = d.job;
                    stateEl.textContent = j.state + (j.rc !== null && j.rc !== undefined ? ' (rc ' + j.rc + ')' : '');
                    stateEl.className = 'vmm-job-state ' + (j.state === 'failed' ? 'vmm-err' : 'vmm-ok');
                    logEl.textContent = j.log || '';
                    logEl.scrollTop = logEl.scrollHeight;
                    if (j.state === 'done' || j.state === 'failed' || j.state === 'canceled') {
                        clearInterval(timer);
                    }
                });
        }, 1500);
    }

    window.vmmTrack = function (id) {
        var box = document.getElementById('vmm-job');
        if (!box) { return; }
        box.style.display = 'block';
        box.innerHTML = '<div class="vmm-job-head">Job <span class="vmm-mono">' + id + '</span><span class="vmm-job-state">queued</span></div><pre class="vmm-raw"></pre>';
        poll(id, box);
    };

    window.vmmRun = function (verb, vm, confirmText) {
        var payload = {action: 'action', verb: verb, vm: vm};
        if (confirmText !== undefined && confirmText !== null) {
            payload.confirm = confirmText;
        }
        post(payload).then(function (d) {
            if (!d.ok) {
                alert(d.error || 'action refused');
                return;
            }
            vmmTrack(d.job.id);
        }).catch(function (e) {
            alert('request failed: ' + e);
        });
    };

    window.vmmConfirmRun = function (verb, vm, question) {
        if (!window.confirm(question)) { return; }
        vmmRun(verb, vm);
    };

    window.vmmDestroy = function (vm) {
        if (!window.confirm('Destroy ' + vm + '?\n\nAll disks and snapshots of this VM are deleted. This cannot be undone.')) {
            return;
        }
        var typed = window.prompt('Type the VM name to confirm:', '');
        if (typed === null) { return; }
        if (typed !== vm) {
            alert('Name does not match - nothing was done.');
            return;
        }
        vmmRun('destroy', vm, typed);
    };
})();

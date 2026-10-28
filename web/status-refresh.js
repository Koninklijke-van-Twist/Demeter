/**
 * Ververs Nu: werkorderstatussen live verversen in korte stappen (action=status_refresh).
 * op=start start of lift mee op de lopende job (zelfde bedrijf + kostenplaats); daarna wordt op=step
 * gepold tot de job klaar is. Voortgang = chunks klaar / chunks totaal, uit het gedeelde statusbestand,
 * dus elke tab ziet dezelfde voortgang. Geen lange HTTP-request: elke step duurt max. ~20 s.
 */
(function (root)
{
    'use strict';

    function createProgressBar (doc)
    {
        if (!doc || !doc.body || typeof doc.createElement !== 'function')
        {
            return { update () {}, remove () {} };
        }
        const box = doc.createElement('div');
        box.id = 'statusRefreshProgress';
        box.setAttribute('role', 'progressbar');
        box.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:9999;background:#fff;border:1px solid #ccc;'
            + 'border-radius:6px;padding:8px 12px;box-shadow:0 2px 8px rgba(0,0,0,.15);font:13px sans-serif;width:280px';
        const label = doc.createElement('div');
        const track = doc.createElement('div');
        track.style.cssText = 'height:8px;background:#eee;border-radius:4px;margin-top:6px;overflow:hidden';
        const fill = doc.createElement('div');
        fill.style.cssText = 'height:100%;width:0;background:#2e7d32;transition:width .3s';
        track.appendChild(fill);
        box.appendChild(label);
        box.appendChild(track);
        doc.body.appendChild(box);

        return {
            update (text, percent)
            {
                label.textContent = text;
                fill.style.width = Math.max(0, Math.min(100, percent)) + '%';
                box.setAttribute('aria-valuenow', String(percent));
            },
            remove () { if (box.parentNode) { box.parentNode.removeChild(box); } }
        };
    }

    function progressText (p)
    {
        const done = Number(p.chunks_done || 0);
        const total = Number(p.chunks_total || 0);
        return 'Statussen verversen: ' + done + ' / ' + total + ' blokken (' + Number(p.percent || 0) + '%)';
    }

    /**
     * @param {{company: string, costCenter: string, fetchJson?: Function, document?: Document,
     *          sleep?: Function, onDone?: Function, pollMs?: number}} options
     * @returns {Promise<object>} laatste voortgang
     */
    async function run (options)
    {
        const fetchJson = options.fetchJson || async function (url)
        {
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
            return response.json();
        };
        const sleep = options.sleep || function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
        const pollMs = typeof options.pollMs === 'number' ? options.pollMs : 1500;
        const bar = createProgressBar(options.document || (typeof document !== 'undefined' ? document : null));
        const url = function (op)
        {
            const params = new URLSearchParams();
            params.set('action', 'status_refresh');
            params.set('op', op);
            params.set('company', options.company);
            params.set('cost_center', options.costCenter);
            return 'index.php?' + params.toString();
        };
        let progress = null;
        let errors = 0;
        try
        {
            progress = await fetchJson(url('start'));
            while (progress && progress.ok !== false && progress.status === 'running')
            {
                bar.update(progressText(progress) + (progress.joined ? ' (loopt al, meegelift)' : ''), Number(progress.percent || 0));
                let next = null;
                try
                {
                    next = await fetchJson(url('step'));
                }
                catch (stepError)
                {
                    next = null;
                }
                if (!next || next.ok === false)
                {
                    // Netwerkfout of 500: even wachten en opnieuw (server bewaart de voortgang per chunk).
                    if (++errors >= 5) { progress = next || { ok: false, status: 'error' }; break; }
                    await sleep(pollMs * 2);
                    continue;
                }
                errors = 0;
                next.joined = progress.joined;
                // Stap van een andere tab bezig: niets gedaan, even wachten voor de volgende poll.
                if (Number(next.chunks_done) === Number(progress.chunks_done) && next.status === 'running')
                {
                    await sleep(pollMs);
                }
                progress = next;
            }
            if (progress && progress.status === 'completed')
            {
                bar.update('Statussen ververst (' + Number(progress.updated || 0) + ' gewijzigd)', 100);
                if (typeof options.onDone === 'function') { await options.onDone(progress); }
            }
            else
            {
                bar.update('Statussen verversen mislukt' + (progress && progress.error ? ': ' + progress.error : ''), Number((progress && progress.percent) || 0));
            }
        }
        finally
        {
            sleep(4000).then(function () { bar.remove(); });
        }
        return progress;
    }

    const api = { run: run, progressText: progressText };
    root.DemeterStatusRefresh = api;
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
})(typeof window !== 'undefined' ? window : globalThis);

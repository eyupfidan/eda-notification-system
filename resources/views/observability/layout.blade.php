<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        :root {
            --fg: #0f172a; --muted: #64748b; --line: #e2e8f0; --bg: #f1f5f9; --card: #fff;
            --accent: #4f46e5; --accent-soft: #eef2ff;
            --ok: #22c55e; --ok-soft: #dcfce7; --warn: #f59e0b; --warn-soft: #fef3c7; --down: #ef4444; --down-soft: #fee2e2;
            --c-scheduled: #8b5cf6; --c-pending: #94a3b8; --c-queued: #f59e0b; --c-processing: #3b82f6;
            --c-sent: #22c55e; --c-failed: #ef4444; --c-cancelled: #64748b;
            --c-ready: #3b82f6; --c-delayed: #f59e0b; --c-reserved: #8b5cf6;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.5 Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; color: var(--fg); background: var(--bg); }
        main { max-width: 1100px; margin: 0 auto; padding: 24px 20px 48px; }
        code { font: 12px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        a { color: var(--accent); }

        nav.top { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
        nav.top .links { display: flex; gap: 4px; padding: 3px; background: var(--card); border: 1px solid var(--line); border-radius: 999px; }
        nav.top .links a { padding: 5px 14px; border-radius: 999px; text-decoration: none; color: var(--muted); font-weight: 600; font-size: 13px; }
        nav.top .links a.active { background: var(--accent-soft); color: var(--accent); }
        .lang { display: inline-flex; padding: 3px; border-radius: 999px; background: var(--card); border: 1px solid var(--line); }
        .lang button { border: 0; background: transparent; padding: 4px 12px; border-radius: 999px; font: 600 12px/1.6 inherit; color: var(--muted); cursor: pointer; }
        .lang button.active { background: var(--accent); color: #fff; }

        header.page { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        h1 { font-size: 24px; margin: 0 0 4px; letter-spacing: -.01em; }
        .lead { color: var(--muted); margin: 0; max-width: 640px; }
        .toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 13px; color: var(--muted); }
        .toolbar select, .toolbar .btn { padding: 6px 12px; border: 1px solid var(--line); border-radius: 10px; background: var(--card); font: 500 13px/1.5 inherit; color: var(--fg); cursor: pointer; text-decoration: none; }
        .toolbar label { display: inline-flex; gap: 6px; align-items: center; cursor: pointer; }

        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(15, 23, 42, .04); }
        .card h2 { font-size: 15px; margin: 0 0 14px; }
        .grid { display: grid; gap: 16px; }
        .grid.cols-2 { grid-template-columns: repeat(2, 1fr); }
        .grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
        @media (max-width: 820px) { .grid.cols-2, .grid.cols-3 { grid-template-columns: 1fr; } }

        .kpis { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 16px; }
        @media (max-width: 900px) { .kpis { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 520px) { .kpis { grid-template-columns: repeat(2, 1fr); } }
        .kpi { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 14px 16px; }
        .kpi span { display: block; color: var(--muted); font-size: 12px; }
        .kpi b { display: block; font-size: 24px; font-variant-numeric: tabular-nums; margin-top: 2px; }
        .kpi small { color: var(--muted); font-size: 12px; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; font-variant-numeric: tabular-nums; }
        th, td { text-align: right; padding: 8px 10px; border-bottom: 1px solid var(--line); white-space: nowrap; }
        th:first-child, td:first-child { text-align: left; }
        th { color: var(--muted); font-weight: 500; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        tr.total td { font-weight: 600; background: #f8fafc; }
        .table-wrap { overflow-x: auto; }

        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .badge::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
        .badge.ok { background: var(--ok-soft); color: #166534; }
        .badge.degraded { background: var(--warn-soft); color: #92400e; }
        .badge.down { background: var(--down-soft); color: #991b1b; }
        .chan { font-weight: 600; text-transform: uppercase; font-size: 12px; }
        .muted { color: var(--muted); }
        .bar { display: flex; height: 10px; border-radius: 999px; overflow: hidden; background: #e2e8f0; }
        .bar span { transition: width .35s ease; }
        .legend { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 10px; font-size: 12px; color: var(--muted); }
        .legend i { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; }
        .error-banner { display: none; background: var(--down-soft); color: #991b1b; border: 1px solid #fecaca; border-radius: 12px; padding: 10px 14px; margin-bottom: 16px; }
        footer { color: var(--muted); font-size: 12px; text-align: center; margin-top: 24px; }
    </style>
    @include('partials.transitions')
</head>
<body>
<main>
    <nav class="top">
        <div class="links">
            <a href="/health" class="{{ request()->is('health') ? 'active' : '' }}" data-i18n="navHealth">Health</a>
            <a href="/metrics" class="{{ request()->is('metrics') ? 'active' : '' }}" data-i18n="navMetrics">Metrics</a>
            <a href="/realtime" data-i18n="navRealtime">Live status</a>
        </div>
        <div class="lang" role="group" aria-label="Language">
            <button type="button" data-lang="tr">TR</button>
            <button type="button" data-lang="en">EN</button>
        </div>
    </nav>

    <div id="error-banner" class="error-banner"></div>

    @yield('content')

    <footer data-i18n-html="footer">JSON: add <code>?type=json</code> to the URL.</footer>
</main>
<script>
    // Shared helpers for the observability pages: translations, formatting and polling.
    const UI = (() => {
        const dict = {
            en: {
                navHealth: 'Health', navMetrics: 'Metrics', navRealtime: 'Live status',
                footer: 'JSON: add <code>?type=json</code> to the URL.',
                autoRefresh: 'Auto refresh', updated: 'Updated', viewJson: 'View JSON',
                fetchError: 'Could not refresh the data',
                ok: 'Healthy', degraded: 'Degraded', down: 'Down',
            },
            tr: {
                navHealth: 'Sağlık', navMetrics: 'Metrikler', navRealtime: 'Canlı durum',
                footer: 'JSON için adrese <code>?type=json</code> ekleyin.',
                autoRefresh: 'Otomatik yenile', updated: 'Güncellendi', viewJson: 'JSON görüntüle',
                fetchError: 'Veriler yenilenemedi',
                ok: 'Sağlıklı', degraded: 'Kısmi sorun', down: 'Çalışmıyor',
            },
        };
        let lang = localStorage.getItem('ui.lang') || localStorage.getItem('realtime.lang')
            || ((navigator.language || '').toLowerCase().startsWith('tr') ? 'tr' : 'en');
        let render = () => {};

        const t = key => dict[lang][key] ?? dict.en[key] ?? key;
        const locale = () => lang === 'tr' ? 'tr-TR' : 'en-GB';
        const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const num = v => v === null || v === undefined ? '—' : Number(v).toLocaleString(locale());
        const pct = v => v === null || v === undefined ? '—' : (v * 100).toLocaleString(locale(), { maximumFractionDigits: 1 }) + '%';
        const ms = v => v === null || v === undefined ? '—' : v >= 1000 ? (v / 1000).toLocaleString(locale(), { maximumFractionDigits: 2 }) + ' s' : v + ' ms';
        const time = iso => iso ? new Date(iso).toLocaleTimeString(locale()) : '—';
        const ago = iso => {
            if (!iso) return '—';
            const s = Math.max(0, Math.round((Date.now() - new Date(iso)) / 1000));
            const rtf = new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' });
            return s < 60 ? rtf.format(-s, 'second') : s < 3600 ? rtf.format(-Math.round(s / 60), 'minute') : rtf.format(-Math.round(s / 3600), 'hour');
        };

        function setLang(next) {
            lang = next;
            localStorage.setItem('ui.lang', next);
            document.documentElement.lang = next;
            document.querySelectorAll('[data-i18n]').forEach(el => el.textContent = t(el.dataset.i18n));
            document.querySelectorAll('[data-i18n-html]').forEach(el => el.innerHTML = t(el.dataset.i18nHtml));
            document.querySelectorAll('.lang button').forEach(b => b.classList.toggle('active', b.dataset.lang === next));
            render();
        }

        function showError(message) {
            const el = document.getElementById('error-banner');
            el.style.display = message ? 'block' : 'none';
            el.textContent = message ? `${t('fetchError')}: ${message}` : '';
        }

        /**
         * @param {object} options.translations  {en: {...}, tr: {...}} page strings
         * @param {object} options.initial       data rendered by the server
         * @param {function} options.draw        draws the page from a data object
         * @param {function} options.url         JSON url to poll
         * @returns {function} refresh()         fetches and redraws now
         */
        function start({ translations, initial, draw, url, interval = 5000 }) {
            Object.keys(dict).forEach(l => Object.assign(dict[l], translations[l]));
            let current = initial;
            render = () => draw(current);
            document.querySelectorAll('.lang button').forEach(b => b.addEventListener('click', () => Motion.swap(() => setLang(b.dataset.lang))));
            Motion.quietly(() => setLang(lang));

            const dot = document.getElementById('live-dot');
            const refresh = async () => {
                dot?.classList.add('busy');
                try {
                    // Health answers 503 with a JSON body when it is down; that is still data to show.
                    const res = await fetch(url(), { headers: { Accept: 'application/json' } });
                    const body = await res.json();
                    if (body.error) throw new Error(body.error.message);
                    showError('');
                    current = body;
                    render();
                } catch (e) {
                    showError(e.message);
                } finally {
                    setTimeout(() => dot?.classList.remove('busy'), 400);
                }
            };
            const auto = document.getElementById('auto-refresh');
            auto?.addEventListener('change', () => dot?.classList.toggle('paused', !auto.checked));
            setInterval(() => { if (!auto || auto.checked) refresh(); }, interval);

            return refresh;
        }

        return { t, esc, num, pct, ms, time, ago, start, lang: () => lang };
    })();
</script>
@yield('scripts')
</body>
</html>

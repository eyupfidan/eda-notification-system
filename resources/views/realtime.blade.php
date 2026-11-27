<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Live status · {{ config('app.name') }}</title>
    <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>
    <style>
        :root {
            --fg: #0f172a; --muted: #64748b; --line: #e2e8f0; --bg: #f1f5f9; --card: #fff;
            --accent: #4f46e5; --accent-soft: #eef2ff;
            --c-scheduled: #8b5cf6; --c-pending: #94a3b8; --c-queued: #f59e0b; --c-processing: #3b82f6;
            --c-sent: #22c55e; --c-failed: #ef4444; --c-cancelled: #64748b;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font: 14px/1.5 Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; color: var(--fg); background: var(--bg); }
        main { max-width: 1040px; margin: 0 auto; padding: 32px 20px 48px; }
        code { font: 12px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }

        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 24px; }
        h1 { font-size: 24px; margin: 0 0 4px; letter-spacing: -.01em; }
        .lead { color: var(--muted); margin: 0; max-width: 620px; }
        .header-right { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .links { display: flex; gap: 4px; padding: 3px; background: var(--card); border: 1px solid var(--line); border-radius: 999px; }
        .links a { padding: 4px 12px; border-radius: 999px; text-decoration: none; color: var(--muted); font-weight: 600; font-size: 12px; }
        .links a.active { background: var(--accent-soft); color: var(--accent); }

        .pill { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px; background: var(--card); border: 1px solid var(--line); font-size: 13px; font-weight: 500; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: #cbd5e1; }
        .pill.on .dot { background: var(--c-sent); box-shadow: 0 0 0 3px #dcfce7; }
        .pill.off .dot { background: var(--c-failed); box-shadow: 0 0 0 3px #fee2e2; }

        .lang { display: inline-flex; padding: 3px; border-radius: 999px; background: var(--card); border: 1px solid var(--line); }
        .lang button { border: 0; background: transparent; padding: 4px 12px; border-radius: 999px; font: 600 12px/1.6 inherit; color: var(--muted); cursor: pointer; }
        .lang button.active { background: var(--accent); color: #fff; }

        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(15, 23, 42, .04); }
        .card h2 { font-size: 15px; margin: 0 0 4px; }
        .card .hint { color: var(--muted); font-size: 13px; margin: 0 0 14px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 760px) { .grid-2 { grid-template-columns: 1fr; } }

        .row { display: flex; gap: 8px; flex-wrap: wrap; }
        input { flex: 1; min-width: 200px; padding: 9px 12px; border: 1px solid var(--line); border-radius: 10px; font: inherit; outline: none; }
        input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
        button.btn { padding: 9px 16px; border: 0; border-radius: 10px; background: var(--accent); color: #fff; font: 600 14px/1.5 inherit; cursor: pointer; }
        button.btn:hover { filter: brightness(1.08); }
        button.btn:disabled { opacity: .6; cursor: default; }
        button.btn.secondary { background: var(--card); color: var(--fg); border: 1px solid var(--line); }
        .subs { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px; }
        .sub { background: var(--accent-soft); color: var(--accent); padding: 3px 10px; border-radius: 999px; font-size: 12px; }
        .msg { font-size: 13px; margin-top: 10px; min-height: 20px; }
        .msg.error { color: var(--c-failed); }

        .summary-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; }
        .summary-head .total { color: var(--muted); font-size: 13px; }
        .bar { display: flex; height: 12px; border-radius: 999px; overflow: hidden; background: #e2e8f0; }
        .bar span { transition: width .35s ease; }
        .tiles { display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px; margin-top: 16px; }
        @media (max-width: 760px) { .tiles { grid-template-columns: repeat(4, 1fr); } }
        .tile { border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; position: relative; overflow: hidden; }
        .tile::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--c); }
        .tile b { display: block; font-size: 22px; font-variant-numeric: tabular-nums; }
        .tile span { color: var(--muted); font-size: 12px; }

        .feed-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 8px; flex-wrap: wrap; }
        .feed-head h2 { margin: 0; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-weight: 500; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        tbody tr { animation: flash 1.2s ease; }
        @keyframes flash { from { background: var(--accent-soft); } to { background: transparent; } }
        .table-wrap { max-height: 480px; overflow: auto; }
        .empty { text-align: center; color: var(--muted); padding: 32px 10px; }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 500; background: #f1f5f9; color: #334155; white-space: nowrap; }
        .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: var(--c, #94a3b8); }
        .chan { font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--muted); }
        .detail { color: var(--muted); max-width: 320px; word-break: break-word; }
    </style>
    @include('partials.transitions')
</head>
<body>
<main>
    <header>
        <div>
            <h1 data-i18n="title">Live notification status</h1>
            <p class="lead" data-i18n="lead">Status changes are pushed over WebSocket (Laravel Reverb) the moment they happen. Watch a single notification, a whole batch, or send a demo batch to see it in action.</p>
        </div>
        <div class="header-right">
            <nav class="links">
                <a href="/health" data-i18n="navHealth">Health</a>
                <a href="/metrics" data-i18n="navMetrics">Metrics</a>
                <a href="/realtime" class="active" data-i18n="navRealtime">Live status</a>
            </nav>
            <span id="conn" class="pill"><span class="dot"></span><span id="conn-text">connecting</span></span>
            <div class="lang" role="group" aria-label="Language">
                <button type="button" data-lang="tr">TR</button>
                <button type="button" data-lang="en">EN</button>
            </div>
        </div>
    </header>

    <div class="grid-2">
        <section class="card">
            <h2 data-i18n="demoTitle">Try it</h2>
            <p class="hint" data-i18n="demoHint">Creates 30 notifications (SMS, email, push) scheduled 5 seconds ahead and follows the batch live.</p>
            <button id="demo" class="btn" data-i18n="demoButton">Send demo batch</button>
            <div id="demo-msg" class="msg"></div>
        </section>

        <section class="card">
            <h2 data-i18n="watchTitle">Watch a channel</h2>
            <p class="hint" data-i18n-html="watchHint">Enter <code>batches.{id}</code> for a batch or <code>notifications.{id}</code> for a single notification.</p>
            <form id="sub-form" class="row">
                <input id="channel" placeholder="batches.01a2…" autocomplete="off">
                <button class="btn secondary" data-i18n="subscribe">Subscribe</button>
            </form>
            <div id="subs" class="subs"></div>
        </section>
    </div>

    <section class="card">
        <div class="summary-head">
            <h2 data-i18n="summaryTitle">Overview</h2>
            <span class="total" id="total"></span>
        </div>
        <div class="bar" id="bar"></div>
        <div class="tiles" id="tiles"></div>
    </section>

    <section class="card">
        <div class="feed-head">
            <h2 data-i18n="feedTitle">Event stream</h2>
            <button id="clear" class="btn secondary" data-i18n="clear">Clear</button>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th data-i18n="colTime">Time</th>
                    <th data-i18n="colChannel">Channel</th>
                    <th data-i18n="colNotification">Notification</th>
                    <th data-i18n="colStatus">Status</th>
                    <th data-i18n="colAttempts">Attempts</th>
                    <th data-i18n="colDetail">Detail</th>
                </tr></thead>
                <tbody id="events"></tbody>
            </table>
            <div id="empty" class="empty" data-i18n="empty">No events yet. Send a demo batch or subscribe to a channel.</div>
        </div>
    </section>
</main>
<script>
    const i18n = {
        en: {
            title: 'Live notification status',
            navHealth: 'Health', navMetrics: 'Metrics', navRealtime: 'Live status',
            lead: 'Status changes are pushed over WebSocket (Laravel Reverb) the moment they happen. Watch a single notification, a whole batch, or send a demo batch to see it in action.',
            demoTitle: 'Try it',
            demoHint: 'Creates 30 notifications (SMS, email, push) scheduled 5 seconds ahead and follows the batch live.',
            demoButton: 'Send demo batch',
            demoSending: 'Sending…',
            demoSent: n => `Batch of ${n} created. Delivery starts in about 5 seconds.`,
            demoError: 'Could not create the batch',
            watchTitle: 'Watch a channel',
            watchHint: 'Enter <code>batches.{id}</code> for a batch or <code>notifications.{id}</code> for a single notification.',
            subscribe: 'Subscribe',
            invalidChannel: 'Channel must start with batches. or notifications.',
            summaryTitle: 'Overview',
            total: n => `${n} notifications tracked`,
            feedTitle: 'Event stream',
            clear: 'Clear',
            colTime: 'Time', colChannel: 'Channel', colNotification: 'Notification', colStatus: 'Status', colAttempts: 'Attempts', colDetail: 'Detail',
            empty: 'No events yet. Send a demo batch or subscribe to a channel.',
            retryAt: t => `retry at ${t}`,
            batchUpdate: 'batch update',
            conn: { connecting: 'Connecting', connected: 'Connected', unavailable: 'Unavailable', failed: 'Failed', disconnected: 'Disconnected', initialized: 'Starting' },
            status: { scheduled: 'Scheduled', pending: 'Pending', queued: 'Queued', processing: 'Processing', sent: 'Sent', failed: 'Failed', cancelled: 'Cancelled', batch: 'Batch' },
        },
        tr: {
            title: 'Canlı bildirim durumu',
            navHealth: 'Sağlık', navMetrics: 'Metrikler', navRealtime: 'Canlı durum',
            lead: 'Durum değişiklikleri gerçekleştiği anda WebSocket (Laravel Reverb) üzerinden iletilir. Tek bir bildirimi ya da bütün bir toplu gönderimi izleyin veya demo gönderimiyle deneyin.',
            demoTitle: 'Deneyin',
            demoHint: '5 saniye sonrasına planlanmış 30 bildirim (SMS, e-posta, push) oluşturur ve toplu gönderimi canlı izler.',
            demoButton: 'Demo gönderimi başlat',
            demoSending: 'Gönderiliyor…',
            demoSent: n => `${n} bildirimlik toplu gönderim oluşturuldu. Teslimat yaklaşık 5 saniye içinde başlayacak.`,
            demoError: 'Toplu gönderim oluşturulamadı',
            watchTitle: 'Kanal izle',
            watchHint: 'Toplu gönderim için <code>batches.{id}</code>, tek bildirim için <code>notifications.{id}</code> girin.',
            subscribe: 'Abone ol',
            invalidChannel: 'Kanal batches. veya notifications. ile başlamalı',
            summaryTitle: 'Genel bakış',
            total: n => `${n} bildirim izleniyor`,
            feedTitle: 'Olay akışı',
            clear: 'Temizle',
            colTime: 'Saat', colChannel: 'Kanal', colNotification: 'Bildirim', colStatus: 'Durum', colAttempts: 'Deneme', colDetail: 'Ayrıntı',
            empty: 'Henüz olay yok. Demo gönderimi başlatın veya bir kanala abone olun.',
            retryAt: t => `${t} tekrar denenecek`,
            batchUpdate: 'toplu güncelleme',
            conn: { connecting: 'Bağlanıyor', connected: 'Bağlı', unavailable: 'Erişilemiyor', failed: 'Başarısız', disconnected: 'Bağlantı kesildi', initialized: 'Başlatılıyor' },
            status: { scheduled: 'Planlandı', pending: 'Beklemede', queued: 'Kuyrukta', processing: 'İşleniyor', sent: 'Gönderildi', failed: 'Başarısız', cancelled: 'İptal edildi', batch: 'Toplu' },
        },
    };

    const statuses = ['scheduled', 'pending', 'queued', 'processing', 'sent', 'failed', 'cancelled'];
    const latest = {};
    const events = [];
    const subscribed = new Set();
    const $ = id => document.getElementById(id);
    let lang = localStorage.getItem('ui.lang') || localStorage.getItem('realtime.lang')
        || ((navigator.language || '').toLowerCase().startsWith('tr') ? 'tr' : 'en');
    let connState = 'connecting';

    const t = key => i18n[lang][key];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const timeFmt = d => d.toLocaleTimeString(lang === 'tr' ? 'tr-TR' : 'en-GB');

    function setLang(next) {
        lang = next;
        localStorage.setItem('ui.lang', next);
        document.documentElement.lang = next;
        document.querySelectorAll('[data-i18n]').forEach(el => el.textContent = t(el.dataset.i18n));
        document.querySelectorAll('[data-i18n-html]').forEach(el => el.innerHTML = t(el.dataset.i18nHtml));
        document.querySelectorAll('.lang button').forEach(b => b.classList.toggle('active', b.dataset.lang === next));
        $('demo-msg').textContent = '';
        renderConn();
        renderCounts();
        renderEvents();
    }

    const pusher = new Pusher(@json(config('broadcasting.connections.reverb.key')), {
        wsHost: window.location.hostname,
        wsPort: @json(config('notifications.realtime.public_port')),
        wssPort: @json(config('notifications.realtime.public_port')),
        forceTLS: window.location.protocol === 'https:',
        enabledTransports: ['ws', 'wss'],
        cluster: 'local',
    });

    pusher.connection.bind('state_change', ({ current }) => { connState = current; renderConn(); });

    function renderConn() {
        const pill = $('conn');
        pill.classList.toggle('on', connState === 'connected');
        pill.classList.toggle('off', ['unavailable', 'failed', 'disconnected'].includes(connState));
        $('conn-text').textContent = t('conn')[connState] || connState;
    }

    function renderCounts() {
        const counts = Object.fromEntries(statuses.map(s => [s, 0]));
        Object.values(latest).forEach(s => counts[s] = (counts[s] || 0) + 1);
        const total = Object.values(latest).length;
        $('total').textContent = t('total')(total);
        Motion.patch($('bar'), statuses
            .map(s => `<span style="width:${total ? counts[s] / total * 100 : 0}%;background:var(--c-${s})" title="${t('status')[s]}: ${counts[s]}"></span>`).join(''));
        Motion.patch($('tiles'), statuses
            .map(s => `<div class="tile" style="--c:var(--c-${s})"><b>${counts[s]}</b><span>${t('status')[s]}</span></div>`).join(''));
    }

    function renderEvents() {
        $('empty').style.display = events.length ? 'none' : 'block';
        $('events').innerHTML = events.map(rowHtml).join('');
    }

    function rowHtml(e) {
        const detail = e.kind === 'batch'
            ? `${t('batchUpdate')}: ${Object.entries(e.statuses || {}).filter(([, n]) => n).map(([s, n]) => `${t('status')[s] || s} ${n}`).join(', ')}`
            : (e.next_retry_at ? t('retryAt')(timeFmt(new Date(e.next_retry_at))) : (e.last_error || ''));
        const status = e.kind === 'batch' ? 'batch' : e.status;
        return `<tr>
            <td>${timeFmt(e.at)}</td>
            <td><span class="chan">${esc(e.channel || '—')}</span></td>
            <td><code title="${esc(e.id)}">…${esc((e.id || '').slice(-8))}</code></td>
            <td><span class="badge" style="--c:var(--c-${status}, var(--accent))">${esc(t('status')[status] || status)}</span></td>
            <td>${esc(e.attempts ?? '')}</td>
            <td class="detail">${esc(detail)}</td>
        </tr>`;
    }

    function addEvent(e) {
        events.unshift({ ...e, at: new Date() });
        if (events.length > 300) events.pop();
        $('events').insertAdjacentHTML('afterbegin', rowHtml(events[0]));
        if ($('events').rows.length > 300) $('events').deleteRow(-1);
        $('empty').style.display = 'none';
    }

    function subscribe(name) {
        $('demo-msg').className = 'msg';
        if (!/^(batches|notifications)\.[\w-]+$/.test(name)) {
            $('demo-msg').className = 'msg error';
            $('demo-msg').textContent = t('invalidChannel');
            return;
        }
        if (subscribed.has(name)) return;
        subscribed.add(name);
        const channel = pusher.subscribe(name);
        channel.bind('notification.status', e => { latest[e.id] = e.status; addEvent(e); renderCounts(); });
        channel.bind('batch.updated', e => addEvent({ kind: 'batch', id: e.batch_id, statuses: e.statuses }));
        $('subs').insertAdjacentHTML('beforeend', `<span class="sub">${esc(name)}</span>`);
        $('channel').value = '';
    }

    async function sendDemoBatch() {
        const btn = $('demo');
        btn.disabled = true;
        btn.textContent = t('demoSending');
        $('demo-msg').className = 'msg';
        $('demo-msg').textContent = '';
        try {
            const channels = ['sms', 'email', 'push'];
            const notifications = Array.from({ length: 30 }, (_, i) => {
                const channel = channels[i % 3];
                return {
                    channel,
                    recipient: { sms: '+9055500' + String(i).padStart(5, '0'), email: `user${i}@example.com`, push: 'device-token-' + String(i).padStart(10, '0') }[channel],
                    subject: channel === 'sms' ? undefined : 'Flash sale',
                    content: `Flash sale #${i}`,
                    priority: ['high', 'normal', 'low'][i % 3],
                    // Scheduled a few seconds ahead so the page is subscribed before delivery starts.
                    scheduled_at: new Date(Date.now() + 5000).toISOString(),
                };
            });
            const res = await fetch('/api/v1/notifications/batch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ notifications }),
            });
            const body = await res.json();
            if (!res.ok) throw new Error(body?.error?.message || res.status);
            body.data.notifications.forEach(n => latest[n.id] = n.status);
            renderCounts();
            subscribe('batches.' + body.data.batch_id);
            $('demo-msg').textContent = t('demoSent')(notifications.length);
        } catch (err) {
            $('demo-msg').className = 'msg error';
            $('demo-msg').textContent = `${t('demoError')}: ${err.message}`;
        } finally {
            btn.disabled = false;
            btn.textContent = t('demoButton');
        }
    }

    $('demo').addEventListener('click', sendDemoBatch);
    $('sub-form').addEventListener('submit', ev => { ev.preventDefault(); subscribe($('channel').value.trim()); });
    $('clear').addEventListener('click', () => { events.length = 0; renderEvents(); });
    document.querySelectorAll('.lang button').forEach(b => b.addEventListener('click', () => Motion.swap(() => setLang(b.dataset.lang))));

    Motion.quietly(() => setLang(lang));
</script>
</body>
</html>

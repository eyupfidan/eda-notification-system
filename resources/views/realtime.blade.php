<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Live status · {{ config('app.name') }}</title>
    <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>
    <style>
        :root { --fg:#0f172a; --muted:#64748b; --line:#e2e8f0; --bg:#f8fafc; --accent:#2563eb; }
        * { box-sizing: border-box; }
        body { margin:0; font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif; color:var(--fg); background:var(--bg); }
        main { max-width: 960px; margin: 40px auto; padding: 0 20px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        p.lead { color: var(--muted); margin: 0 0 24px; }
        .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px 20px; margin-bottom:16px; }
        .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        input { flex:1; min-width:260px; padding:8px 10px; border:1px solid var(--line); border-radius:8px; font:inherit; }
        button { padding:8px 14px; border:0; border-radius:8px; background:var(--accent); color:#fff; font:inherit; cursor:pointer; }
        button.secondary { background:#fff; color:var(--fg); border:1px solid var(--line); }
        .dot { display:inline-block; width:8px; height:8px; border-radius:50%; background:#cbd5e1; margin-right:6px; }
        .dot.on { background:#22c55e; }
        .counts { display:grid; grid-template-columns:repeat(7,1fr); gap:8px; margin-top:12px; }
        .count { border:1px solid var(--line); border-radius:8px; padding:8px; text-align:center; }
        .count b { display:block; font-size:20px; }
        .count span { color:var(--muted); font-size:12px; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th, td { text-align:left; padding:6px 8px; border-bottom:1px solid var(--line); }
        th { color:var(--muted); font-weight:500; }
        code { font-size:12px; }
        .badge { padding:2px 8px; border-radius:999px; font-size:12px; background:#f1f5f9; }
        .s-sent { background:#dcfce7; color:#166534; } .s-failed { background:#fee2e2; color:#991b1b; }
        .s-processing { background:#dbeafe; color:#1e40af; } .s-queued { background:#fef9c3; color:#854d0e; }
        .s-cancelled { background:#f1f5f9; color:#475569; }
    </style>
</head>
<body>
<main>
    <h1>Live notification status</h1>
    <p class="lead">Status changes pushed over WebSocket (Laravel Reverb). Subscribe to <code>notifications.{id}</code> or <code>batches.{id}</code>, or send a demo batch.</p>

    <div class="card">
        <div class="row">
            <span><span id="conn" class="dot"></span><span id="conn-text">connecting…</span></span>
        </div>
        <div class="row" style="margin-top:12px">
            <input id="channel" placeholder="batches.01a2… or notifications.01a2…">
            <button onclick="subscribe(document.getElementById('channel').value.trim())">Subscribe</button>
            <button class="secondary" onclick="sendDemoBatch()">Send demo batch (30, in 5 s)</button>
        </div>
        <div class="counts" id="counts"></div>
    </div>

    <div class="card">
        <table>
            <thead><tr><th>Time</th><th>Channel</th><th>Notification</th><th>Status</th><th>Attempts</th><th>Detail</th></tr></thead>
            <tbody id="events"></tbody>
        </table>
    </div>
</main>
<script>
    const statuses = ['scheduled', 'pending', 'queued', 'processing', 'sent', 'failed', 'cancelled'];
    const latest = {};

    const pusher = new Pusher(@json(config('broadcasting.connections.reverb.key')), {
        wsHost: window.location.hostname,
        wsPort: @json(config('notifications.realtime.public_port')),
        forceTLS: false,
        enabledTransports: ['ws'],
        cluster: 'local',
    });

    pusher.connection.bind('state_change', ({ current }) => {
        document.getElementById('conn').classList.toggle('on', current === 'connected');
        document.getElementById('conn-text').textContent = current;
    });

    function renderCounts() {
        const counts = Object.fromEntries(statuses.map(s => [s, 0]));
        Object.values(latest).forEach(s => counts[s]++);
        document.getElementById('counts').innerHTML = statuses
            .map(s => `<div class="count"><b>${counts[s]}</b><span>${s}</span></div>`).join('');
    }

    function log(channel, e) {
        const row = document.createElement('tr');
        row.innerHTML = `<td>${new Date().toLocaleTimeString()}</td><td><code>${channel}</code></td>
            <td><code>${(e.id || '').slice(-8)}</code></td>
            <td><span class="badge s-${e.status}">${e.status}</span></td><td>${e.attempts ?? ''}</td>
            <td>${e.next_retry_at ? 'retry at ' + new Date(e.next_retry_at).toLocaleTimeString() : (e.last_error || '')}</td>`;
        document.getElementById('events').prepend(row);
    }

    function subscribe(name) {
        if (!name) return;
        const channel = pusher.subscribe(name);
        channel.bind('notification.status', e => { latest[e.id] = e.status; log(name, e); renderCounts(); });
        channel.bind('batch.updated', e => log(name, { id: e.batch_id, status: 'batch', last_error: JSON.stringify(e.statuses) }));
        document.getElementById('channel').value = name;
    }

    async function sendDemoBatch() {
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
        body.data.notifications.forEach(n => latest[n.id] = n.status);
        renderCounts();
        subscribe('batches.' + body.data.batch_id);
    }

    renderCounts();
</script>
</body>
</html>

@extends('observability.layout')

@section('title', 'Health')

@section('content')
    <style>
        .hero { display: flex; align-items: center; gap: 18px; padding: 22px 24px; border-radius: 16px; margin-bottom: 16px; border: 1px solid var(--line); background: var(--card); }
        .hero { transition: border-color .5s ease, background .5s ease; }
        .hero .icon { transition: background-color .5s ease;  width: 52px; height: 52px; border-radius: 50%; display: grid; place-items: center; font-size: 24px; font-weight: 700; color: #fff; flex: none; }
        .hero.ok { background: linear-gradient(90deg, var(--ok-soft), var(--card) 60%); border-color: #bbf7d0; }
        .hero.ok .icon { background: var(--ok); }
        .hero.degraded { background: linear-gradient(90deg, var(--warn-soft), var(--card) 60%); border-color: #fde68a; }
        .hero.degraded .icon { background: var(--warn); }
        .hero.down { background: linear-gradient(90deg, var(--down-soft), var(--card) 60%); border-color: #fecaca; }
        .hero.down .icon { background: var(--down); }
        .hero h2 { margin: 0; font-size: 20px; }
        .hero p { margin: 2px 0 0; color: var(--muted); }
        .check { position: relative; overflow: hidden; }
        .check::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--c, var(--line)); }
        .check .head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .check h3 { margin: 0; font-size: 15px; }
        .check .value { font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .check .desc { color: var(--muted); font-size: 13px; margin: 4px 0 0; }
        .check .err { color: #991b1b; background: var(--down-soft); border-radius: 8px; padding: 6px 10px; font-size: 12px; margin-top: 10px; word-break: break-word; }
    </style>

    <header class="page">
        <div>
            <h1 data-i18n="title">Service health</h1>
            <p class="lead" data-i18n="lead">Database, Redis, queue, workers and scheduler. The service accepts notifications as long as the database and Redis are up.</p>
        </div>
        <div class="toolbar">
            <label><input type="checkbox" id="auto-refresh" checked> <span data-i18n="autoRefresh">Auto refresh</span></label>
            <span><span id="live-dot" class="live-dot"></span> <span data-i18n="updated">Updated</span>: <b id="updated">—</b></span>
            <a class="btn" href="/health?type=json" target="_blank" data-i18n="viewJson">View JSON</a>
        </div>
    </header>

    <section id="hero" class="hero">
        <div class="icon" id="hero-icon"></div>
        <div>
            <h2 id="hero-title"></h2>
            <p id="hero-text"></p>
        </div>
    </section>

    <div class="grid cols-3" id="checks"></div>
@endsection

@section('scripts')
<script>
    const translations = {
        en: {
            title: 'Service health',
            lead: 'Database, Redis, queue, workers and scheduler. The service accepts notifications as long as the database and Redis are up.',
            heroOk: 'All systems are running normally.',
            heroDegraded: 'The API accepts notifications, but delivery may be delayed.',
            heroDown: 'The service cannot accept notifications right now.',
            database: 'Database', redis: 'Redis', queue: 'Queue', workers: 'Workers', scheduler: 'Scheduler',
            latency: 'Response time', depth: 'Jobs waiting', active: 'Active workers', lastRun: 'Last run',
            descDatabase: 'PostgreSQL: where notifications are stored.',
            descRedis: 'Queues, rate limits, circuit breaker and heartbeats.',
            descQueue: 'Jobs waiting across the high, normal and low queues.',
            descWorkers: 'Queue workers that sent a heartbeat recently.',
            descScheduler: 'Runs scheduled notifications and the recovery sweep.',
            never: 'never',
        },
        tr: {
            title: 'Servis sağlığı',
            lead: 'Veritabanı, Redis, kuyruk, worker\'lar ve zamanlayıcı. Veritabanı ve Redis çalıştığı sürece servis bildirim kabul eder.',
            heroOk: 'Tüm sistemler normal çalışıyor.',
            heroDegraded: 'API bildirim kabul ediyor, ancak teslimat gecikebilir.',
            heroDown: 'Servis şu anda bildirim kabul edemiyor.',
            database: 'Veritabanı', redis: 'Redis', queue: 'Kuyruk', workers: 'Worker\'lar', scheduler: 'Zamanlayıcı',
            latency: 'Yanıt süresi', depth: 'Bekleyen iş', active: 'Aktif worker', lastRun: 'Son çalışma',
            descDatabase: 'PostgreSQL: bildirimlerin saklandığı yer.',
            descRedis: 'Kuyruklar, hız limitleri, devre kesici ve heartbeat\'ler.',
            descQueue: 'Yüksek, normal ve düşük öncelikli kuyruklarda bekleyen işler.',
            descWorkers: 'Yakın zamanda heartbeat gönderen kuyruk worker\'ları.',
            descScheduler: 'Planlanmış bildirimleri ve kurtarma taramasını çalıştırır.',
            never: 'hiç',
        },
    };

    const { t, esc, num, ago, time } = UI;
    const colors = { ok: 'var(--ok)', degraded: 'var(--warn)', down: 'var(--down)' };

    function metric(name, c) {
        if (c.error) return ['', ''];
        switch (name) {
            case 'database':
            case 'redis': return [t('latency'), c.latency_ms !== undefined ? `${c.latency_ms} ms` : '—'];
            case 'queue': return [t('depth'), num(c.depth)];
            case 'workers': return [t('active'), num(c.active)];
            case 'scheduler': return [t('lastRun'), c.last_run ? ago(c.last_run) : t('never')];
        }
        return ['', ''];
    }

    const $checks = document.getElementById('checks');

    function draw(data) {
        const hero = document.getElementById('hero');
        hero.className = 'hero ' + data.status;
        document.getElementById('hero-icon').textContent = { ok: '✓', degraded: '!', down: '✕' }[data.status];
        document.getElementById('hero-title').textContent = t(data.status);
        document.getElementById('hero-text').textContent = t({ ok: 'heroOk', degraded: 'heroDegraded', down: 'heroDown' }[data.status]);
        document.getElementById('updated').textContent = time(data.timestamp);

        Motion.patch($checks, Object.entries(data.checks).map(([name, c]) => {
            const [label, value] = metric(name, c);
            const desc = t('desc' + name[0].toUpperCase() + name.slice(1));
            return `<section class="card check" style="--c:${colors[c.status]}">
                <div class="head"><h3>${esc(t(name))}</h3><span class="badge ${esc(c.status)}">${esc(t(c.status))}</span></div>
                ${label ? `<div class="muted" style="font-size:12px">${esc(label)}</div><div class="value">${esc(value)}</div>` : ''}
                <p class="desc">${esc(desc)}</p>
                ${c.error ? `<div class="err">${esc(c.error)}</div>` : ''}
            </section>`;
        }).join(''));
    }

    UI.start({ translations, draw, initial: @json($health), url: () => '/health?type=json' });
</script>
@endsection

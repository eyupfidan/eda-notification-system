@extends('observability.layout')

@section('title', 'Metrics')

@section('content')
    <style>
        .queue-row { display: grid; grid-template-columns: 70px 1fr 60px; gap: 12px; align-items: center; margin-bottom: 12px; }
        .queue-row b { text-transform: capitalize; }
        .queue-row .n { text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; }
        .tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 10px; margin-top: 14px; }
        .tile { border: 1px solid var(--line); border-radius: 12px; padding: 8px 12px; position: relative; overflow: hidden; }
        .tile::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--c); }
        .tile b { display: block; font-size: 20px; font-variant-numeric: tabular-nums; }
        .tile span { color: var(--muted); font-size: 12px; }
        .breaker { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--line); }
        .breaker:last-child { border-bottom: 0; }
        .breaker small { display: block; color: var(--muted); font-size: 12px; }
    </style>

    <header class="page">
        <div>
            <h1 data-i18n="title">Metrics</h1>
            <p class="lead" data-i18n="lead">Queue depth, delivery outcomes, provider calls, latency and circuit breakers for the selected time window.</p>
        </div>
        <div class="toolbar">
            <label><span data-i18n="window">Window</span>
                <select id="window">
                    @foreach ($windows as $w)
                        <option value="{{ $w }}" @selected($w === $metrics['window'])>{{ $w }}</option>
                    @endforeach
                </select>
            </label>
            <label><input type="checkbox" id="auto-refresh" checked> <span data-i18n="autoRefresh">Auto refresh</span></label>
            <span><span id="live-dot" class="live-dot"></span> <span data-i18n="updated">Updated</span>: <b id="updated">—</b></span>
            <a class="btn" id="json-link" href="/metrics?type=json" target="_blank" data-i18n="viewJson">View JSON</a>
        </div>
    </header>

    <div class="kpis" id="kpis"></div>

    <div class="grid cols-2">
        <section class="card">
            <h2 data-i18n="queuesTitle">Queue depth</h2>
            <div id="queues"></div>
            <div class="legend" id="queue-legend"></div>
        </section>
        <section class="card">
            <h2 data-i18n="statusTitle">Notifications by status</h2>
            <div class="bar" id="status-bar"></div>
            <div class="tiles" id="status-tiles"></div>
        </section>
    </div>

    <section class="card">
        <h2 data-i18n="deliveryTitle">Delivery (final outcome in the window)</h2>
        <div class="table-wrap"><table id="delivery"></table></div>
    </section>

    <section class="card">
        <h2 data-i18n="attemptsTitle">Provider calls (including retries)</h2>
        <div class="table-wrap"><table id="attempts"></table></div>
    </section>

    <section class="card">
        <h2 data-i18n="breakersTitle">Circuit breakers</h2>
        <div id="breakers"></div>
    </section>
@endsection

@section('scripts')
<script>
    const translations = {
        en: {
            title: 'Metrics',
            lead: 'Queue depth, delivery outcomes, provider calls, latency and circuit breakers for the selected time window.',
            window: 'Window',
            kSent: 'Sent', kFailed: 'Failed', kSuccess: 'Success rate', kThroughput: 'Throughput', kP95: 'p95 latency', kWorkers: 'Active workers',
            perSecond: '/ s', inWindow: w => `last ${w}`, rateLimit: n => `limit ${n}/s per channel`, endToEnd: 'created → sent',
            priority: { high: 'High', normal: 'Normal', low: 'Low' },
            queuesTitle: 'Queue depth', ready: 'Ready', delayed: 'Delayed', reserved: 'Processing',
            statusTitle: 'Notifications by status',
            status: { scheduled: 'Scheduled', pending: 'Pending', queued: 'Queued', processing: 'Processing', sent: 'Sent', failed: 'Failed', cancelled: 'Cancelled' },
            deliveryTitle: 'Delivery (final outcome in the window)',
            attemptsTitle: 'Provider calls (including retries)',
            colChannel: 'Channel', colSent: 'Sent', colFailed: 'Failed', colSuccess: 'Success', colThroughput: 'Per second',
            colTotal: 'Calls', colOk: 'Successful', colRetryable: 'Retryable error', colPermanent: 'Permanent error',
            all: 'All',
            breakersTitle: 'Circuit breakers',
            open: 'Open', closed: 'Closed',
            breakerClosed: n => `${n} consecutive failures`,
            breakerOpen: s => `paused, retrying in ${s} s`,
        },
        tr: {
            title: 'Metrikler',
            lead: 'Seçilen zaman aralığı için kuyruk derinliği, teslimat sonuçları, sağlayıcı çağrıları, gecikme ve devre kesiciler.',
            window: 'Aralık',
            kSent: 'Gönderildi', kFailed: 'Başarısız', kSuccess: 'Başarı oranı', kThroughput: 'Verim', kP95: 'p95 gecikme', kWorkers: 'Aktif worker',
            perSecond: '/ sn', inWindow: w => `son ${w}`, rateLimit: n => `kanal başına ${n}/sn limit`, endToEnd: 'oluşturma → gönderim',
            priority: { high: 'Yüksek', normal: 'Normal', low: 'Düşük' },
            queuesTitle: 'Kuyruk derinliği', ready: 'Hazır', delayed: 'Ertelenmiş', reserved: 'İşleniyor',
            statusTitle: 'Duruma göre bildirimler',
            status: { scheduled: 'Planlandı', pending: 'Beklemede', queued: 'Kuyrukta', processing: 'İşleniyor', sent: 'Gönderildi', failed: 'Başarısız', cancelled: 'İptal edildi' },
            deliveryTitle: 'Teslimat (aralıktaki nihai sonuç)',
            attemptsTitle: 'Sağlayıcı çağrıları (tekrar denemeler dahil)',
            colChannel: 'Kanal', colSent: 'Gönderildi', colFailed: 'Başarısız', colSuccess: 'Başarı', colThroughput: 'Saniyede',
            colTotal: 'Çağrı', colOk: 'Başarılı', colRetryable: 'Tekrar denenebilir hata', colPermanent: 'Kalıcı hata',
            all: 'Toplam',
            breakersTitle: 'Devre kesiciler',
            open: 'Açık', closed: 'Kapalı',
            breakerClosed: n => `art arda ${n} hata`,
            breakerOpen: s => `durduruldu, ${s} sn sonra tekrar denenecek`,
        },
    };

    const { t, esc, num, pct, ms, time } = UI;
    const statuses = ['scheduled', 'pending', 'queued', 'processing', 'sent', 'failed', 'cancelled'];
    const parts = ['ready', 'delayed', 'reserved'];
    const $ = id => document.getElementById(id);
    const channelName = c => c === 'all' ? t('all') : c.toUpperCase();
    const rows = obj => [...Object.keys(obj).filter(c => c !== 'all'), 'all'];

    function draw(m) {
        const all = m.delivery.all;
        $('updated').textContent = time(m.generated_at);
        $('json-link').href = `/metrics?type=json&window=${m.window}`;

        const kpi = (label, value, note = '') => `<div class="kpi"><span>${esc(label)}</span><b>${esc(value)}</b><small>${esc(note)}</small></div>`;
        Motion.patch($('kpis'), [
            kpi(t('kSent'), num(all.sent), t('inWindow')(m.window)),
            kpi(t('kFailed'), num(all.failed), t('inWindow')(m.window)),
            kpi(t('kSuccess'), pct(all.success_rate), t('inWindow')(m.window)),
            kpi(t('kThroughput'), `${num(all.throughput_per_second)} ${t('perSecond')}`, t('rateLimit')(m.rate_limit_per_second_per_channel)),
            kpi(t('kP95'), ms(all.latency_ms.p95), t('endToEnd')),
            kpi(t('kWorkers'), num(m.workers.active)),
        ].join(''));

        const max = Math.max(1, ...Object.values(m.queues).map(q => q.total));
        Motion.patch($('queues'), Object.entries(m.queues).map(([name, q]) => `<div class="queue-row">
            <b>${esc(t('priority')[name] || name)}</b>
            <div class="bar">${parts.map(p => `<span style="width:${q[p] / max * 100}%;background:var(--c-${p})" title="${esc(t(p))}: ${q[p]}"></span>`).join('')}</div>
            <span class="n">${num(q.total)}</span>
        </div>`).join(''));
        Motion.patch($('queue-legend'), parts.map(p => `<span><i style="background:var(--c-${p})"></i>${esc(t(p))}</span>`).join(''));

        const counts = m.notifications_by_status;
        const total = statuses.reduce((s, k) => s + (counts[k] || 0), 0);
        Motion.patch($('status-bar'), statuses
            .map(s => `<span style="width:${total ? (counts[s] || 0) / total * 100 : 0}%;background:var(--c-${s})" title="${esc(t('status')[s])}: ${counts[s] || 0}"></span>`).join(''));
        Motion.patch($('status-tiles'), statuses
            .map(s => `<div class="tile" style="--c:var(--c-${s})"><b>${num(counts[s] || 0)}</b><span>${esc(t('status')[s])}</span></div>`).join(''));

        const latencyHead = '<th>avg</th><th>p50</th><th>p95</th><th>p99</th>';
        const latencyCells = l => `<td>${ms(l.avg)}</td><td>${ms(l.p50)}</td><td>${ms(l.p95)}</td><td>${ms(l.p99)}</td>`;

        Motion.patch($('delivery'), `<thead><tr><th>${t('colChannel')}</th><th>${t('colSent')}</th><th>${t('colFailed')}</th><th>${t('colSuccess')}</th><th>${t('colThroughput')}</th>${latencyHead}</tr></thead>
            <tbody>${rows(m.delivery).map(c => { const d = m.delivery[c]; return `<tr class="${c === 'all' ? 'total' : ''}">
                <td><span class="chan">${esc(channelName(c))}</span></td><td>${num(d.sent)}</td><td>${num(d.failed)}</td><td>${pct(d.success_rate)}</td><td>${num(d.throughput_per_second)}</td>${latencyCells(d.latency_ms)}</tr>`; }).join('')}</tbody>`);

        Motion.patch($('attempts'), `<thead><tr><th>${t('colChannel')}</th><th>${t('colTotal')}</th><th>${t('colOk')}</th><th>${t('colRetryable')}</th><th>${t('colPermanent')}</th><th>${t('colSuccess')}</th>${latencyHead}</tr></thead>
            <tbody>${rows(m.provider_attempts).map(c => { const a = m.provider_attempts[c]; return `<tr class="${c === 'all' ? 'total' : ''}">
                <td><span class="chan">${esc(channelName(c))}</span></td><td>${num(a.total)}</td><td>${num(a.success)}</td><td>${num(a.retryable_failure)}</td><td>${num(a.permanent_failure)}</td><td>${pct(a.success_rate)}</td>${latencyCells(a.latency_ms)}</tr>`; }).join('')}</tbody>`);

        Motion.patch($('breakers'), Object.entries(m.circuit_breakers).map(([c, b]) => `<div class="breaker">
            <div><span class="chan">${esc(channelName(c))}</span><small>${esc(b.state === 'open' ? t('breakerOpen')(b.retry_after) : t('breakerClosed')(b.consecutive_failures))}</small></div>
            <span class="badge ${b.state === 'open' ? 'down' : 'ok'}">${esc(t(b.state))}</span>
        </div>`).join(''));
    }

    const select = $('window');
    const refresh = UI.start({ translations, draw, initial: @json($metrics), url: () => `/metrics?type=json&window=${select.value}` });
    select.addEventListener('change', () => {
        history.replaceState(null, '', `/metrics?window=${select.value}`);
        refresh();
    });
</script>
@endsection

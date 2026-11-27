{{--
    Smooth transitions shared by the browser pages (/health, /metrics, /realtime):
    - page to page: cross-document View Transitions (old page fades out, new one slides in;
      the nav and the language switch stay in place and their indicators glide to the new tab)
    - a sliding indicator behind the active nav link and language button
    - language switch: a short cross-fade (View Transitions when available)
    - data refresh: Motion.patch() updates only what changed, so bars glide to their new width,
      changed values slide in with a short highlight and new rows fade in
    Everything is skipped with prefers-reduced-motion.
--}}
<style>
    @view-transition { navigation: auto; }

    .links, .lang { position: relative; isolation: isolate; }
    .links a, .lang button { position: relative; z-index: 1; transition: color .25s ease; }
    .links a.active, .lang button.active { background: transparent !important; }
    .slide-indicator {
        position: absolute; z-index: 0; top: 3px; bottom: 3px; left: 0; width: 0; border-radius: 999px;
        transition: transform .35s cubic-bezier(.4, 0, .2, 1), width .35s cubic-bezier(.4, 0, .2, 1);
        pointer-events: none;
    }
    .links .slide-indicator { background: var(--accent-soft); view-transition-name: nav-indicator; }
    .lang .slide-indicator { background: var(--accent); view-transition-name: lang-indicator; }
    .no-anim .slide-indicator { transition: none; }
    nav.top .links, header .links { view-transition-name: nav-links; }
    .lang { view-transition-name: lang-switch; }

    ::view-transition-old(root) { animation: vt-out .18s ease both; }
    ::view-transition-new(root) { animation: vt-in .32s cubic-bezier(.2, .8, .2, 1) both; }
    ::view-transition-group(nav-indicator),
    ::view-transition-group(lang-indicator) { animation-duration: .35s; animation-timing-function: cubic-bezier(.4, 0, .2, 1); }
    /* Language switch: only cross-fade, no slide. */
    html.vt-lang::view-transition-old(root) { animation: vt-fade-out .16s ease both; }
    html.vt-lang::view-transition-new(root) { animation: vt-fade-in .24s ease both; }

    @keyframes vt-out { to { opacity: 0; transform: translateY(-6px); } }
    @keyframes vt-in { from { opacity: 0; transform: translateY(10px); } }
    @keyframes vt-fade-out { to { opacity: 0; } }
    @keyframes vt-fade-in { from { opacity: 0; } }

    /* Data refresh */
    .bar span { transition: width .6s cubic-bezier(.4, 0, .2, 1) !important; }
    .flash { animation: data-flash 1.2s ease-out; }
    .kpi b.flash, .tile b.flash, .check .value.flash, .queue-row .n.flash { animation: data-tick .45s cubic-bezier(.2, .8, .2, 1), data-flash 1.2s ease-out; }
    .enter { animation: data-enter .4s cubic-bezier(.2, .8, .2, 1) both; }
    @keyframes data-flash { from { background-color: color-mix(in srgb, var(--accent) 22%, transparent); } to { background-color: transparent; } }
    @keyframes data-tick { from { opacity: 0; transform: translateY(8px); } }
    @keyframes data-enter { from { opacity: 0; transform: translateY(6px); } }
    .live-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--ok, #22c55e); margin-right: 2px; }
    .live-dot.busy { animation: live-pulse .8s ease-in-out infinite; }
    .live-dot.paused { background: #cbd5e1; }
    @keyframes live-pulse { 50% { transform: scale(1.6); opacity: .5; } }

    /* Browsers without View Transitions still get a gentle entrance. */
    @supports not (view-transition-name: none) {
        main { animation: vt-in .32s cubic-bezier(.2, .8, .2, 1) both; }
    }

    @media (prefers-reduced-motion: reduce) {
        ::view-transition-group(*), ::view-transition-old(*), ::view-transition-new(*) { animation: none !important; }
        .slide-indicator, .links a, .lang button { transition: none !important; }
        main { animation: none !important; }
        .flash, .enter, .live-dot.busy { animation: none !important; }
        .bar span { transition: none !important; }
    }
</style>
<script>
    const Motion = (() => {
        const reduced = () => matchMedia('(prefers-reduced-motion: reduce)').matches;

        function place(group) {
            const indicator = group.querySelector('.slide-indicator');
            const active = group.querySelector('.active');
            if (!indicator) return;
            indicator.style.opacity = active ? 1 : 0;
            if (!active) return;
            indicator.style.width = active.offsetWidth + 'px';
            indicator.style.transform = `translateX(${active.offsetLeft}px)`;
        }

        function init() {
            const groups = document.querySelectorAll('.links, .lang');
            groups.forEach(group => {
                const indicator = document.createElement('span');
                indicator.className = 'slide-indicator';
                group.prepend(indicator);
                // Follow class changes (language switch) and text width changes (translated labels).
                new MutationObserver(() => place(group)).observe(group, { subtree: true, attributes: true, attributeFilter: ['class'], childList: true, characterData: true });
            });
            // First placement without animation, so the indicator does not fly in on load.
            document.documentElement.classList.add('no-anim');
            groups.forEach(place);
            requestAnimationFrame(() => requestAnimationFrame(() => document.documentElement.classList.remove('no-anim')));
            addEventListener('resize', () => groups.forEach(place));
            document.fonts?.ready.then(() => groups.forEach(place));
        }

        // While true (language switch, first paint) patch() updates silently.
        let silent = false;

        function flash(el) {
            if (!el) return;
            el.classList.remove('flash');
            void el.offsetWidth; // restart the animation
            el.classList.add('flash');
        }

        function morph(from, to, animate) {
            if (from.nodeType !== to.nodeType || from.nodeName !== to.nodeName) {
                const fresh = to.cloneNode(true);
                from.replaceWith(fresh);
                if (animate && fresh.nodeType === 1) fresh.classList.add('enter');
                return;
            }
            if (from.nodeType === Node.TEXT_NODE) {
                if (from.nodeValue !== to.nodeValue) {
                    from.nodeValue = to.nodeValue;
                    if (animate && to.nodeValue.trim()) flash(from.parentElement);
                }
                return;
            }
            if (from.nodeType !== Node.ELEMENT_NODE) return;
            for (const a of [...from.attributes]) if (!to.hasAttribute(a.name) && a.name !== 'class') from.removeAttribute(a.name);
            for (const a of [...to.attributes]) if (from.getAttribute(a.name) !== a.value && a.name !== 'class') from.setAttribute(a.name, a.value);
            // Keep running animation classes (flash / enter); sync the rest of the class list.
            const keep = ['flash', 'enter'].filter(c => from.classList.contains(c));
            const cls = [to.getAttribute('class') || '', ...keep].join(' ').trim();
            if ((from.getAttribute('class') || '') !== cls) cls ? from.setAttribute('class', cls) : from.removeAttribute('class');

            const fc = [...from.childNodes], tc = [...to.childNodes];
            tc.forEach((child, i) => {
                if (fc[i]) return morph(fc[i], child, animate);
                const fresh = child.cloneNode(true);
                from.appendChild(fresh);
                if (animate && fresh.nodeType === 1) fresh.classList.add('enter');
            });
            fc.slice(tc.length).forEach(n => n.remove());
        }

        /** Replaces el's content with html, touching only what changed (keeps CSS transitions alive). */
        function patch(el, html) {
            const animate = !silent && !reduced() && el.childNodes.length > 0;
            const target = el.cloneNode(false);
            const tpl = document.createElement('template');
            tpl.innerHTML = html;
            target.append(tpl.content);
            morph(el, target, animate);
        }

        function quietly(update) {
            silent = true;
            try { update(); } finally { silent = false; }
        }

        /** Runs a DOM update (e.g. a language switch) with a short cross-fade and no data highlights. */
        function swap(update) {
            if (!document.startViewTransition || reduced()) return quietly(update);
            document.documentElement.classList.add('vt-lang');
            const vt = document.startViewTransition(() => {
                quietly(update);
                document.querySelectorAll('.links, .lang').forEach(place);
            });
            // A transition can be skipped (another one started, tab hidden): that is not an error.
            vt.ready.catch(() => {});
            vt.finished.catch(() => {}).finally(() => document.documentElement.classList.remove('vt-lang'));
        }

        // Cross-document transitions are skipped on reloads or when the page is revealed early;
        // the browser then rejects their promises, which is expected.
        addEventListener('pagereveal', e => {
            e.viewTransition?.ready.catch(() => {});
            e.viewTransition?.finished.catch(() => {});
        });

        document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', init) : init();

        return { swap, patch, quietly, flash };
    })();
</script>

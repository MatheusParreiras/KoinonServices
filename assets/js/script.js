/*
 * Koinon Services - interações globais
 *
 * Barra superior única (V13):
 *  - Telas internas:  [← Voltar] | Koinon Services | [🔔] [🌙]
 *  - Dashboards:      (vazio)    | Koinon Services | usuário [🔔] [🌙] [Sair]
 *  - Telas públicas (login, LGPD, definir senha, recuperação de senha):
 *    somente o botão de tema; sem voltar, sem sino e sem sair.
 *
 * A barra fica SEMPRE no fluxo normal da página (position: sticky), nunca
 * "flutuando" sobre o conteúdo; por isso não sobrepõe mais os botões das telas.
 */
(function () {
    'use strict';

    var PATH = window.location.pathname.toLowerCase();

    // Telas que ficam fora do sistema logado (nunca exibem voltar/sino/sair).
    var IS_PUBLIC = /\/(login|lgpd|definir_senha|esqueci_senha|redefinir_senha|acesso_negado)\.php$/.test(PATH);
    var IS_ACTION = /\/logout\.php$/.test(PATH);
    var IS_PUBLIC_ROOT = /\/(?:koinon-service\/)?$/.test(PATH) ||
        (/\/index\.php$/.test(PATH) && PATH.indexOf('/view/') === -1);
    var IS_PROFILE_AREA = /\/view\//.test(PATH) && !IS_PUBLIC && !IS_ACTION && !IS_PUBLIC_ROOT;
    var IS_MAIN_DASHBOARD = /\/view\/(dashboard|morador\/index)\.php$/.test(PATH);

    function getProjectRoot() {
        var script = document.currentScript || Array.from(document.scripts).find(function (s) {
            return (s.src || '').indexOf('/assets/js/script.js') !== -1;
        });
        if (!script || !script.src) return '/koinon-service/';
        try {
            var url = new URL(script.src, window.location.href);
            return url.pathname.replace(/assets\/js\/script\.js.*$/, '');
        } catch (e) {
            return '/koinon-service/';
        }
    }

    var PROJECT_ROOT = getProjectRoot();

    function isMoradorPage() {
        return /\/view\/morador\//i.test(window.location.pathname);
    }

    function notificationTarget() {
        return isMoradorPage() ? 'view/morador/notificacoes.php' : 'view/notificacoes.php';
    }

    function resolveNotificationLink(link) {
        if (!link) return notificationTarget();
        if (/^https?:\/\//i.test(link) || link.charAt(0) === '/') return link;
        if (isMoradorPage()) return PROJECT_ROOT + 'view/morador/' + link.replace(/^view\/morador\//, '');
        if (link.indexOf('view/') === 0) return PROJECT_ROOT + link;
        return PROJECT_ROOT + 'view/' + link;
    }

    /* ------------------------------------------------------------------
     * TEMA CLARO / ESCURO
     * ------------------------------------------------------------------ */
    function applyStoredTheme() {
        var saved = null;
        try { saved = localStorage.getItem('koinon-theme'); } catch (e) {}
        var preferred = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark' : 'light';
        var theme = saved || preferred;
        document.documentElement.setAttribute('data-theme', theme);
        return theme;
    }

    function getThemeButton() {
        var button = document.querySelector('.koinon-theme-toggle');
        if (button) return button;

        var theme = document.documentElement.getAttribute('data-theme') || 'light';
        button = document.createElement('button');
        button.type = 'button';
        button.className = 'koinon-theme-toggle';
        updateThemeButton(button, theme);

        button.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('koinon-theme', next); } catch (e) {}
            updateThemeButton(button, next);
        });
        return button;
    }

    function updateThemeButton(button, theme) {
        var label = theme === 'dark' ? 'Mudar para tema claro' : 'Mudar para tema escuro';
        button.innerHTML = theme === 'dark' ? '☀️' : '🌙';
        button.title = label;
        button.setAttribute('aria-label', label);
    }

    // Telas públicas / página inicial: o botão de tema entra na navbar da
    // página (se existir) ou fica fixo no canto, sem outros controles.
    function placeThemeOnPublicPage() {
        var button = getThemeButton();
        var nav = document.querySelector('.navbar');
        if (nav) {
            var container = nav.querySelector('.container-fluid, .container') || nav;
            var group = container.querySelector('.koinon-nav-actions');
            if (!group) {
                group = document.createElement('div');
                group.className = 'koinon-nav-actions d-flex align-items-center gap-2';
                container.appendChild(group);
            }
            if (!group.contains(button)) group.appendChild(button);
            return;
        }
        button.classList.add('koinon-theme-floating');
        if (!document.body.contains(button)) document.body.appendChild(button);
    }

    /* ------------------------------------------------------------------
     * BARRA SUPERIOR ÚNICA
     * ------------------------------------------------------------------ */
    function escapeHtml(value) {
        return String(value).replace(/[&<>'"]/g, function (ch) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[ch];
        });
    }

    function escapeAttr(value) { return escapeHtml(value); }

    function buildTopbar() {
        var existing = document.querySelector('.koinon-topbar');
        if (existing) return existing;

        var moradorArea = isMoradorPage();
        var homeHref = PROJECT_ROOT + (moradorArea ? 'view/morador/index.php' : 'view/dashboard.php');
        var homeLabel = moradorArea ? 'Voltar à Área do Morador' : 'Voltar ao Dashboard';

        // Lê o que já existe nas navbars antigas antes de substituí-las.
        var oldNavs = Array.from(document.querySelectorAll('.navbar'));
        var userName = '';
        var userRole = '';
        var logoutHref = '';
        var customBack = null;

        oldNavs.forEach(function (nav) {
            var logout = nav.querySelector('a[href*="logout.php"]');
            if (logout && !logoutHref) logoutHref = logout.getAttribute('href');

            var nameEl = nav.querySelector('.koinon-user-name, .fw-semibold');
            if (nameEl && !userName) {
                userName = (nameEl.textContent || '').trim();
                var small = nav.querySelector('small');
                if (small) userRole = (small.textContent || '').trim();
            }

            // Botão "← Voltar" específico da tela (ex.: QR Code -> Autorizações).
            nav.querySelectorAll('a').forEach(function (a) {
                if (customBack || a.classList.contains('navbar-brand')) return;
                var href = a.getAttribute('href') || '';
                var text = (a.textContent || '').trim();
                if (/logout\.php/.test(href) || !/voltar/i.test(text)) return;
                customBack = { href: href, label: text.replace(/^[←\s]+/, '') || 'Voltar' };
            });
        });

        var header = document.createElement('header');
        header.className = 'koinon-topbar';
        header.innerHTML =
            '<div class="koinon-topbar__inner">' +
                '<div class="koinon-topbar__left"></div>' +
                '<a class="koinon-topbar__brand" href="' + escapeAttr(IS_MAIN_DASHBOARD ? '#' : homeHref) +
                    '" aria-label="Koinon Services">Koinon Services</a>' +
                '<div class="koinon-topbar__right"></div>' +
            '</div>';

        var left = header.querySelector('.koinon-topbar__left');
        var right = header.querySelector('.koinon-topbar__right');

        if (!IS_MAIN_DASHBOARD) {
            var back = customBack || { href: homeHref, label: homeLabel };
            var backLink = document.createElement('a');
            backLink.className = 'btn btn-outline-secondary btn-sm koinon-topbar__back';
            backLink.href = back.href;
            backLink.title = back.label;
            backLink.innerHTML = '<span aria-hidden="true">←</span> ' +
                '<span class="koinon-back-full">' + escapeHtml(back.label) + '</span>' +
                '<span class="koinon-back-short">Voltar</span>';
            left.appendChild(backLink);
        }

        // Nome/perfil e "Sair" aparecem somente nos dashboards.
        if (IS_MAIN_DASHBOARD && userName) {
            var user = document.createElement('div');
            user.className = 'koinon-topbar__user text-end d-none d-md-block';
            user.innerHTML = '<div class="fw-semibold koinon-user-name">' + escapeHtml(userName) + '</div>' +
                (userRole ? '<small class="text-muted">' + escapeHtml(userRole) + '</small>' : '');
            right.appendChild(user);
        }

        if (oldNavs.length) {
            oldNavs[0].replaceWith(header);
            oldNavs.slice(1).forEach(function (nav) { nav.remove(); });
        } else {
            document.body.insertBefore(header, document.body.firstChild);
        }
        document.body.classList.add('koinon-has-topbar');

        createNotificationBell(right);
        right.appendChild(getThemeButton());

        if (IS_MAIN_DASHBOARD && logoutHref) {
            var logout = document.createElement('a');
            logout.href = logoutHref;
            logout.className = 'btn btn-outline-danger btn-sm koinon-logout-btn';
            logout.textContent = 'Sair';
            right.appendChild(logout);
        }

        if (!IS_MAIN_DASHBOARD) removeRedundantBackLinks(header, homeHref);
        return header;
    }

    // A barra já tem o botão de retorno. Remove os botões "← Voltar",
    // "← Dashboard" etc. repetidos dentro da própria tela.
    function removeRedundantBackLinks(header, homeHref) {
        var homePath;
        try { homePath = new URL(homeHref, window.location.href).pathname.toLowerCase(); }
        catch (e) { return; }

        document.querySelectorAll('a[href]').forEach(function (a) {
            if (header.contains(a)) return;
            var raw = a.getAttribute('href') || '';
            if (!raw || raw.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(raw)) return;

            var linkPath;
            try {
                var url = new URL(raw, window.location.href);
                if (url.origin !== window.location.origin) return;
                linkPath = url.pathname.toLowerCase();
            } catch (e) { return; }
            if (linkPath !== homePath) return;

            var text = (a.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            if (text.length > 40) return;
            if (!/voltar|dashboard|painel|área do morador|area do morador|início|inicio/.test(text)) return;

            var parent = a.parentElement;
            a.remove();
            if (parent && parent !== document.body && !parent.children.length &&
                !(parent.textContent || '').trim()) {
                parent.remove();
            }
        });
    }

    function createNotificationBell(container) {
        if (document.querySelector('.koinon-notification-bell')) return;

        // Mantém o comportamento anterior: o sino substitui links soltos de notificações.
        document.querySelectorAll('a[href*="notificacoes.php"]').forEach(function (existing) {
            if (!container.contains(existing)) existing.remove();
        });

        var link = document.createElement('a');
        link.href = PROJECT_ROOT + notificationTarget();
        link.className = 'koinon-notification-bell';
        link.setAttribute('aria-label', 'Notificações');
        link.title = 'Notificações';
        link.setAttribute('aria-expanded', 'false');
        link.innerHTML = [
            '<span class="koinon-notification-icon" aria-hidden="true">🔔</span>',
            '<span class="koinon-notification-badge" hidden>0</span>',
            '<span class="visually-hidden">Notificações</span>'
        ].join('');

        link.addEventListener('click', function (event) {
            event.preventDefault();
            toggleNotificationPanel(link);
        });

        container.appendChild(link);
    }

    function ensureNotificationPanel() {
        let panel = document.querySelector('.koinon-notification-panel');
        if (panel) return panel;

        panel = document.createElement('div');
        panel.className = 'koinon-notification-panel';
        panel.hidden = true;
        panel.innerHTML = [
            '<div class="koinon-notification-panel-head">',
                '<div><strong>Notificações</strong><div class="small text-muted" data-notification-summary>Carregando...</div></div>',
                '<button type="button" class="btn btn-sm btn-light" data-notification-close aria-label="Fechar">×</button>',
            '</div>',
            '<div class="koinon-notification-panel-body" data-notification-list>',
                '<div class="text-muted small p-3">Carregando notificações...</div>',
            '</div>',
            '<div class="koinon-notification-panel-foot">',
                '<button type="button" class="btn btn-sm btn-outline-primary" data-notification-read-all>Marcar todas como lidas</button>',
                '<a class="btn btn-sm btn-primary" href="' + PROJECT_ROOT + notificationTarget() + '">Ver todas</a>',
            '</div>'
        ].join('');
        document.body.appendChild(panel);

        panel.querySelector('[data-notification-close]').addEventListener('click', function () {
            closeNotificationPanel();
        });
        panel.querySelector('[data-notification-read-all]').addEventListener('click', async function () {
            await markAllNotificationsRead();
        });

        return panel;
    }

    function positionNotificationPanel(bell) {
        const panel = ensureNotificationPanel();
        const rect = bell.getBoundingClientRect();
        const width = Math.min(390, window.innerWidth - 20);
        panel.style.width = width + 'px';
        panel.style.top = Math.min(window.innerHeight - 20, rect.bottom + 10) + 'px';
        panel.style.right = Math.max(10, window.innerWidth - rect.right) + 'px';
        return panel;
    }

    async function loadNotificationPanel() {
        const panel = ensureNotificationPanel();
        const list = panel.querySelector('[data-notification-list]');
        const summary = panel.querySelector('[data-notification-summary]');
        list.innerHTML = '<div class="text-muted small p-3">Carregando notificações...</div>';

        try {
            const response = await fetch(PROJECT_ROOT + 'view/notificacao_popup.php', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            if (!response.ok) throw new Error('Falha ao carregar notificações');
            const data = await response.json();
            const items = Array.isArray(data.items) ? data.items : [];
            summary.textContent = items.length ? (items.length + ' não lida(s)') : 'Nenhuma nova notificação';

            if (!items.length) {
                list.innerHTML = '<div class="text-muted small p-3">Você não possui novas notificações.</div>';
                return;
            }

            list.innerHTML = items.slice(0, 8).map(item => {
                const title = escapeHtml(item.titulo || 'Notificação');
                const message = escapeHtml(item.mensagem || '');
                const date = escapeHtml(item.created_at_formatado || item.created_at || '');
                const href = resolveNotificationLink(item.link || '');
                const id = Number(item.id || 0);
                return '<div class="koinon-notification-item" data-notification-id="' + id + '">' +
                    '<div class="koinon-notification-item-title">' + title + '</div>' +
                    '<div class="small text-muted mt-1">' + message + '</div>' +
                    '<div class="d-flex justify-content-between align-items-center mt-2 gap-2">' +
                        '<span class="small text-muted">' + date + '</span>' +
                        '<div class="d-flex gap-1">' +
                            '<button type="button" class="btn btn-sm btn-light" data-notification-mark="' + id + '">Lida</button>' +
                            (href ? '<a class="btn btn-sm btn-outline-primary" href="' + escapeAttr(href) + '">Abrir</a>' : '') +
                        '</div>' +
                    '</div>' +
                '</div>';
            }).join('');

            list.querySelectorAll('[data-notification-mark]').forEach(btn => {
                btn.addEventListener('click', async function () {
                    await markNotificationRead(Number(btn.dataset.notificationMark || 0));
                    await loadNotificationPanel();
                    refreshNotificationBadge();
                });
            });
        } catch (e) {
            summary.textContent = 'Não foi possível carregar';
            list.innerHTML = '<div class="alert alert-warning m-3 mb-0 small">Não foi possível carregar as notificações agora.</div>';
        }
    }

    async function markNotificationRead(id) {
        if (!id) return;
        try {
            await fetch(PROJECT_ROOT + 'view/notificacao_marcar_lida.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + encodeURIComponent(id)
            });
        } catch (e) {}
    }

    async function markAllNotificationsRead() {
        try {
            await fetch(PROJECT_ROOT + 'view/notificacao_marcar_todas_lidas.php', {
                method: 'POST',
                credentials: 'same-origin'
            });
        } catch (e) {}
        await loadNotificationPanel();
        refreshNotificationBadge();
    }

    function toggleNotificationPanel(bell) {
        const panel = ensureNotificationPanel();
        if (!panel.hidden) {
            closeNotificationPanel();
            return;
        }
        positionNotificationPanel(bell);
        panel.hidden = false;
        bell.setAttribute('aria-expanded', 'true');
        loadNotificationPanel();
    }

    function closeNotificationPanel() {
        const panel = document.querySelector('.koinon-notification-panel');
        const bell = document.querySelector('.koinon-notification-bell');
        if (panel) panel.hidden = true;
        if (bell) bell.setAttribute('aria-expanded', 'false');
    }

    async function refreshNotificationBadge() {
        const badge = document.querySelector('.koinon-notification-badge');
        if (!badge) return;
        try {
            const response = await fetch(PROJECT_ROOT + 'view/notificacao_badge.php', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            if (!response.ok) return;
            const data = await response.json();
            const total = Number(data.total || 0);
            if (total > 0) {
                badge.textContent = total > 99 ? '99+' : String(total);
                badge.hidden = false;
            } else {
                badge.hidden = true;
            }
            handlePush(data.ultima);
        } catch (e) {}
    }

    /* ------------------------------------------------------------------
     * "PUSH" EM TEMPO REAL (simulação): a cada 5 s o sistema consulta a
     * última notificação não lida. Se surgir uma nova, mostra um aviso na
     * tela e, nas telas de listas, atualiza a página para refletir o novo
     * status (ex.: reserva que passou de "Aguardando pagamento" para
     * "Confirmada" assim que o morador paga).
     * ------------------------------------------------------------------ */
    var PUSH_KEY = 'koinon-push-last';
    var LIVE_PAGES = /\/(financeiro\/index|reserva\/index|morador\/(nova_reserva|financeiro|boleto|index)|dashboard)\.php$/;

    function handlePush(ultima) {
        if (!ultima || !ultima.id) return;
        var atual = Number(ultima.id);
        var visto = null;
        try { visto = sessionStorage.getItem(PUSH_KEY); } catch (e) {}
        try { sessionStorage.setItem(PUSH_KEY, String(atual)); } catch (e) {}
        if (visto === null) return;                 // primeira leitura da sessão: só marca
        if (atual <= Number(visto)) return;         // nada novo

        window.koinonToast(ultima.titulo || 'Nova notificação', ultima.mensagem || '',
            ultima.tipo === 'Pagamento' ? 'success' : 'info',
            ultima.link ? resolveNotificationLink(ultima.link) : '');

        if (ultima.tipo === 'Pagamento' && LIVE_PAGES.test(PATH)) {
            setTimeout(function () { window.location.reload(); }, 3200);
        }
    }

    window.koinonToast = function (titulo, mensagem, tipo, link) {
        var box = document.querySelector('.k-toasts');
        if (!box) {
            box = document.createElement('div');
            box.className = 'k-toasts';
            document.body.appendChild(box);
        }
        var el = document.createElement(link ? 'a' : 'div');
        el.className = 'k-toast k-toast--' + (tipo || 'info');
        if (link) el.href = link;
        el.innerHTML = '<div class="k-toast__bar"></div><div class="k-toast__body"><strong>' +
            escapeHtml(titulo) + '</strong><span>' + escapeHtml(mensagem) + '</span></div>' +
            '<button type="button" class="k-toast__x" aria-label="Fechar">×</button>';
        el.querySelector('.k-toast__x').addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation(); el.remove();
        });
        box.appendChild(el);
        setTimeout(function () { el.classList.add('is-out'); setTimeout(function () { el.remove(); }, 400); }, 8000);
    };


    /* ------------------------------------------------------------------
     * MÁSCARA DE CPF
     * ------------------------------------------------------------------ */
    function formatarCpf(valor) {
        var v = String(valor || '').replace(/\D/g, '').slice(0, 11);
        if (v.length > 9) return v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
        if (v.length > 6) return v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
        if (v.length > 3) return v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
        return v;
    }

    function initCpfMask() {
        /* Campos de CPF/documento: máscara 000.000.000-00 enquanto digita */
        document.querySelectorAll('input[name="cpf"], input[name="documento"], input[data-cpf]').forEach(function (input) {
            input.setAttribute('maxlength', '14');
            input.setAttribute('inputmode', 'numeric');
            if (!input.placeholder) input.placeholder = '000.000.000-00';
            if (input.value) input.value = formatarCpf(input.value);
            input.addEventListener('input', function () { input.value = formatarCpf(input.value); });
        });
        /* Login: aceita CPF ou e-mail; só aplica a máscara enquanto for número */
        document.querySelectorAll('input[data-cpf-ou-email]').forEach(function (input) {
            input.addEventListener('input', function () {
                var v = input.value;
                if (v !== '' && /^[\d.\-\s]+$/.test(v)) input.value = formatarCpf(v);
            });
        });
    }

    /* ------------------------------------------------------------------
     * PROTEÇÃO CSRF: acrescenta o token nos links que executam ações
     * (excluir, pagar, mudar status, cancelar...). A regra espelha
     * Csrf::ehAcaoGet() em service/Csrf.php.
     * ------------------------------------------------------------------ */
    function readCsrfCookie() {
        var match = document.cookie.match(/(?:^|;\s*)koinon_csrf=([^;]+)/);
        return match ? decodeURIComponent(match[1]) : '';
    }

    function isActionUrl(url) {
        var p = url.searchParams;
        if (p.has('excluir')) return true;
        if (p.get('acao')) return true;
        if (p.has('status') && p.has('id')) return true;
        return /\/(cancelar_reserva|cancelar_autorizacao)\.php$/i.test(url.pathname) && p.has('id');
    }

    function protectActionLinks() {
        var token = readCsrfCookie();
        if (!token) return;

        document.querySelectorAll('a[href]').forEach(function (a) {
            var raw = a.getAttribute('href') || '';
            if (!raw || raw.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(raw)) return;
            try {
                var url = new URL(raw, window.location.href);
                if (url.origin !== window.location.origin || url.searchParams.has('_csrf')) return;
                if (!isActionUrl(url)) return;
                url.searchParams.set('_csrf', token);
                a.setAttribute('href', url.pathname + url.search + url.hash);
            } catch (e) { /* ignora links inválidos */ }
        });
    }

    /* ------------------------------------------------------------------
     * EFEITOS VISUAIS (V14): revelar ao rolar, contadores e navbar da home
     * ------------------------------------------------------------------ */
    var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function initReveal() {
        var alvos = document.querySelectorAll('.service-card, .profile-card, .about-section .col-lg-6, #contato .card, .cta-section .container');
        if (!alvos.length || REDUCED || !('IntersectionObserver' in window)) return;
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting) { e.target.classList.add('k-in'); io.unobserve(e.target); }
            });
        }, { threshold: 0.12 });
        alvos.forEach(function (el, i) {
            el.classList.add('k-reveal');
            el.style.transitionDelay = ((i % 4) * 90) + 'ms';
            io.observe(el);
        });
    }

    function initCountUp() {
        if (REDUCED) return;
        document.querySelectorAll('.morador-stat .h3, [data-countup]').forEach(function (el) {
            var texto = (el.textContent || '').trim();
            if (!/^\d+$/.test(texto)) return;
            var alvo = parseInt(texto, 10);
            if (alvo < 2 || alvo > 100000) return;
            var inicio = null;
            function passo(t) {
                if (inicio === null) inicio = t;
                var p = Math.min((t - inicio) / 900, 1);
                el.textContent = String(Math.round(alvo * (1 - Math.pow(1 - p, 3))));
                if (p < 1) requestAnimationFrame(passo);
            }
            el.textContent = '0';
            requestAnimationFrame(passo);
        });
    }

    function initLandingNav() {
        var nav = document.querySelector('.koinon-landing-nav');
        if (!nav) return;
        function aoRolar() { nav.classList.toggle('is-scrolled', window.scrollY > 24); }
        aoRolar();
        window.addEventListener('scroll', aoRolar, { passive: true });

        // Link ativo conforme a seção visível (scrollspy leve).
        var links = Array.from(nav.querySelectorAll('.koinon-navlink[href^="#"]'));
        if (!links.length || !('IntersectionObserver' in window)) return;
        var mapa = {};
        links.forEach(function (a) {
            var alvo = document.querySelector(a.getAttribute('href'));
            if (alvo) mapa[alvo.id] = a;
        });
        var spy = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (!e.isIntersecting || !mapa[e.target.id]) return;
                links.forEach(function (a) { a.classList.remove('is-active'); });
                mapa[e.target.id].classList.add('is-active');
            });
        }, { rootMargin: '-45% 0px -50% 0px' });
        Object.keys(mapa).forEach(function (id) { spy.observe(document.getElementById(id)); });
    }

    /* ------------------------------------------------------------------
     * INICIALIZAÇÃO
     * ------------------------------------------------------------------ */
    function init() {
        applyStoredTheme();
        initCpfMask();
        protectActionLinks();
        initReveal();
        initCountUp();
        initLandingNav();

        if (IS_PROFILE_AREA) {
            buildTopbar();
            refreshNotificationBadge();
            setInterval(refreshNotificationBadge, 5000);
        } else if (!IS_ACTION) {
            placeThemeOnPublicPage();
        }

        document.addEventListener('click', function (event) {
            var panel = document.querySelector('.koinon-notification-panel');
            var bell = document.querySelector('.koinon-notification-bell');
            if (panel && !panel.hidden && !panel.contains(event.target) && bell && !bell.contains(event.target)) {
                closeNotificationPanel();
            }
        });

        window.addEventListener('resize', function () {
            var panel = document.querySelector('.koinon-notification-panel');
            var bell = document.querySelector('.koinon-notification-bell');
            if (panel && !panel.hidden && bell) positionNotificationPanel(bell);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();

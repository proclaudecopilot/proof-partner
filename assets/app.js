/* Proof Partner — партньорска книга (SPA, vanilla, no deps) */
(function () {
	'use strict';

	var BOOT = window.PROOF_PARTNER_BOOT || {};
	var root = document.getElementById('proof-partner-app');
	if (!root) return;

	/* ---------------- utils ---------------- */

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	var nf = new Intl.NumberFormat('bg-BG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
	function money(v, cur) { return nf.format(Number(v) || 0) + '\u00A0' + (cur || BOOT.currency || ''); }
	function dt(s) { return s ? esc(String(s).slice(0, 16).replace('T', ' ')) : ''; }
	function rateFmt(r) { return String(parseFloat(r)).replace('.', ','); }
	/* Резюме на „живите" поръчки от tracked_from нататък, минали през engine-а при свързване/проверка */
	function liveLine(l) {
		if (!l) return '';
		var t = '\nОт датата нататък: ' + l.scanned + ' поръчки с кода са проверени, ' + l.created + ' нови реда в книгата';
		if (l.skipped) t += ' (' + l.skipped + ' вече имаха ред или са отказани/върнати)';
		if (l.more) t += ' · остават ' + l.more + ' — фоновата мрежа ще ги довърши';
		return t + '.';
	}

	function api(path, opts) {
		opts = opts || {};
		var conf = {
			method: opts.method || 'GET',
			headers: { 'X-WP-Nonce': BOOT.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin'
		};
		if (opts.body) conf.body = JSON.stringify(opts.body);
		return fetch(BOOT.restUrl + path, conf).then(function (r) {
			return r.text().then(function (txt) {
				var j = null;
				try { j = JSON.parse(txt); } catch (e) {
					throw new Error('HTTP ' + r.status + ' — не-JSON отговор: ' + txt.replace(/<[^>]*>/g, ' ').trim().slice(0, 120));
				}
				if (!r.ok) throw new Error((j && j.message) || ('HTTP ' + r.status));
				return j;
			});
		});
	}

	/* Попъп със съобщение + ОК (за резултати, които трябва да се прочетат; тостът е за дреболии) */
	function notice(title, msg, isErr) {
		var ov = document.createElement('div');
		ov.className = 'ap-overlay ap-notice';
		ov.style.cssText = 'position:fixed;inset:0;z-index:100003;background:rgba(22,48,63,.45);display:flex;align-items:center;justify-content:center;padding:16px';
		ov.innerHTML = '<div class="ap-modal" role="alertdialog" style="width:520px;border-top:6px solid ' + (isErr ? 'var(--bad)' : 'var(--coral)') + '"><h2>' + esc(title) + '</h2><p style="margin:0 0 4px;white-space:pre-line;line-height:1.55">' + esc(msg) + '</p><div class="ap-actions"><button type="button" class="ap-btn" data-ok>Разбрах</button></div></div>';
		document.body.appendChild(ov);
		function close() { ov.remove(); document.removeEventListener('keydown', onKey); }
		function onKey(e) { if (e.key === 'Escape' || e.key === 'Enter') { e.preventDefault(); close(); } }
		ov.querySelector('[data-ok]').addEventListener('click', close);
		ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
		document.addEventListener('keydown', onKey);
		setTimeout(function () { ov.querySelector('[data-ok]').focus(); }, 20);
	}
	function toast(msg, isErr) {
		var box = document.querySelector('.ap-toasts');
		if (!box) {
			box = document.createElement('div');
			box.className = 'ap-toasts';
			// Критичното позициониране е inline — имунно на кеширан/минифициран CSS.
			box.style.cssText = 'position:fixed;right:22px;bottom:22px;z-index:100002;display:flex;flex-direction:column;gap:8px';
			document.body.appendChild(box);
		}
		var t = document.createElement('div');
		t.className = 'ap-toast' + (isErr ? ' err' : '');
		t.textContent = msg;
		box.appendChild(t);
		setTimeout(function () { t.remove(); }, isErr ? 6000 : 3500);
	}

	/* ---------- Боен пояс: екранът не може да "изчезне" тихо ----------
	 * 1) Контейнерът си самовъзстановява критичните стилове (ако друг CSS/
	 *    оптимизатор ги убие, до 2 сек се връщат + виждаме броя инциденти).
	 * 2) Всяка неуловена JS грешка излиза като червен постоянен банер с
	 *    текста — следващият "скрит екран" ще се самообясни. */
	(function () {
		/* 0) Изход от чужд <form>: друг плъгин увива wp-admin съдържанието
		 * във форма → всеки click може да submit-не (вкл. програмно, което
		 * никой guard не спира). Изваждаме приложението ИЗВЪН формата. */
		try {
			var appEl = document.getElementById('proof-partner-app');
			var wrapForm = appEl && appEl.closest ? appEl.closest('form') : null;
			if (appEl && appEl.parentNode !== document.body) {
				document.body.appendChild(appEl); // fixed сме: мястото в DOM е без значение, но чуждите пре-рендери вече не ни стигат
				if (wrapForm) console.warn('[proof-partner] контейнерът беше в чужд <form> (' + (wrapForm.id || wrapForm.getAttribute('action') || '?') + ') — преместен в <body>');
			}
		} catch (escErr) {}

		var CRIT = 'position:fixed;inset:0;z-index:99999;display:grid;grid-template-columns:232px 1fr;';
		var incidents = 0;
		function assertShell() {
			var el = document.getElementById('proof-partner-app');
			if (!el) return;
			var cs = getComputedStyle(el);
			if (cs.position !== 'fixed' || cs.display === 'none' || parseFloat(cs.opacity) < 0.5) {
				incidents++;
				el.style.cssText += CRIT + 'opacity:1;';
				if (incidents === 1) console.warn('[proof-partner] style-loss: контейнерът загуби стиловете си — възстановен');
			}
		}
		assertShell();
		setInterval(assertShell, 2000);

		function panic(msg) {
			console.error('[proof-partner]', msg);
			try { notice('Грешка в приложението', msg, true); } catch (x) {}
		}
		// Банерът крещи САМО за наши грешки; чуждите admin скриптове
		// (LiteSpeed "Angie", heartbeat и т.н.) отиват тихо в console.
		function isOurs(src, stack) {
			var sig = 'proof-partner';
			return (src && src.indexOf(sig) !== -1) || (stack && String(stack).indexOf(sig) !== -1);
		}
		window.addEventListener('error', function (e) {
			var msg = (e.message || 'error') + ' @ ' + (e.filename || '').split('/').pop() + ':' + (e.lineno || '');
			if (isOurs(e.filename, e.error && e.error.stack)) panic(msg);
			else console.warn('[proof-partner] чужда JS грешка (игнорирана):', msg);
		});
		window.addEventListener('unhandledrejection', function (e) {
			var msg = 'Promise: ' + ((e.reason && e.reason.message) || e.reason || '?');
			if (isOurs('', e.reason && e.reason.stack)) panic(msg);
			else console.warn('[proof-partner] чужд promise reject (игнориран):', msg);
		});

		/* 3) УБИЕЦЪТ на тихите form submit-и: всеки <button> без type в
		 * приложението по подразбиране е type=submit — ако ЧУЖД плъгин е
		 * увил wp-admin съдържанието във <form>, клик = навигация = "скрит
		 * екран". Прихващаме в capture фаза: форсираме type=button и спираме
		 * default действието. Нашите си listeners работят необезпокоявани. */
		document.addEventListener('click', function (e) {
			var btn = e.target && e.target.closest ? e.target.closest('button') : null;
			if (!btn || !btn.closest('#proof-partner-app, .ap-overlay, .ap-toasts, .ap-notice')) return;
			if (btn.getAttribute('type') === null || btn.type === 'submit') {
				btn.type = 'button';
				e.preventDefault(); // спира само form submit / default; listeners си текат
			}
			try { sessionStorage.setItem('ap_lastclick', JSON.stringify({ t: Date.now(), label: (btn.textContent || '').trim().slice(0, 40) })); } catch (err) {}
		}, true);

		/* 4) Черна кутия: ако страницата се презареди до 4 сек след клик —
		 * навигацията Е причината за "скрития екран" и го казваме на глас. */
		try {
			var lc = JSON.parse(sessionStorage.getItem('ap_lastclick') || 'null');
			if (lc && Date.now() - lc.t < 4000) {
				panic('Страницата се ПРЕЗАРЕДИ ' + Math.round((Date.now() - lc.t) / 100) / 10 + ' сек след клик на „' + lc.label + '" — тиха навигация (form submit). Ако виждаш това, поправката отдолу вече я блокира; прати screenshot.');
				sessionStorage.removeItem('ap_lastclick');
			}
		} catch (err) {}

		/* 5) Съдържанието не може да остане празно: ако view-то е без деца
		 * два поредни тика (4 сек), самопрезареждаме текущия екран. */
		var emptyTicks = 0;
		setInterval(function () {
			var v = document.getElementById('ap-view');
			if (!v) {
				// Целият шел е изтрит (чужд admin скрипт е пре-рендерирал региона ни).
				try {
					console.warn('[proof-partner] shell-loss: чужд скрипт изтри приложението — престрояване');
					var host = document.getElementById('proof-partner-app');
					if (!host) { host = document.createElement('div'); host.id = 'proof-partner-app'; }
					if (host.parentNode !== document.body) document.body.appendChild(host);
					if (typeof window.__apRebuild === 'function') window.__apRebuild();
				} catch (err) {}
				return;
			}
			if (v.children.length === 0 && v.textContent.trim() === '') {
				emptyTicks++;
				if (emptyTicks >= 2) { emptyTicks = 0; try { panic('view-loss: съдържанието изчезна — самовъзстановяване'); route(); } catch (err) {} }
			} else { emptyTicks = 0; }
		}, 2000);
	})();

	var STATUS_BG = { pending: 'Изчакваща', approved: 'За изплащане', paid: 'Изплатена', reversed: 'Сторнирана', history: 'История (0 €)', fixed: 'Фиксиран договор (0 €)' };
	var PSTATUS_BG = { applicant: 'Кандидат', active: 'Активен', paused: 'Пауза', archived: 'Архив' };
	function chip(status, label) { return '<span class="ap-chip ' + esc(status) + '">' + esc(label || STATUS_BG[status] || status) + '</span>'; }

	/* ---------------- shell ---------------- */

	function buildShell() {
		root = document.getElementById('proof-partner-app') || root;
		root.innerHTML =
			'<aside class="ap-side">' +
				'<div class="ap-brand">' +
					(BOOT.logoUrl ? '<img class="ap-logo" src="' + esc(BOOT.logoUrl) + '" alt="Proof" width="94">' : '<span class="dot"></span>PROOF') +
					'<small>Партньорска книга</small></div>' +
				'<nav class="ap-nav" id="ap-nav">' +
					'<a href="#/dashboard" data-r="dashboard"><span class="ico">◧</span>Табло</a>' +
					'<a href="#/partners" data-r="partners"><span class="ico">◉</span>Партньори</a>' +
					'<a href="#/commissions" data-r="commissions"><span class="ico">≣</span>Комисиони</a>' +
					'<a href="#/payouts" data-r="payouts"><span class="ico">↧</span>Изплащания</a>' +
					'<a href="#/marketing" data-r="marketing"><span class="ico">📣</span>Маркетинг</a>' +
					'<a href="#/proof" data-r="proof"><span class="ico">📦</span><span title="Пакети · Подновявания">Пакети</span></a>' +
					'<a href="#/import" data-r="import"><span class="ico">⇲</span>Импорт купони</a>' +
					'<a href="#/game" data-r="game"><span class="ico">🎮</span>Игра</a>' +
					'<a href="#/ranking" data-r="ranking"><span class="ico">🏅</span>Ранглиста</a>' +
					'<a href="#/diagnostics" data-r="diagnostics"><span class="ico">✓</span>Диагностика</a>' +
				'</nav>' +
				'<div class="ap-side-foot">' +
					'<a href="' + esc(BOOT.settingsUrl) + '">Настройки</a>' +
					'<a href="' + esc(BOOT.classicUrl) + '">Класически изглед</a>' +
				'</div>' +
			'</aside>' +
			'<div class="ap-main">' +
				'<header class="ap-top">' +
					'<h1 id="ap-title">Табло</h1>' +
					'<div class="ap-owed"><div class="lbl">Дължимо към партньори</div><div class="val" id="ap-owed">—</div></div>' +
				'</header>' +
				'<main class="ap-content" id="ap-view"></main>' +
			'</div>';
	}
	buildShell();

	var $view, $title;
	function bindShell() {
		$view = document.getElementById('ap-view');
		$title = document.getElementById('ap-title');
	}
	bindShell();
	window.__apRebuild = function () {
		try { buildShell(); bindShell(); route(); refreshOwed(); } catch (e) { console.warn('[proof-partner] rebuild:', e); }
	};

	function refreshOwed() {
		api('payables').then(function (rows) {
			var byCur = {};
			rows.forEach(function (r) { byCur[r.currency] = (byCur[r.currency] || 0) + r.net; });
			var parts = Object.keys(byCur).map(function (c) { return money(byCur[c], c); });
			document.getElementById('ap-owed').textContent = parts.length ? parts.join('  +  ') : money(0);
		}).catch(function () {});
	}


	/* =============== ИГРАТА =============== */
	function viewGame() {
		loading('Игра');
		Promise.all([api('game/config'), api('game/content?status=pending'), api('game/standings')]).then(function (res) {
			var c = res[0], queue = res[1], st = res[2];
			function f(id, label, val, type) {
				return '<div class="ap-field"><label>' + label + '</label>' +
					(type === 'ta' ? '<textarea class="ap-textarea" id="' + id + '" rows="3">' + esc(val || '') + '</textarea>'
						: '<input class="ap-input" id="' + id + '" value="' + esc(String(val == null ? '' : val)) + '">') + '</div>';
			}
			var html = '';

			html += '<div class="ap-panel"><h2>🎮 Играта: настройки<span class="sp"></span>' +
				'<label style="font-size:13px;font-weight:800;display:flex;align-items:center;gap:6px"><input type="checkbox" id="gm-enabled"' + (Number(c.enabled) ? ' checked' : '') + '> активна</label></h2>' +
				'<div style="padding:0 18px 16px">' +
				'<div class="ap-row2">' + f('gm-from', 'Начало (ГГГГ-ММ-ДД)', c.from) + f('gm-to', 'Край (ГГГГ-ММ-ДД)', c.to) + '</div>' +
				'<div class="ap-row2">' + f('gm-grace', 'Grace дни след края (доставки)', c.grace_days) + f('gm-minorder', 'Мин. поръчка за точки (€)', c.min_order) + '</div>' +
				'<p style="font-weight:900;margin:14px 0 6px">🎯 Точки по постижения</p>' +
				'<div class="ap-row2">' + f('gm-ptss', 'Точки / продажба', c.pts_sale) + f('gm-ptsc', 'Точки / одобрено съдържание', c.pts_content) + '</div>' +
				'<div class="ap-row2">' + f('gm-ptsr', 'Точки / реферал (заключени)', c.pts_referral) + f('gm-cap', 'Съдържание: max одобрени/седмица', c.content_cap) + '</div>' +
				'<div class="ap-row2">' + f('gm-stw', '🔥 Серия: поредни седмици за бонус', c.streak_weeks) + f('gm-stb', '🔥 Серия: бонус точки', c.streak_bonus) + '</div>' +
				'<p style="font-weight:900;margin:14px 0 6px">📣 Известия и социално</p>' +
				'<div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:8px">' +
					'<label style="font-weight:800;font-size:13.5px;display:flex;gap:6px;align-items:center"><input type="checkbox" id="gm-ovt"' + (Number(c.overtake_notify) ? ' checked' : '') + '> ⚔️ Имейл „изпревариха те"</label>' +
					'<label style="font-weight:800;font-size:13.5px;display:flex;gap:6px;align-items:center"><input type="checkbox" id="gm-dig"' + (Number(c.digest_enabled) ? ' checked' : '') + '> 📬 Седмичен дайджест</label>' +
					'<label style="font-weight:800;font-size:13.5px;display:flex;gap:6px;align-items:center"><input type="checkbox" id="gm-shr"' + (Number(c.share_enabled) ? ' checked' : '') + '> 📤 Share картичка</label>' +
				'</div>' +
				f('gm-levels', 'Нива (Име|мин.точки на ред) — кариерни, не се нулират', c.levels, 'ta') +
				'<p style="font-weight:900;margin:14px 0 6px">📑 Подредба на таблото <span style="font-weight:800;color:var(--muted,#93817a);font-size:12.5px">(партньорът вижда секциите в този ред)</span></p>' +
				'<div id="gm-layout"></div>' +
				'<input type="hidden" id="gm-layout-val" value="' + esc(c.layout || '') + '">' +
				'<details style="margin-top:8px"><summary style="cursor:pointer;font-weight:900;padding:6px 0">📝 Всички текстове (мечта, награди, правила…)</summary>' +
				f('gm-herok', 'Kicker', c.hero_kick) + f('gm-herot', 'Заглавие на мечтата', c.hero_title) +
				f('gm-heros', 'Подзаглавие', c.hero_sub, 'ta') + f('gm-chips', 'Чипове (по един на ред)', c.hero_chips, 'ta') +
				f('gm-win', 'Ред „кой печели" ({to} {final})', c.win_line, 'ta') +
				'<div class="ap-row2">' + f('gm-p1', 'Награда 1-во място (продажби)', c.prize_p1) + f('gm-p2', 'Награда 2-ро място', c.prize_p2) + '</div>' +
				'<div class="ap-row2">' + f('gm-p3', 'Награда 3-то място', c.prize_p3) + f('gm-pc', 'Сума: корона Съдържание', c.prize_content) + '</div>' +
				'<div class="ap-row2">' + f('gm-pct', 'Име: корона Съдържание', c.prize_content_t) + f('gm-pcd', 'Описание', c.prize_content_d) + '</div>' +
				'<div class="ap-row2">' + f('gm-pr', 'Сума: корона Реферали', c.prize_ref) + f('gm-prt', 'Име: корона Реферали', c.prize_ref_t) + '</div>' +
				f('gm-prd', 'Описание: корона Реферали', c.prize_ref_d) +
				'<div class="ap-row2">' + f('gm-pl', 'Сума: лотария', c.prize_lucky) + f('gm-plt', 'Име: лотария', c.prize_lucky_t) + '</div>' +
				f('gm-pld', 'Описание: лотария', c.prize_lucky_d) +
				f('gm-w1', 'Път 1: Продавай ({min})', c.way1, 'ta') + f('gm-w1f', 'Път 1: дребен шрифт', c.way1_fine) +
				f('gm-w2', 'Път 2: Създавай', c.way2, 'ta') + f('gm-w2f', 'Път 2: дребен шрифт ({cap})', c.way2_fine) +
				f('gm-w3', 'Път 3: Канù', c.way3, 'ta') + f('gm-w3f', 'Път 3: дребен шрифт', c.way3_fine) +
				f('gm-rules', 'Правила (по едно на ред; {min} {grace} {to})', c.rules, 'ta') +
				f('gm-sttxt', '🔥 Текст при серия ({n} {b})', c.streak_txt) +
				'<div class="ap-row2">' + f('gm-ovts', '⚔️ Имейл: тема', c.overtake_subj) + f('gm-digs', '📬 Дайджест: тема', c.digest_subj) + '</div>' +
				f('gm-ovtb', '⚔️ Имейл: текст ({name} {rival} {rank})', c.overtake_body, 'ta') +
				f('gm-perks', '🎁 Перкове по ниво (Име|перк на ред) — показват се в стълбицата', c.perks, 'ta') +
				'<div class="ap-row2">' + f('gm-et', 'Празно състояние: заглавие ({pts})', c.empty_title) + f('gm-es', 'Празно състояние: текст', c.empty_sub) + '</div>' +
				'</details>' +
				'<div class="ap-field"><label>Мисии (icon | текст | награда | срок | тип: content_week/referrals_active/sales_month | цел) — по една на ред</label>' +
				'<textarea class="ap-textarea" id="gm-missions" rows="4">' +
				esc((c.missions || []).map(function (m) { return [m.icon, m.text, m.reward, m.deadline, m.target_type, m.target_n].join(' | '); }).join(String.fromCharCode(10))) +
				'</textarea></div>' +
				'<button class="ap-btn leaf" id="gm-save">Запази играта</button> ' +
				'<button class="ap-btn ghost" id="gm-preview">👁 Виж като партньор</button>' +
				'</div></div>';

			html += '<div class="ap-panel"><h2>🎬 Опашка за одобрение (' + queue.length + ')<span class="sp"></span></h2><div style="padding:0 18px 14px">';
			if (!queue.length) html += '<p class="ap-muted">Празно — всичко е прегледано.</p>';
			queue.forEach(function (q) {
				html += '<div style="display:flex;gap:10px;align-items:center;padding:10px 0;border-bottom:1px dashed var(--line);flex-wrap:wrap">' +
					'<div style="flex:1;min-width:200px"><strong>' + esc(q.partner) + '</strong><br><a href="' + esc(q.url) + '" target="_blank" rel="noopener" style="font-size:12.5px">' + esc(q.url.slice(0, 60)) + '…</a></div>' +
					'<button class="ap-btn leaf sm gm-mod" data-id="' + q.content_id + '" data-act="approve">Одобри</button>' +
					'<button class="ap-btn ghost sm gm-mod" data-id="' + q.content_id + '" data-act="reject">Откажи</button></div>';
			});
			html += '</div></div>';

			html += '<div class="ap-panel"><h2>🏆 Класации<span class="sp"></span>' +
				'<button class="ap-btn ghost sm" id="gm-draw">🎲 Тегли лотарията</button> ' +
				'<button class="ap-btn ghost sm" id="gm-final">🏁 Приключи сезона</button></h2>' +
				'<div style="padding:0 0 8px">';
			if (st.draw && st.draw.winner_name) html += '<p style="padding:0 18px;font-weight:900">🎲 Изтеглена: ' + esc(st.draw.winner_name) + ' · ' + esc(st.draw.at) + ' · ' + st.draw.pool + ' участници</p>';
			html += '<table class="ap-table"><thead><tr><th>Партньор</th><th class="num">Точки</th><th class="num">🔒</th><th class="num">🛒</th><th class="num">🎬</th><th class="num">🤝</th></tr></thead><tbody>';
			(st.rows || []).slice(0, 30).forEach(function (r) {
				html += '<tr><td><strong>' + esc(r.partner) + '</strong></td><td class="num"><b>' + r.active + '</b></td><td class="num">' + r.locked + '</td>' +
					'<td class="num">' + r.sales + '</td><td class="num">' + r.content + '</td><td class="num">' + r.refs + '</td></tr>';
			});
			html += '</tbody></table></div></div>';

			$title.textContent = 'Игра';
			$view.innerHTML = html;

			// --- Подредба на секциите (↑↓) ---
			var SEC_BG = { dream: '⛵ Мечтата (наградата)', podium: '🛒 Подиум продажби', prizes: '👑 Короните + лотарията', ways: '➍ Трите пътя', score: '🔢 Моите точки и ниво', rank: '📍 Ранг и дистанции', missions: '🎯 Мисии', content: '🎬 Предай съдържание', invite: '🤝 Покани приятел', diary: '🗒 Дневник', board: '🏆 Ранглиста', rules: '⚖️ Правила' };
			function layoutArr() {
				var v = document.getElementById('gm-layout-val').value.split(',').map(function (x) { return x.trim(); }).filter(Boolean);
				Object.keys(SEC_BG).forEach(function (k) { if (v.indexOf(k) < 0) v.push(k); });
				return v.filter(function (k) { return SEC_BG[k]; });
			}
			function renderLayout() {
				var arr = layoutArr();
				document.getElementById('gm-layout-val').value = arr.join(',');
				document.getElementById('gm-layout').innerHTML = arr.map(function (k, i) {
					return '<div style="display:flex;align-items:center;gap:8px;padding:7px 10px;background:var(--panel2,#fffaf6);border-radius:10px;margin-bottom:5px">' +
						'<span style="flex:1;font-weight:800;font-size:13.5px">' + (i + 1) + '. ' + SEC_BG[k] + '</span>' +
						'<button class="ap-btn ghost sm gm-mv" data-k="' + k + '" data-d="-1"' + (i === 0 ? ' disabled' : '') + '>↑</button>' +
						'<button class="ap-btn ghost sm gm-mv" data-k="' + k + '" data-d="1"' + (i === arr.length - 1 ? ' disabled' : '') + '>↓</button></div>';
				}).join('');
				document.querySelectorAll('.gm-mv').forEach(function (b) {
					b.addEventListener('click', function () {
						var arr2 = layoutArr(), i2 = arr2.indexOf(b.dataset.k), j2 = i2 + parseInt(b.dataset.d, 10);
						if (j2 < 0 || j2 >= arr2.length) return;
						arr2.splice(j2, 0, arr2.splice(i2, 1)[0]);
						document.getElementById('gm-layout-val').value = arr2.join(',');
						renderLayout();
					});
				});
			}
			renderLayout();

			function collectConfig() {
				var missions = document.getElementById('gm-missions').value.split(String.fromCharCode(10)).map(function (line) {
					var pp = line.split('|').map(function (x) { return x.trim(); });
					return pp[1] ? { icon: pp[0] || '🎯', text: pp[1], reward: pp[2] || '', deadline: pp[3] || '', target_type: pp[4] || '', target_n: parseInt(pp[5], 10) || 1 } : null;
				}).filter(Boolean);
				var g = function (id) { return document.getElementById(id).value; };
				return {
					enabled: document.getElementById('gm-enabled').checked ? 1 : 0,
					from: g('gm-from'), to: g('gm-to'), grace_days: g('gm-grace'), min_order: g('gm-minorder'),
					pts_sale: g('gm-ptss'), pts_content: g('gm-ptsc'), pts_referral: g('gm-ptsr'), content_cap: g('gm-cap'),
					levels: g('gm-levels'), layout: g('gm-layout-val'),
					hero_kick: g('gm-herok'), hero_title: g('gm-herot'), hero_sub: g('gm-heros'),
					hero_chips: g('gm-chips'), win_line: g('gm-win'),
					prize_p1: g('gm-p1'), prize_p2: g('gm-p2'), prize_p3: g('gm-p3'),
					prize_content: g('gm-pc'), prize_content_t: g('gm-pct'), prize_content_d: g('gm-pcd'),
					prize_ref: g('gm-pr'), prize_ref_t: g('gm-prt'), prize_ref_d: g('gm-prd'),
					prize_lucky: g('gm-pl'), prize_lucky_t: g('gm-plt'), prize_lucky_d: g('gm-pld'),
					way1: g('gm-w1'), way1_fine: g('gm-w1f'), way2: g('gm-w2'), way2_fine: g('gm-w2f'),
					way3: g('gm-w3'), way3_fine: g('gm-w3f'), rules: g('gm-rules'),
					empty_title: g('gm-et'), empty_sub: g('gm-es'), missions: missions,
					streak_weeks: g('gm-stw'), streak_bonus: g('gm-stb'), streak_txt: g('gm-sttxt'),
					overtake_notify: document.getElementById('gm-ovt').checked ? 1 : 0,
					overtake_subj: g('gm-ovts'), overtake_body: g('gm-ovtb'),
					digest_enabled: document.getElementById('gm-dig').checked ? 1 : 0,
					digest_subj: g('gm-digs'),
					share_enabled: document.getElementById('gm-shr').checked ? 1 : 0,
					perks: g('gm-perks')
				};
			}

			document.getElementById('gm-preview').addEventListener('click', function () {
				var pv = document.getElementById('gm-preview');
				pv.disabled = true; pv.textContent = 'Строя прегледа…';
				api('game/preview', { method: 'POST', body: collectConfig() }).then(function (r) {
					pv.disabled = false; pv.textContent = '👁 Виж като партньор';
					var m = document.createElement('div');
					m.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(61,43,37,.55);display:flex;align-items:center;justify-content:center;padding:18px';
					m.innerHTML = '<div style="background:#fff;border-radius:18px;overflow:hidden;width:min(430px,96vw);height:min(92vh,860px);display:flex;flex-direction:column">' +
						'<div style="display:flex;align-items:center;gap:8px;padding:10px 14px;font:900 13.5px Montserrat,sans-serif">📱 Как го вижда ' + esc(r.as) + '<span style="flex:1"></span><button id="gm-pv-x" class="ap-btn ghost sm">Затвори</button></div>' +
						'<iframe style="flex:1;border:0;width:100%" sandbox="allow-same-origin"></iframe></div>';
					document.body.appendChild(m);
					m.querySelector('iframe').srcdoc = r.html;
					m.querySelector('#gm-pv-x').addEventListener('click', function () { m.remove(); });
					m.addEventListener('click', function (ev) { if (ev.target === m) m.remove(); });
				}).catch(function (e) { pv.disabled = false; pv.textContent = '👁 Виж като партньор'; toast(e.message, true); });
			});

			document.getElementById('gm-save').addEventListener('click', function () {
				api('game/config', { method: 'POST', body: collectConfig() }).then(function () { toast('Играта е запазена — порталът я показва веднага'); viewGame(); })
				.catch(function (e) { toast(e.message, true); });
			});

			$view.querySelectorAll('.gm-mod').forEach(function (b) {
				b.addEventListener('click', function () {
					var note = b.dataset.act === 'reject' ? (prompt('Причина за отказа (вижда я партньорът):') || '') : '';
					api('game/content/' + b.dataset.id, { method: 'POST', body: { action: b.dataset.act, note: note } })
						.then(function () { toast(b.dataset.act === 'approve' ? 'Одобрено — точките са начислени' : 'Отказано'); viewGame(); })
						.catch(function (e) { toast(e.message, true); });
				});
			});

			document.getElementById('gm-final').addEventListener('click', function () {
				if (!confirm('ПРИКЛЮЧВАШ сезона: класацията се замразява и обявява в портала, играта се изключва, победителите получават имейл. Необратимо е. Продължаваш?')) return;
				api('game/finalize', { method: 'POST' }).then(function (r) {
					toast('🏁 Сезонът е приключен и обявен: ' + (r.final && r.final.season || ''));
					viewGame();
				}).catch(function (e) { toast(e.message, true); });
			});
			document.getElementById('gm-draw').addEventListener('click', function () {
				if (!confirm('Теглиш лотарията между всички играещи с точки. Действието е еднократно и се записва. Продължаваш?')) return;
				api('game/draw', { method: 'POST' })
					.then(function (r) { toast('🎲 Печели: ' + r.draw.winner_name + ' (от ' + r.draw.pool + ' участници)'); viewGame(); })
					.catch(function (e) { toast(e.message, true); });
			});
		}).catch(function (e) { $title.textContent = 'Игра'; $view.innerHTML = '<div class="ap-panel"><p style="padding:18px">' + esc(e.message) + '</p></div>'; });
	}

	/* ---------------- router ---------------- */

	function route() {
		var h = (location.hash || '#/dashboard').slice(2);
		var seg = h.split('?')[0].split('/');
		var qs = {};
		(h.split('?')[1] || '').split('&').forEach(function (kv) {
			if (!kv) return; var p = kv.split('='); qs[decodeURIComponent(p[0])] = decodeURIComponent(p[1] || '');
		});

		document.querySelectorAll('#ap-nav a').forEach(function (a) {
			a.classList.toggle('active', a.dataset.r === seg[0]);
		});

		if (seg[0] === 'partners' && seg[1]) return viewPartner(parseInt(seg[1], 10));
		if (seg[0] === 'partners')            return viewPartners(qs);
		if (seg[0] === 'commissions')         return viewCommissions(qs);
		if (seg[0] === 'payouts' && seg[1])   return viewPayout(parseInt(seg[1], 10));
		if (seg[0] === 'payouts')             return viewPayouts();
		if (seg[0] === 'diagnostics')         return viewDiagnostics();
		if (seg[0] === 'marketing')           return viewMarketing();
		if (seg[0] === 'proof')               return viewProof();
		if (seg[0] === 'import')              return viewImport();
		if (seg[0] === 'game')                return viewGame();
		if (seg[0] === 'ranking')             return viewRanking();
		return viewDashboard();
	}
	window.addEventListener('hashchange', route);

	function viewError(e) {
		try { console.error('[Proof Partner]', e); } catch (x) {}
		$view.innerHTML = '<div class="ap-panel" style="border:2px solid var(--bad)"><div style="padding:18px"><b style="color:var(--bad)">Екранът не се зареди</b><p style="margin:8px 0 12px">' + esc(e && e.message ? e.message : String(e)) + '</p><button class="ap-btn sm" onclick="location.reload()">Презареди</button> <a class="ap-btn sm ghost" href="#/dashboard">Табло</a></div></div>';
		window.scrollTo(0, 0);
	}
	/* Скелет вместо „Зареждане…": заема мястото на панелите, за да не подскача екранът при пристигане на данните. */
	function skeleton(kind) {
		if (kind === 'partner') return '<div class="ap-skel"><div class="sk h1"></div><div class="sk-row"><div class="sk card"></div><div class="sk card"></div><div class="sk card"></div><div class="sk card"></div><div class="sk card"></div></div><div class="sk bar"></div><div class="sk panel"></div><div class="sk panel short"></div></div>';
		return '<div class="ap-skel"><div class="sk-row"><div class="sk card"></div><div class="sk card"></div><div class="sk card"></div><div class="sk card"></div></div><div class="sk panel"></div><div class="sk panel short"></div></div>';
	}
	function loading(title, kind) {
		$title.textContent = title;
		$view.innerHTML = skeleton(kind);
	}

	/* ---------------- Dashboard ---------------- */

	function viewDashboard() {
		loading('Табло');
		api('overview').then(function (d) {
			var trend = '';
			if (typeof d.month_prev === 'number' && d.month_prev > 0) {
				var pct = Math.round(100 * (d.month_now - d.month_prev) / d.month_prev);
				trend = ' <span style="font-size:13px;color:' + (pct >= 0 ? 'var(--good)' : 'var(--bad)') + '">' + (pct >= 0 ? '▲' : '▼') + ' ' + Math.abs(pct) + '% срещу мин. месец</span>';
			}
			var payable = d.payable.map(function (r) { return money(r.total, r.currency); }).join(' + ') || money(0);
			var pending = d.pending.map(function (r) { return money(r.total, r.currency); }).join(' + ') || money(0);

			var html = '';


			html += '<div class="ap-slips">' +
				'<div class="ap-slip leaf"><div class="lbl">Дължимо</div><div class="val">' + payable + '</div><div class="sub">готово за изплащане</div></div>' +
				'<div class="ap-slip amber"><div class="lbl">Изчакващи</div><div class="val">' + pending + '</div><div class="sub">в период на задържане</div></div>' +
				'<div class="ap-slip"><div class="lbl">Този месец</div><div class="val">' + money(d.month.total) + '</div><div class="sub">' + (trend || 'комисиони, вкл. изчакващи') + '</div><div class="sub">' + d.month.count + ' комисиони</div></div>' +
				'<div class="ap-slip"><div class="lbl">Активни партньори</div><div class="val">' + d.partners_active + '</div><div class="sub">генерират комисиони</div></div>' +
			'</div>';

			if (d.alerts && d.alerts.length) {
				var arev = d.alerts.slice().reverse();
				var arow = function (a) {
					return '<li><strong>' + dt(a.time) + '</strong> — ' + esc(a.message) +
						' <button class="ap-alert-x" data-atime="' + esc(a.time) + '" data-amsg="' + esc(a.message) + '" title="Затвори сигнала">×</button></li>';
				};
				html += '<div class="ap-alert"><h3>Сигнали (' + arev.length + ')</h3><ul>' + arev.slice(0, 3).map(arow).join('') + '</ul>' +
					(arev.length > 3 ? '<details><summary style="cursor:pointer;font-weight:800;padding:4px 0">още ' + (arev.length - 3) + '…</summary><ul>' + arev.slice(3).map(arow).join('') + '</ul></details>' : '') +
					'<button class="ap-btn ghost sm" id="ap-clear-alerts">Изчисти всички</button></div>';
			}


			html += '<div class="ap-panel"><h2>Последни събития<span class="sp"></span><button class="ap-btn ghost sm" id="ap-feed-refresh" title="Презареди">↻</button></h2><div id="ap-feed" style="padding:0 18px 14px"><p class="ap-muted" style="padding-top:6px">Зареждане…</p></div></div>';

			html += '<div class="ap-panel"><h2>Топ партньори — този месец<span class="sp"></span></h2><table class="ap-table"><thead><tr><th>Партньор</th><th style="text-align:right">Комисиони</th><th style="text-align:right">Сума</th></tr></thead><tbody>';
			if (!d.top.length) html += '<tr><td colspan="3" class="empty">Няма комисиони този месец.</td></tr>';
			d.top.forEach(function (t) {
				html += '<tr class="click" data-go="#/partners/' + t.partner_id + '"><td><strong>' + esc(t.partner) + '</strong></td><td class="num">' + t.count + '</td><td class="num pos">' + money(t.total) + '</td></tr>';
			});
			html += '</tbody></table></div>';


			html += '<div class="ap-panel"><h2>Последни движения<span class="sp"></span></h2>' + ledgerTable(d.recent, { partner: true, actions: false }) + '</div>';

			$title.textContent = 'Табло';
			$view.innerHTML = html;

			$view.querySelectorAll('.ap-alert-x').forEach(function (x) {
				x.addEventListener('click', function () {
					api('alerts/clear', { method: 'POST', body: { time: x.dataset.atime, message: x.dataset.amsg } })
						.then(function () { x.closest('li').remove(); });
				});
			});
			loadFeed();
			var fr = document.getElementById('ap-feed-refresh');
			if (fr) fr.addEventListener('click', function () { loadFeed(); });

			var clr = document.getElementById('ap-clear-alerts');
			if (clr) clr.addEventListener('click', function () {
				var panel = clr.closest('.ap-alert');
				if (panel) panel.remove(); // веднага, без да чакаме мрежата
				api('alerts/clear', { method: 'POST' }).then(function () { toast('Сигналите са изчистени'); })
					.catch(function () { toast('Мрежова грешка — сигналите може да се появят пак', true); });
			});
			bindRowLinks();
			bindOrderInfo();
		}).catch(function (e) { viewError(e); });
	}

	function initRecon() {
		var body = document.getElementById('ap-recon-body');
		var runBtn = document.getElementById('ap-recon-run');
		if (!body) return;

		function render(rep) {
			var m = rep.missed || [], o = rep.orphans || [], pr = rep.premature || [];
			var law = (rep.won_statuses || []).join(', ');
			var h = '<p class="ap-muted" style="margin:10px 0 12px">Последна проверка: ' + (rep.ran_at ? dt(rep.ran_at) : 'никога — нощната проверка още не е минала') + ' · обхват ' + (rep.lookback || 30) + ' дни</p>';
			if (rep && rep.error) h += '<p style="font-weight:900;color:var(--bad,#d64545)">⚠️ Сверката гърми при строене: ' + esc(rep.error) + ' — прати този ред.</p>';

			if (law) {
				h += '<p class="ap-muted" style="font-size:12.5px">Закон: „За изплащане" изисква статус <strong>' + esc(law) + '</strong> · <a href="#/proof">смени статусите →</a></p>';
			}
			h += '<p style="font-weight:800;color:var(--good)">🤖 Самоуправление: включено — пропуснати, преждевременни и осиротели се поправят автоматично при всяко отваряне и на всеки час.</p>';
			(rep.autofix_log || []).slice().reverse().slice(0, 8).forEach(function (a) {
				h += '<p class="ap-muted" style="font-size:12.5px;margin:2px 0">· ' + dt(a.t) + ' — ' + esc(a.msg) + '</p>';
			});
			if (pr.length) {
				h += '<p style="font-weight:800;color:var(--bad)">Останали СЛЕД авто-поправката — „За изплащане" без Delivered (' + pr.length + ') <button class="ap-btn sm" id="ap-fix-premature">Върни ги в изчакващи</button></p><ul style="margin:6px 0 14px 18px;font-size:13px">';
				pr.slice(0, 15).forEach(function (x) {
					h += '<li><a href="' + esc(x.order_url) + '" target="_blank" rel="noopener">#' + x.order_id + '</a> · ' + esc(x.partner) + ' · ' + money(x.amount, x.currency) + ' · ' + esc(x.why) + '</li>';
				});
				if (pr.length > 15) h += '<li class="ap-muted">… и още ' + (pr.length - 15) + '</li>';
				h += '</ul>';
			}
			if (!m.length && !o.length && !pr.length) {
				h += '<p style="font-weight:800;color:var(--good)">✓ Книгата е сверена — нищо за поправяне.</p>';
			}
			if (m.length) {
				h += '<p style="font-weight:800">Пропуснати комисиони (' + m.length + ') <button class="ap-btn sm" id="ap-fix-missed">Създай ги</button></p><ul style="margin:6px 0 14px 18px;font-size:13px">';
				m.slice(0, 15).forEach(function (x) {
					h += '<li><a href="' + esc(x.order_url) + '" target="_blank" rel="noopener">#' + x.order_id + '</a> · <code class="ap-code">' + esc((x.coupon_code || '').toUpperCase()) + '</code> · ' + esc(x.partner) + ' · ' + esc(x.date) + '</li>';
				});
				if (m.length > 15) h += '<li class="ap-muted">… и още ' + (m.length - 15) + '</li>';
				h += '</ul>';
			}
			if (o.length) {
				h += '<p style="font-weight:800">Осиротели редове (' + o.length + ') <button class="ap-btn sm danger" id="ap-fix-orphans">Сторнирай ги</button></p><ul style="margin:6px 0 6px 18px;font-size:13px">';
				o.slice(0, 15).forEach(function (x) {
					h += '<li><a href="' + esc(x.order_url) + '" target="_blank" rel="noopener">#' + x.order_id + '</a> · ' + esc(x.partner) + ' · ' + money(x.amount, x.currency) + ' · ' + esc(x.why) + '</li>';
				});
				if (o.length > 15) h += '<li class="ap-muted">… и още ' + (o.length - 15) + '</li>';
				h += '</ul>';
			}
			var target = document.getElementById('ap-recon-body') || body;
			try { target.innerHTML = h; }
			catch (err) { target.innerHTML = '<p style="color:var(--bad);font-weight:800">Грешка при показване: ' + esc(err.message) + ' — данните са наред, пусни „Провери сега".</p>'; }

			var fm = document.getElementById('ap-fix-missed');
			if (fm) fm.addEventListener('click', function () {
				fm.disabled = true;
				api('reconciliation/fix', { method: 'POST', body: { what: 'missed' } }).then(function (r) {
					toast('Създадени: ' + r.fixed + ' комисиони'); render(r.report); refreshOwed();
				}).catch(function (e) {
					toast(e.message, true);
					api('reconciliation').then(render).catch(function () { fm.disabled = false; });
				});
			});
			var ft = document.getElementById('ap-fix-trigger');
			if (ft) ft.addEventListener('click', function () {
				ft.disabled = true;
				api('trigger', { method: 'POST', body: { status: 'delivered' } }).then(function () {
					toast('Законът е delivered. Пускам нова проверка…');
					return api('reconciliation/run', { method: 'POST' });
				}).then(function (r) { render(r.report || r); refreshOwed(); })
					.catch(function (e) {
						toast(e.message, true);
						api('reconciliation').then(render).catch(function () { ft.disabled = false; });
					});
			});
			var fp = document.getElementById('ap-fix-premature');
			if (fp) fp.addEventListener('click', function () {
				fp.disabled = true;
				api('reconciliation/fix', { method: 'POST', body: { what: 'premature' } }).then(function (r) {
					toast('Върнати в изчакващи: ' + r.fixed + ' реда'); render(r.report); refreshOwed();
				}).catch(function (e) {
					toast(e.message, true);
					api('reconciliation').then(render).catch(function () { fp.disabled = false; });
				});
			});
			var fo = document.getElementById('ap-fix-orphans');
			if (fo) fo.addEventListener('click', function () {
				if (!confirm('Осиротелите редове ще бъдат сторнирани (по същия път като живо връщане). Продължаваме?')) return;
				fo.disabled = true;
				api('reconciliation/fix', { method: 'POST', body: { what: 'orphans' } }).then(function (r) {
					toast('Сторнирани: ' + r.fixed + ' реда'); render(r.report); refreshOwed();
				}).catch(function (e) {
					toast(e.message, true);
					api('reconciliation').then(render).catch(function () { fo.disabled = false; });
				});
			});
		}

		api('reconciliation').then(render).catch(function () { body.innerHTML = '<p class="ap-muted">Грешка при зареждане.</p>'; });

		if (runBtn) runBtn.addEventListener('click', function () {
			runBtn.disabled = true; runBtn.textContent = 'Проверява…';
			api('reconciliation/run', { method: 'POST' }).then(function (rep) {
				runBtn.disabled = false; runBtn.textContent = 'Провери сега';
				toast('Проверката приключи'); render(rep);
			}).catch(function (e) { runBtn.disabled = false; runBtn.textContent = 'Провери сега'; toast(e.message, true); });
		});
	}

	/* ---------------- Partners ---------------- */

	function viewPartners(qs) {
		loading('Партньори');
		var status = qs.status || '';
		api('partners' + (status ? '?status=' + encodeURIComponent(status) : '')).then(function (rows) {
			var html = '<div class="ap-panel"><div class="ap-filters">' +
				'<input class="ap-input" id="ap-psearch" type="search" placeholder="Търсене по име, email, код…" style="min-width:240px">' +
				'<span id="ap-bulkbar" class="ap-bulkbar" style="visibility:hidden"><select class="ap-input" id="ap-bulkact" style="width:auto;padding:7px 10px"><option value="">Bulk действие…</option><option value="rate">Смени ставка</option><option value="pause">Пауза</option><option value="activate">Активирай</option><option value="emails">Копирай emails</option></select> <button class="ap-btn sm" id="ap-bulkgo">Приложи</button></span>' +
				'<select class="ap-select" id="ap-pstatus">' +
					'<option value="">Всички статуси</option>' +
					'<option value="applicant"' + (status === 'applicant' ? ' selected' : '') + '>Кандидати</option>' +
					'<option value="active"' + (status === 'active' ? ' selected' : '') + '>Активни</option>' +
					'<option value="paused"' + (status === 'paused' ? ' selected' : '') + '>Пауза</option>' +
					'<option value="archived"' + (status === 'archived' ? ' selected' : '') + '>Архив</option>' +
				'</select>' +
				'<span style="flex:1"></span>' +
				'<button class="ap-btn leaf" id="ap-add-partner">+ Нов партньор</button>' +
			'</div>' +
			'<table class="ap-table"><thead><tr><th style="width:26px"><input type="checkbox" id="ap-pall" title="Избери страницата"></th><th>Партньор</th><th>Статус</th><th style="text-align:right">Ставка</th><th>Кодове</th><th style="text-align:right">Изчакващи</th><th style="text-align:right">Дължимо</th><th style="text-align:right">Изплатени</th></tr></thead><tbody id="ap-plist"></tbody></table>' +
			'<div id="ap-ppager" style="display:flex;gap:12px;align-items:center;padding:10px 18px;border-top:2px solid var(--line);font-weight:800"></div></div>';

			$title.textContent = 'Партньори';
			$view.innerHTML = html;

			var all = rows;
			var ppg = 1, PP = 20, lastFilter = '';
			function renderList(filter) {
				lastFilter = filter || '';
				var f = lastFilter.toLowerCase();
				var matched = all.filter(function (p) {
					if (!f) return true;
					var hay = (p.display_name + ' ' + p.email + ' ' + p.coupons.map(function (c) { return c.coupon_code; }).join(' ')).toLowerCase();
					return hay.indexOf(f) !== -1;
				});
				var pages = Math.max(1, Math.ceil(matched.length / PP));
				if (ppg > pages) ppg = pages;
				var body = '';
				matched.slice((ppg - 1) * PP, ppg * PP).forEach(function (p) {
					var codes = p.coupons.map(function (c) {
						return c.active ? '<code class="ap-code">' + esc(c.coupon_code.toUpperCase()) + '</code>' : '<s><code class="ap-code">' + esc(c.coupon_code.toUpperCase()) + '</code></s>';
					}).join(' ') || '<span class="ap-muted">—</span>';
					body += '<tr class="click" data-go="#/partners/' + p.partner_id + '">' +
						'<td onclick="event.stopPropagation()"><input type="checkbox" class="ap-pcheck" value="' + p.partner_id + '" data-email="' + esc(p.email || '') + '"></td>' +
						'<td><strong>' + esc(p.display_name) + '</strong><br><span class="ap-muted">' + esc(p.email) + '</span></td>' +
						'<td>' + chip(p.status, PSTATUS_BG[p.status]) + '</td>' +
						'<td class="num">' + (p.pay_model === 'fixed' ? chip('fixed', 'Фактура') : rateFmt(p.rate) + '%') + '</td>' +
						'<td>' + codes + '</td>' +
						'<td class="num">' + money(p.totals.pending) + '</td>' +
						'<td class="num pos">' + money(p.totals.approved) + '</td>' +
						'<td class="num">' + money(p.totals.paid) + '</td>' +
					'</tr>';
				});
				document.getElementById('ap-plist').innerHTML = body || '<tr><td colspan="8" class="empty">Няма партньори по тези критерии. Добавете първия с „+ Нов партньор“.</td></tr>';
				var pg = document.getElementById('ap-ppager');
				pg.innerHTML = '<span>' + matched.length + ' партньори · страница ' + ppg + '/' + pages + '</span><span style="flex:1"></span>' +
					(ppg > 1 ? '<button class="ap-btn ghost sm" id="ap-pprev">‹ Предишни 20</button>' : '') +
					(ppg < pages ? '<button class="ap-btn ghost sm" id="ap-pnext">Следващи 20 ›</button>' : '');
				var b1 = document.getElementById('ap-pprev'), b2 = document.getElementById('ap-pnext');
				if (b1) b1.addEventListener('click', function () { ppg--; renderList(lastFilter); });
				if (b2) b2.addEventListener('click', function () { ppg++; renderList(lastFilter); });
				bindRowLinks();
				bindOrderInfo();
			}
			renderList('');

			document.getElementById('ap-psearch').addEventListener('input', function () { ppg = 1; renderList(this.value); });

			function selectedIds() { return Array.prototype.map.call($view.querySelectorAll('.ap-pcheck:checked'), function (c) { return parseInt(c.value, 10); }); }
			$view.addEventListener('change', function (e) {
				if (e.target.id === 'ap-pall') {
					$view.querySelectorAll('.ap-pcheck').forEach(function (c) { c.checked = e.target.checked; });
				}
				if (e.target.classList.contains('ap-pcheck') || e.target.id === 'ap-pall') {
					document.getElementById('ap-bulkbar').style.visibility = selectedIds().length ? 'visible' : 'hidden';
				}
			});
			document.getElementById('ap-bulkgo').addEventListener('click', function () {
				var act = document.getElementById('ap-bulkact').value, ids = selectedIds();
				if (!act || !ids.length) return;
				if (act === 'emails') {
					var emails = Array.prototype.map.call($view.querySelectorAll('.ap-pcheck:checked'), function (c) { return c.dataset.email; }).filter(Boolean).join(', ');
					navigator.clipboard.writeText(emails).then(function () { toast(ids.length + ' emails копирани'); });
					return;
				}
				var body = { ids: ids, action: act };
				if (act === 'rate') {
					var r = prompt('Нова ставка (%) за ' + ids.length + ' партньори:', '10');
					if (!r) return;
					body.rate = r;
				}
				if (!confirm(act + ' върху ' + ids.length + ' партньори — сигурен ли си?')) return;
				api('partners/bulk', { method: 'POST', body: body }).then(function (r) {
					toast('Готово: ' + r.done + ' партньори'); route();
				}).catch(function (e) { toast(e.message, true); });
			});
			document.getElementById('ap-pstatus').addEventListener('change', function () {
				location.hash = '#/partners' + (this.value ? '?status=' + this.value : '');
			});
			document.getElementById('ap-add-partner').addEventListener('click', addPartnerModal);
		}).catch(function (e) { viewError(e); });
	}

	/* ---------------- Служебни пратки: стока, дадена на партньора (1.7.0) ---------------- */
	function shipPanel(id) {
		var host = document.getElementById('app-ship'); if (!host) return;
		api('proof/shipments/' + id).then(function (d) {
			var T = d.totals || {}, list = d.shipments || [];
			function chipS(s) { return s.counted ? '<span class="ap-chip paid">✓ получена · ' + esc(s.order_status) + '</span>' : (['cancelled', 'refunded', 'failed'].indexOf(s.order_status) >= 0 ? '<span class="ap-chip reversed">✕ ' + esc(s.order_status) + '</span>' : '<span class="ap-chip pending">⏳ ' + esc(s.order_status) + '</span>'); }
			var rows = list.map(function (s) {
				return '<tr><td style="white-space:nowrap"><button class="ap-oi-btn" data-oi="' + s.order_id + '" title="Инфо за поръчката">▸</button> <a href="' + esc(s.edit_url) + '" target="_blank" rel="noopener">#' + s.order_id + '</a></td><td>' + (s.order_date ? esc(String(s.order_date).slice(0, 10)) + '' : '') + '</td><td>' + chipS(s) + '</td>' +
					'<td><span class="ap-desc" title="' + esc(s.items.map(function (i) { return i.name + ' ×' + i.qty; }).join(', ')) + '">' + esc(s.items.map(function (i) { return i.name + ' ×' + i.qty; }).join(', ')) + '</span></td>' +
					'<td class="num">' + s.qty + '</td><td class="num">' + money(s.value) + '</td><td>' + esc(s.note || '') + '</td><td><button type="button" class="ap-btn sm danger" data-shipdel="' + s.shipment_id + '">✕</button></td></tr>';
			}).join('');
			host.innerHTML = '<div class="ap-panel"><h2>Служебни пратки — стока към партньора<span class="sp"></span><span class="ap-muted" style="font-weight:600">получени ' + (T.delivered || 0) + ' от ' + (T.shipments || 0) + ' · ' + (T.qty || 0) + ' бр. · ' + money(T.value || 0) + ' по каталог</span></h2><div style="padding:0 18px 16px">' +
				'<table class="ap-table"><thead><tr><th>Поръчка</th><th>Дата</th><th>Статус</th><th>Продукти</th><th style="text-align:right">Бр.</th><th style="text-align:right">Стойност</th><th>Бележка</th><th></th></tr></thead><tbody>' + (rows || '<tr><td colspan="8" class="empty">Няма записани пратки.</td></tr>') + '</tbody></table>' +
				'<div class="ap-add"><p class="ap-sub">Нова пратка</p><div class="ap-inline"><div class="ap-field w-s"><label>№ на поръчката</label><input class="ap-input" id="ship-no" placeholder="напр. 23489" inputmode="numeric"></div><div class="ap-field"><label>Бележка (по избор)</label><input class="ap-input" id="ship-note" placeholder="мостри за октомври"></div><button type="button" class="ap-btn" id="ship-prev">Зареди</button></div>' +
				'<div id="ship-preview"></div>' +
				'<p class="ap-hint" style="margin-top:12px">Стойност по каталожни цени × бройки (служебните поръчки са обикновено на 0). Брои се за „получена" само при доставена/завършена — следи се автоматично. Партньорът я вижда в портала (таб „Продукти").</p></div>' +
				'</div></div>';
			function preview() {
				var no = host.querySelector('#ship-no').value.replace(/\D/g, ''); if (!no) { toast('Въведи номер на поръчка.', true); return; }
				var pv = host.querySelector('#ship-preview'); pv.style.marginTop = '12px'; pv.innerHTML = '<p class="ap-muted">Зареждам поръчка #' + esc(no) + '…</p>';
				api('proof/shipments/' + id + '/preview?order=' + encodeURIComponent(no)).then(function (s) {
					var warn = '';
					if (s.already && s.already !== id) warn += '<p style="color:var(--bad);font-weight:700">Вече е записана като пратка към партньор #' + s.already + '.</p>';
					if (s.has_commission) warn += '<p style="color:var(--bad);font-weight:700">⚠ Тази поръчка носи комисиона (клиентска, с партньорски код) — сигурен ли си, че е служебна пратка?</p>';
					if (s.paid_total > 0) warn += '<p style="color:#a86b09;font-weight:700">Поръчката е платена (' + money(s.paid_total) + ') — служебните обикновено са на 0.</p>';
					pv.innerHTML = '<div style="background:var(--blush);border-radius:12px;padding:12px 14px">' + warn +
						'<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center"><b>#' + s.order_id + '</b>' + chipS(s) + '<span>' + esc(s.recipient || '') + (s.city ? ', ' + esc(s.city) : '') + '</span><span class="ap-muted">' + esc(String(s.order_date || '').slice(0, 10)) + '</span></div>' +
						'<table class="ap-table" style="margin-top:8px"><thead><tr><th>Продукт</th><th>SKU</th><th style="text-align:right">Бр.</th><th style="text-align:right">Каталожна цена</th><th style="text-align:right">Стойност</th></tr></thead><tbody>' + s.items.map(function (i) { return '<tr><td>' + esc(i.name) + '</td><td class="ap-muted">' + esc(i.sku || '') + '</td><td class="num">' + i.qty + '</td><td class="num">' + money(i.unit_regular) + '</td><td class="num">' + money(i.line_regular) + '</td></tr>'; }).join('') + '</tbody></table>' +
						'<div class="ap-actions" style="justify-content:flex-start"><button type="button" class="ap-btn" id="ship-add"' + (s.already && s.already !== id ? ' disabled' : '') + '>' + (s.already === id ? 'Обнови пратката' : 'Запиши като пратка към партньора') + ' — ' + s.qty + ' бр. · ' + money(s.value) + '</button></div></div>';
					pv.querySelector('#ship-add').addEventListener('click', function () {
						var b = this; b.disabled = true;
						api('proof/shipments/' + id, { method: 'POST', body: { order_id: no, note: host.querySelector('#ship-note').value } }).then(function () {
							notice('Пратка #' + no + ' е записана', s.qty + ' бр. на стойност ' + money(s.value) + ' по каталог.\n' + (s.counted ? 'Статусът е ' + s.order_status + ' — брои се като получена.' : 'Статусът е ' + s.order_status + ' — ще се брои за получена, когато стане доставена/завършена (следи се автоматично).'));
							shipPanel(id);
						}).catch(function (e) { b.disabled = false; toast(e.message, true); });
					});
				}).catch(function (e) { pv.innerHTML = '<p style="color:var(--bad);font-weight:700">' + esc(e.message) + '</p>'; });
			}
			host.querySelector('#ship-prev').addEventListener('click', preview);
			host.querySelector('#ship-no').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); preview(); } });
			host.addEventListener('click', function (e) {
				var del = e.target.closest('[data-shipdel]'); if (!del) return;
				if (!confirm('Махаш пратката от отчета на партньора? (поръчката остава)')) return;
				api('proof/shipments/' + id + '/' + del.dataset.shipdel, { method: 'DELETE' }).then(function () { shipPanel(id); }).catch(function (er) { toast(er.message, true); });
			});
		}).catch(function (e) { host.innerHTML = '<div class="ap-panel"><p style="padding:16px;color:var(--bad)">' + esc(e.message) + '</p></div>'; });
	}

	/* ---------------- Фиксиран договор: фактури + възвръщаемост (1.6.0) ---------------- */
	function roiPanel(id) {
		var host = document.getElementById('app-roi'); if (!host) return;
		api('proof/roi/' + id).then(function (d) {
			var T = d.totals, inv = d.invoices || [];
			function verdict() {
				if (!T.cost) return '<span class="ap-muted">Няма фактури — добави поне една, за да има сравнение.</span>';
				if (!T.revenue) return '<b style="color:var(--bad)">Няма продажби от старта (' + esc(d.tracked_from || '—') + ') срещу ' + money(T.cost) + ' разход.</b>';
				var cmp = T.would > 0 ? ' На комисиона ' + d.rate + '% същите продажби биха стрували ' + money(T.would) + (T.would < T.cost ? ' — комисионата е ПО-ЕВТИНА с ' + money(T.cost - T.would) : ' — фиксираното е по-евтино с ' + money(T.would - T.cost)) + '.' : '';
				return '<b style="color:' + (T.cost_pct <= 15 ? 'var(--good)' : (T.cost_pct <= 30 ? '#a86b09' : 'var(--bad)')) + '">Разходът е ' + T.cost_pct + '% от оборота (ROI ' + T.roi + '×).</b>' + esc(cmp);
			}
			function cmpBox(c, title) {
				if (!c) return '';
				var worse = c.invoice > c.commission, none = !c.invoice && !c.commission;
				return '<div class="ap-slip" style="flex:1;min-width:260px;' + (none ? '' : 'border:2px solid ' + (worse ? 'var(--bad)' : 'var(--good)')) + '"><div class="lbl">' + esc(title) + ' · ' + esc(c.ym) + '</div>' +
					'<div style="font-size:13px;margin-top:6px;line-height:1.6">Продажби: <b>' + c.orders + '</b> поръчки · ' + money(c.revenue) + '<br>Комисиона би била (' + c.rate + '%): <b>' + money(c.commission) + '</b><br>Фактура: <b>' + money(c.invoice) + '</b>' +
					(none ? '' : '<br><b style="color:' + (worse ? 'var(--bad)' : 'var(--good)') + '">' + (worse ? 'Плащаме ' + money(c.diff) + ' повече, отколкото изкарва' + (c.commission > 0 ? ' (' + (Math.round(c.invoice / c.commission * 10) / 10) + '× комисионата)' : '') + '.' : 'Изкарва ' + money(-c.diff) + ' повече от фактурата — фиксираното е изгодно.') + '</b>') + '</div></div>';
			}
			var cmp = '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:0 0 14px">' + cmpBox(d.prev_month, 'Предходен месец') + cmpBox(d.this_month, 'Текущ месец (до момента)') + '</div>' +
				(d.portal_message ? '<div style="background:var(--blush);border-left:6px solid var(--coral);border-radius:10px;padding:10px 14px;margin:0 0 14px;font-size:13px"><b>Партньорът вижда в портала:</b><br>' + esc(d.portal_message) + '</div>' : (d.prev_month && d.prev_month.invoice > 0 ? '' : '<p class="ap-muted" style="margin:0 0 12px">Съобщението в портала се показва, когато има фактура за предходния месец (текстовете са в Маркетинг).</p>'));
			var monthRow = function (m) {
				var col = m.cost_pct === null ? '' : (m.cost_pct <= 15 ? 'var(--good)' : (m.cost_pct <= 30 ? '#a86b09' : 'var(--bad)'));
				return '<tr><td><b>' + esc(m.ym) + '</b></td><td class="num">' + m.orders + (m.hist_orders ? ' <span class="ap-muted" title="история преди старта">(+' + m.hist_orders + ')</span>' : '') + '</td><td class="num">' + money(m.revenue) + (m.hist_revenue ? ' <span class="ap-muted">(+' + money(m.hist_revenue) + ')</span>' : '') + '</td><td class="num">' + (m.cost ? money(m.cost) + ' <span class="ap-muted">' + m.invoices + ' ф.</span>' : '—') + '</td><td class="num">' + (m.goods_qty ? m.goods_qty + ' бр. · ' + money(m.goods_value) : '—') + '</td><td class="num" style="color:' + col + '">' + (m.cost_pct === null ? '—' : m.cost_pct + '%') + '</td><td class="num">' + (m.roi === null ? '—' : m.roi + '×') + '</td><td class="num">' + money(m.would_commission) + (m.cost ? ' <b style="color:' + (m.cost > m.would_commission ? 'var(--bad)' : 'var(--good)') + '">' + (m.cost > m.would_commission ? '−' : '+') + money(Math.abs(m.cost - m.would_commission)) + '</b>' : '') + '</td></tr>';
			};
			var invRows = inv.map(function (i) {
				return '<tr><td>' + esc(i.number || '—') + (i.file_url ? ' <a href="' + esc(i.file_url) + '" target="_blank" rel="noopener" title="' + esc(i.file_name) + '">📎</a>' : '') + '</td><td>' + esc(i.issued_at || '') + '</td><td>' + esc((i.period_from || '') + ' → ' + (i.period_to || '')) + '</td><td class="num">' + money(i.amount) + (i.vat_amount ? ' <span class="ap-muted">+ДДС ' + money(i.vat_amount) + '</span>' : '') + '</td><td>' + (i.paid ? '<span class="ap-chip paid">платена</span>' : '<button type="button" class="ap-btn sm ghost" data-invpaid="' + i.invoice_id + '">маркирай платена</button>') + '</td><td>' + esc(i.note || '') + '</td><td><button type="button" class="ap-btn sm danger" data-invdel="' + i.invoice_id + '">✕</button></td></tr>';
			}).join('');
			var today = new Date().toISOString().slice(0, 10), m0 = today.slice(0, 8) + '01';
			host.innerHTML =
				'<div class="ap-panel"><h2>Възвръщаемост — фиксиран договор<span class="sp"></span><span class="ap-muted" style="font-weight:600">продажбите с кодовете от ' + esc(d.tracked_from || '—') + ' срещу фактурите</span></h2><div style="padding:0 18px 16px">' +
				'<div class="ap-slips roi"><div class="ap-slip"><div class="lbl">Поръчки</div><div class="val">' + T.orders + '</div></div><div class="ap-slip"><div class="lbl">Оборот</div><div class="val">' + money(T.revenue) + '</div></div><div class="ap-slip amber"><div class="lbl">Фактурирано</div><div class="val">' + money(T.cost) + '</div></div><div class="ap-slip"><div class="lbl">Стока (пратки)</div><div class="val">' + (T.goods_qty || 0) + ' бр. · ' + money(T.goods_value || 0) + '</div></div><div class="ap-slip ' + (T.cost_pct === null ? '' : (T.cost_pct <= 15 ? 'leaf' : '')) + '"><div class="lbl">Разход от оборота</div><div class="val">' + (T.cost_pct === null ? '—' : T.cost_pct + '%') + '</div></div></div>' +
				'<p style="margin:0 0 12px">' + verdict() + '</p>' + cmp +
				'<div id="roi-months-host"></div>' +
				'<p class="ap-muted" style="margin-top:8px">Оборот = стойността на спечелените поръчки с кодовете на партньора (ДДС според настройката). Фактурата се брои в месеца на „период от". Стоката (служебни пратки) е информативна — по каталожни цени, не влиза в „Разход %". (+N) = история преди старта, не влиза в сметката. Праг: до 15% зелено, до 30% жълто.</p>' +
				'</div></div>' +
				'<div class="ap-panel"><h2>Фактури от партньора<span class="sp"></span></h2><div style="padding:0 18px 16px">' +
				'<table class="ap-table"><thead><tr><th>№</th><th>Дата</th><th>Период</th><th style="text-align:right">Сума (без ДДС)</th><th>Платена</th><th>Бележка</th><th></th></tr></thead><tbody>' + (invRows || '<tr><td colspan="7" class="empty">Няма качени фактури.</td></tr>') + '</tbody></table>' +
				'<div class="ap-add"><p class="ap-sub">Нова фактура</p><div class="ap-form">' +
				'<div class="ap-row3"><div class="ap-field"><label>№ на фактурата</label><input class="ap-input" id="inv-no" placeholder="0000000123"></div><div class="ap-field"><label>Дата</label><input class="ap-input" id="inv-date" type="date" value="' + today + '"></div><div class="ap-field"><label>Сума без ДДС (€)</label><input class="ap-input" id="inv-amt" type="number" step="0.01" min="0" placeholder="0.00"></div></div>' +
				'<div class="ap-row3"><div class="ap-field"><label>Период от</label><input class="ap-input" id="inv-from" type="date" value="' + m0 + '"></div><div class="ap-field"><label>Период до</label><input class="ap-input" id="inv-to" type="date" value="' + today + '"></div><div class="ap-field"><label>ДДС (€, по избор)</label><input class="ap-input" id="inv-vat" type="number" step="0.01" min="0" placeholder="0.00"></div></div>' +
				'<div class="ap-row3"><div class="ap-field"><label>Файл (PDF/снимка, по избор)</label><input class="ap-input" id="inv-file" type="file" accept=".pdf,image/*"></div><div class="ap-field" style="grid-column:span 2"><label>Бележка</label><input class="ap-input" id="inv-note" placeholder="напр. септември, по договор"></div></div>' +
				'</div><div class="ap-actions"><button type="button" class="ap-btn" id="inv-add">Добави фактурата</button><label class="ap-check"><input type="checkbox" id="inv-paid"> вече е платена</label><span class="sp"></span><span class="ap-hint">Фактурата влиза в месеца на „Период от".</span></div></div>' +
				'</div></div>';
			pagedTable(host.querySelector('#roi-months-host'), d.months || [], 12, function (sl) { return '<table class="ap-table"><thead><tr><th>Месец</th><th style="text-align:right">Поръчки</th><th style="text-align:right">Оборот</th><th style="text-align:right">Фактури</th><th style="text-align:right" title="служебни пратки, получени, по каталожни цени">Стока</th><th style="text-align:right">Разход %</th><th style="text-align:right">ROI</th><th style="text-align:right" title="какво би било на комисиона по ставката; разликата спрямо фактурата">Ако беше ' + d.rate + '% · разлика</th></tr></thead><tbody>' + (sl.map(monthRow).join('') || '<tr><td colspan="8" class="empty">Още няма нито продажби, нито фактури.</td></tr>') + '</tbody></table>'; });
			host.querySelector('#inv-add').addEventListener('click', function () {
				var b = this, amt = host.querySelector('#inv-amt').value;
				if (!amt || +amt <= 0) { toast('Сумата е задължителна.', true); host.querySelector('#inv-amt').focus(); return; }
				b.disabled = true;
				var fd = new FormData();
				fd.append('number', host.querySelector('#inv-no').value); fd.append('issued_at', host.querySelector('#inv-date').value);
				fd.append('period_from', host.querySelector('#inv-from').value); fd.append('period_to', host.querySelector('#inv-to').value);
				fd.append('amount', amt); fd.append('vat_amount', host.querySelector('#inv-vat').value || '0'); fd.append('note', host.querySelector('#inv-note').value);
				fd.append('paid', host.querySelector('#inv-paid').checked ? '1' : '0');
				var f = host.querySelector('#inv-file').files[0]; if (f) fd.append('file', f);
				fetch(BOOT.restUrl + 'proof/invoices/' + id, { method: 'POST', headers: { 'X-WP-Nonce': BOOT.nonce }, credentials: 'same-origin', body: fd })
					.then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || ('HTTP ' + r.status)); return j; }); })
					.then(function (j) { if (j.file_error) toast('Фактурата е записана, но файлът не: ' + j.file_error, true); else toast('Фактурата е добавена'); roiPanel(id); })
					.catch(function (e) { b.disabled = false; toast(e.message, true); });
			});
			host.addEventListener('click', function (e) {
				var del = e.target.closest('[data-invdel]'), pd = e.target.closest('[data-invpaid]');
				if (del) { if (!confirm('Изтриваш фактурата?')) return; api('proof/invoices/' + id + '/' + del.dataset.invdel, { method: 'DELETE' }).then(function () { roiPanel(id); }).catch(function (er) { toast(er.message, true); }); }
				if (pd) { api('proof/invoices/' + id + '/' + pd.dataset.invpaid, { method: 'POST', body: { paid: 1 } }).then(function () { roiPanel(id); }).catch(function (er) { toast(er.message, true); }); }
			});
		}).catch(function (e) { host.innerHTML = '<div class="ap-panel"><p style="padding:16px;color:var(--bad)">' + esc(e.message) + '</p></div>'; });
	}

	/* ---------------- Промо код: модал „свържи съществуващ / създай нов" (1.4.0) ---------------- */
	function couponModal(id, p) {
		var ov = document.createElement('div');
		ov.className = 'ap-overlay';
		ov.style.cssText = 'position:fixed;inset:0;z-index:100001;background:rgba(22,48,63,.45);display:flex;align-items:flex-start;justify-content:center;padding:6vh 16px;overflow-y:auto';
		var today = new Date().toISOString().slice(0, 10);
		ov.innerHTML = '<div class="ap-modal" role="dialog" aria-modal="true" style="width:620px">' +
			'<h2>Промо код за ' + esc(p.display_name) + '</h2>' +
			'<div class="ap-tabs" style="margin:0 0 14px"><button type="button" class="ap-tab on" data-cm="link">Свържи съществуващ</button><button type="button" class="ap-tab" data-cm="new">Създай нов</button></div>' +
			'<div id="cm-link">' +
				'<div class="ap-field"><label>Търси купон (пиши поне 2 букви)</label><input class="ap-input" id="cm-q" placeholder="GEORGI…" autocomplete="off"></div>' +
				'<div class="ap-cm-list" id="cm-list"><p class="ap-muted" style="margin:6px 0">Резултатите се показват тук с брой поръчки и оборот.</p></div>' +
				'<div class="ap-cm-pick" id="cm-pick" hidden></div>' +
			'</div>' +
			'<div id="cm-new" hidden>' +
				'<div class="ap-row2">' +
					'<div class="ap-field"><label>Код</label><input class="ap-input" id="cm-code" placeholder="напр. GEORGIPROOF" style="text-transform:uppercase"></div>' +
					'<div class="ap-field"><label>Отстъпка за КЛИЕНТА</label><div style="display:flex;gap:8px"><select class="ap-select" id="cm-type"><option value="percent">процент</option><option value="fixed_cart">фиксирана €</option></select><input class="ap-input" id="cm-amount" type="number" step="0.01" value="' + esc(BOOT.defaultCoupon || 10) + '" style="width:90px"></div></div>' +
				'</div>' +
			'</div>' +
			'<hr>' +
			'<div class="ap-field"><label>Следи се от (поръчки преди датата = история, без комисиона)</label><input class="ap-input" id="cm-from" type="date" value="' + esc(p.tracked_from || today) + '" style="max-width:220px"></div>' +
			'<div class="ap-actions"><button type="button" class="ap-btn ghost" id="cm-cancel">Отказ</button><button type="button" class="ap-btn" id="cm-go" disabled>Свържи кода</button></div>' +
		'</div>';
		document.body.appendChild(ov);
		var mode = 'link', picked = null, t = null, last = '';
		var $q = ov.querySelector('#cm-q'), $list = ov.querySelector('#cm-list'), $pick = ov.querySelector('#cm-pick'), $go = ov.querySelector('#cm-go');
		function setMode(m) {
			mode = m; ov.querySelectorAll('[data-cm]').forEach(function (b) { b.classList.toggle('on', b.dataset.cm === m); });
			ov.querySelector('#cm-link').hidden = m !== 'link'; ov.querySelector('#cm-new').hidden = m !== 'new';
			$go.textContent = m === 'link' ? 'Свържи кода' : 'Създай кода'; refreshGo();
			if (m === 'new') ov.querySelector('#cm-code').focus(); else $q.focus();
		}
		function refreshGo() { $go.disabled = mode === 'link' ? !picked : !ov.querySelector('#cm-code').value.trim(); }
		ov.querySelectorAll('[data-cm]').forEach(function (b) { b.addEventListener('click', function () { setMode(b.dataset.cm); }); });
		ov.querySelector('#cm-code').addEventListener('input', refreshGo);
		function render(list) {
			if (!list.length) { $list.innerHTML = '<p class="ap-muted" style="margin:6px 0">Няма купон, започващ с „' + esc(last) + '". Превключи на „Създай нов".</p>'; return; }
			$list.innerHTML = list.map(function (c) {
				var disc = c.type === 'percent' ? c.amount + '%' : c.amount + ' €';
				return '<button type="button" class="ap-cm-row' + (c.mapped ? ' off' : '') + '" data-code="' + esc(c.code) + '"' + (c.mapped ? ' disabled' : '') + '>' +
					'<b>' + esc(c.code.toUpperCase()) + '</b><span>' + esc(disc) + ' за клиента · <b>' + c.orders + '</b> поръчки (' + c.won + ' спечелени · ' + c.revenue + ' €)' + (c.last ? ' · последна ' + esc(c.last) : '') + (c.mapped ? ' · вече е на друг партньор' : '') + '</span></button>';
			}).join('');
		}
		$q.addEventListener('input', function () {
			var q = $q.value.trim(); clearTimeout(t); picked = null; $pick.hidden = true; refreshGo();
			if (q.length < 2) { $list.innerHTML = '<p class="ap-muted" style="margin:6px 0">Пиши поне 2 букви.</p>'; return; }
			$list.innerHTML = '<p class="ap-muted" style="margin:6px 0">Търся…</p>';
			t = setTimeout(function () { last = q; api('proof/coupons/suggest?q=' + encodeURIComponent(q)).then(function (list) { if (q === last) render(list); }).catch(function (e) { $list.innerHTML = '<p class="ap-muted">' + esc(e.message) + '</p>'; }); }, 200);
		});
		$list.addEventListener('click', function (e) {
			var b = e.target.closest('button[data-code]'); if (!b || b.disabled) return;
			picked = b.dataset.code; $list.querySelectorAll('.ap-cm-row').forEach(function (x) { x.classList.toggle('on', x === b); });
			$pick.hidden = false; $pick.innerHTML = 'Избран: <b>' + esc(picked.toUpperCase()) + '</b> — кодът и отстъпката му остават както са. Всички стари поръчки с него ще влязат в книгата на партньора като „История (0 €)".';
			refreshGo();
		});
		$q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); var f = $list.querySelector('button[data-code]:not([disabled])'); if (f) f.click(); } });
		ov.querySelector('#cm-cancel').addEventListener('click', function () { ov.remove(); });
		ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
		document.addEventListener('keydown', function esc_(e) { if (e.key === 'Escape') { ov.remove(); document.removeEventListener('keydown', esc_); } });
		$go.addEventListener('click', function () {
			var code = mode === 'link' ? picked : ov.querySelector('#cm-code').value.trim();
			if (!code) return;
			$go.disabled = true; $go.textContent = mode === 'link' ? 'Свързвам…' : 'Създавам…';
			var from = ov.querySelector('#cm-from').value;
			api('partners/' + id + '/coupons', { method: 'POST', body: { coupon_code: code, coupon_type: ov.querySelector('#cm-type').value, coupon_amount: ov.querySelector('#cm-amount').value } })
				.then(function (r) {
					if (r && r.coupon_warning) toast(r.coupon_warning, true);
					return api('proof/history/' + id, { method: 'POST', body: { tracked_from: from } });
				})
				.then(function (h) {
					ov.remove();
					notice(code.toUpperCase() + ' е ' + (mode === 'link' ? 'свързан' : 'създаден'),
						(h.rows && h.rows.rows ? h.rows.rows + ' стари поръчки са записани като история (0 €): ' + h.history.won + ' спечелени на стойност ' + money(h.history.revenue) + '.\n' : 'Няма стари поръчки с този код.\n') +
						'Следи се от ' + h.tracked_from + ' — от тази дата всяка поръчка с кода носи комисиона.' + liveLine(h.live));
					location.hash = '#/partners/' + id; route();
				})
				.catch(function (e) { $go.disabled = false; $go.textContent = mode === 'link' ? 'Свържи кода' : 'Създай кода'; toast(e.message, true); });
		});
		setTimeout(function () { $q.focus(); }, 30);
	}

	function addPartnerModal() {
		var ov = document.createElement('div');
		ov.className = 'ap-overlay';
		ov.style.cssText = 'position:fixed;inset:0;z-index:100001;background:rgba(67,48,42,.4);display:flex;align-items:flex-start;justify-content:center;padding:6vh 16px;overflow-y:auto';
		ov.innerHTML = '<div class="ap-modal" role="dialog" aria-modal="true">' +
			'<h2>Нов партньор</h2>' +
			'<div class="ap-field"><label>Име *</label><input class="ap-input" id="apm-name" placeholder="напр. Йоана Димитрова"></div>' +
			'<div class="ap-field"><label>Email *</label><input class="ap-input" id="apm-email" type="email" placeholder="name@example.com"></div>' +
			'<div class="ap-row2">' +
				'<div class="ap-field"><label>Ставка (%)</label><input class="ap-input" id="apm-rate" type="number" step="0.001" value="' + esc(BOOT.defaultRate || 10) + '"></div>' +
				'<div class="ap-field"><label>Следи се от (стари поръчки с кода = история)</label><input class="ap-input" id="apm-from" type="date" value="' + new Date().toISOString().slice(0, 10) + '"></div>' +
				'<div class="ap-field"><label>Метод на плащане</label><input class="ap-input" id="apm-method" value="bank"></div>' +
			'</div>' +
			'<div class="ap-field"><label>Имена по документ (за Wise)</label><input class="ap-input" id="apm-legal"></div>' +
			'<div class="ap-row2">' +
				'<div class="ap-field"><label>IBAN</label><input class="ap-input" id="apm-iban" placeholder="BG.."></div>' +
				'<div class="ap-field"><label>BIC (по избор)</label><input class="ap-input" id="apm-bic"></div>' +
			'</div>' +
			'<div class="ap-field"><label>Бележки за плащане</label><textarea class="ap-textarea" id="apm-details" rows="2"></textarea></div>' +
			'<hr>' +
			'<div class="ap-field"><label>Промо код (по избор — създава се веднага)</label><input class="ap-input" id="apm-code" placeholder="напр. JOANAPROOF"></div>' +
			'<div class="ap-row2">' +
				'<div class="ap-field"><label>Тип отстъпка</label><select class="ap-select" id="apm-ctype"><option value="percent">процент</option><option value="fixed_cart">фиксирана</option></select></div>' +
				'<div class="ap-field"><label>Размер</label><input class="ap-input" id="apm-camount" type="number" step="0.01" value="5"></div>' +
			'</div>' +
			'<div class="ap-actions"><button type="button" class="ap-btn ghost" id="apm-cancel">Откажи</button><button type="button" class="ap-btn leaf" id="apm-save">Създай партньор</button></div>' +
		'</div>';
		document.body.appendChild(ov);
		ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
		ov.querySelector('#apm-cancel').addEventListener('click', function () { ov.remove(); });
		ov.querySelector('#apm-name').focus();

		ov.querySelector('#apm-save').addEventListener('click', function () {
			var btn = this; btn.disabled = true;
			api('partners', { method: 'POST', body: {
				display_name: ov.querySelector('#apm-name').value,
				email: ov.querySelector('#apm-email').value,
				rate: ov.querySelector('#apm-rate').value,
				tracked_from: ov.querySelector('#apm-from').value,
				payment_method: ov.querySelector('#apm-method').value,
				payment_details: ov.querySelector('#apm-details').value,
				legal_name: ov.querySelector('#apm-legal').value,
				iban: ov.querySelector('#apm-iban').value,
				bic: ov.querySelector('#apm-bic').value,
				coupon_code: ov.querySelector('#apm-code').value,
				coupon_type: ov.querySelector('#apm-ctype').value,
				coupon_amount: ov.querySelector('#apm-camount').value
			}}).then(function (r) {
				ov.remove();
				toast('Партньорът е създаден');
				if (r.coupon_warning) toast('Купон: ' + r.coupon_warning, true);
				location.hash = '#/partners/' + r.partner_id;
			}).catch(function (e) { btn.disabled = false; toast(e.message, true); });
		});
	}

	function viewPartner(id) {
		loading('Партньор', 'partner'); try { window.scrollTo(0, 0); var mc = document.querySelector('.ap-content'); if (mc) mc.scrollTop = 0; } catch (x) {}
		Promise.all([api('partners/' + id), api('proof/roi/' + id).catch(function () { return null; })]).then(function (res) {
			var p = res[0], R = res[1] || { months: [], totals: {}, invoices: [], prev_month: null, this_month: null };
			var fixed = p.pay_model === 'fixed';
			var SEC = function (key, title, summary, bodyHtml, openDefault, extraClass) {
				var open = (function () { try { var v = localStorage.getItem('pp_sec_' + key); return v === null ? openDefault : v === '1'; } catch (e) { return openDefault; } })();
				return '<details class="pp-sec' + (extraClass ? ' ' + extraClass : '') + '" id="s-' + key + '" data-sec="' + key + '"' + (open ? ' open' : '') + '><summary><span class="t">' + title + '</span><span class="sum">' + summary + '</span><span class="car">▾</span></summary><div class="body">' + bodyHtml + '</div></details>';
			};
			var ini = (p.display_name || '?').split(/\s+/).map(function (w) { return w.charAt(0); }).slice(0, 2).join('').toUpperCase();
			var codesTxt = (p.coupons || []).map(function (c) { return '<b>' + esc(c.coupon_code.toUpperCase()) + '</b>'; }).join(', ');

			/* ── КОЙ Е ── */
			var html = '<a class="ap-back" href="#/partners">&larr; Всички партньори</a>' +
				'<div class="pp-hero"><div class="av">' + esc(ini) + '</div><div><h2>' + esc(p.display_name) + '</h2><div class="meta">' + chip(p.status, PSTATUS_BG[p.status]) + (fixed ? chip('fixed', '📄 Фиксирано с фактура') : chip('cy', '% Комисиона ' + p.rate + '%')) +
				'<span>' + esc(p.email) + '</span>' + (p.channels ? '<span>·</span><span>' + esc(p.channels) + '</span>' : '') + (codesTxt ? '<span>·</span><span>кодове ' + codesTxt + '</span>' : '') + (p.tracked_from ? '<span>·</span><span>следи се от <b>' + esc(p.tracked_from) + '</b></span>' : '') + '</div></div>' +
				'<div class="act"><a class="ap-btn" style="text-decoration:none" target="_blank" rel="noopener" href="' + (BOOT.home || '') + '/partner?view_as=' + id + '">👁 Като партньора</a></div></div>';

			/* ── ЕДИН ПОГЛЕД ── */
			var months = (R.months || []).slice().sort(function (a, b) { return a.ym < b.ym ? -1 : 1; }).slice(-6);
			var maxRev = Math.max(1, Math.max.apply(null, months.map(function (m) { return m.revenue; }).concat([0])));
			var thisYm = new Date().toISOString().slice(0, 7), tm = null; months.forEach(function (m) { if (m.ym === thisYm) tm = m; });
			var bars = months.map(function (m) { return '<i class="' + (m.ym === thisYm ? 'cur' : '') + '" title="' + esc(m.ym) + ': ' + m.orders + ' пор. · ' + money(m.revenue) + '"><b style="height:' + Math.max(4, Math.round(m.revenue / maxRev * 100)) + '%"></b></i>'; }).join('');
			var legend = months.map(function (m) { return '<span>' + ['', 'яну', 'фев', 'мар', 'апр', 'май', 'юни', 'юли', 'авг', 'сеп', 'окт', 'ное', 'дек'][+m.ym.slice(5, 7)] + '</span>'; }).join('');
			var T = R.totals || {}, S = p.shipments || {};
			var earned = fixed ? (tm ? tm.would_commission : 0) : (tm ? tm.commission : 0);
			var verdict;
			if (fixed) {
				var pm = R.prev_month;
				if (pm && pm.invoice > 0) verdict = '<div class="pp-g ver ' + (pm.behind ? 'bad' : 'good') + '"><div class="l">' + esc(pm.ym) + ' · присъда</div><div class="v">' + (pm.behind ? '−' : '+') + money(Math.abs(pm.diff)) + '</div><div class="s">' + (pm.behind ? 'фактура ' + money(pm.invoice) + ' срещу ' + money(pm.commission) + ' комисиона' + (pm.commission > 0 ? ' (' + (Math.round(pm.invoice / pm.commission * 10) / 10) + '×)' : '') + '. Комисионата е по-евтина.' : 'изкарва повече от фактурата (' + money(pm.invoice) + '). Фиксираното е изгодно.') + '</div></div>';
				else verdict = '<div class="pp-g ver"><div class="l">Присъда</div><div class="v">—</div><div class="s">няма фактура за предходния месец</div></div>';
			} else {
				verdict = '<div class="pp-g ver"><div class="l">Дължимо</div><div class="v">' + money(p.totals.approved) + '</div><div class="s">изчакващи ' + money(p.totals.pending) + ' · сторно ' + money(p.totals.reversed) + '</div></div>';
			}
			html += '<div class="pp-glance">' +
				'<div class="pp-g"><div class="l">Продажби с кодовете · 6 месеца</div><div class="v">' + (tm ? money(tm.revenue) : money(0)) + '</div><div class="s">' + (tm ? tm.orders : 0) + ' поръчки този месец</div><div class="bars">' + (bars || '<i><b style="height:4%"></b></i>') + '</div><div class="legend">' + legend + '</div></div>' +
				'<div class="pp-g"><div class="l">' + (fixed ? 'Изкарал (би било комисиона)' : 'Изкарал (комисиона)') + '</div><div class="v">' + money(earned) + '</div><div class="s">' + (fixed ? p.rate + '% · общо ' + money(T.would || 0) : 'този месец · общо ' + money(T.commission_paid || 0)) + '</div></div>' +
				'<div class="pp-g"><div class="l">Платили сме му</div><div class="v">' + money(fixed ? (T.cost || 0) : p.totals.paid) + '</div><div class="s">' + (fixed ? (R.invoices || []).length + ' фактури' + ((R.invoices || []).some(function (i) { return !i.paid; }) ? ' · <b style="color:var(--bad)">' + (R.invoices || []).filter(function (i) { return !i.paid; }).length + ' неплатена</b>' : '') : 'изплатени комисиони') + '</div></div>' +
				'<div class="pp-g"><div class="l">Дали сме му стока</div><div class="v">' + (S.qty || 0) + ' бр.</div><div class="s">' + money(S.value || 0) + ' по каталог · ' + (S.delivered || 0) + ' от ' + (S.shipments || 0) + ' получени</div></div>' +
				verdict + '</div>';

			/* ── НАВИГАЦИЯ ── */
			var realRows = (p.ledger || []).filter(function (r) { return r.status !== 'history'; }), histRows = (p.history || []);
			html += '<div class="pp-subnav">' +
				'<a href="#s-sales">Продажби <span class="n">' + realRows.length + '</span></a>' +
				(fixed ? '<a href="#s-roi">Възвръщаемост · Фактури <span class="n">' + (R.invoices || []).length + '</span></a>' : '<a href="#s-fate">Съдба на поръчките</a>') +
				'<a href="#s-ship">Пратки <span class="n">' + (S.shipments || 0) + '</span></a>' +
				'<a href="#s-codes">Кодове <span class="n">' + (p.coupons || []).length + '</span></a>' +
				'<a href="#s-offers">Оферти</a>' +
				'<a href="#s-data">Данни</a>' +
				(p.history_count ? '<a href="#s-hist">История <span class="n">' + p.history_count + '</span></a>' : '') +
				'<a href="#s-access">Достъп · бележки</a></div>';

			/* ── ПРОДАЖБИ ── */
			var lastRow = realRows[0];
			html += SEC('sales', 'Продажби', '<b>' + realRows.length + '</b> движения' + (lastRow ? ' · последно ' + esc(dt(lastRow.created_at)) : '') + (fixed ? ' · фиксиран договор (0 € комисиона)' : ' · дължимо ' + money(p.totals.approved)),
				'<div id="pp-sales-host"></div><div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px"><span class="ap-muted">Най-новите първи · по 20 на страница · последните 150</span><a href="#/commissions?partner=' + id + '" class="ap-btn sm ghost">Всички в Комисиони →</a></div>', true);

			/* ── ВЪЗВРЪЩАЕМОСТ / СЪДБА ── */
			if (fixed) {
				html += SEC('roi', 'Възвръщаемост · Фактури', (R.prev_month && R.prev_month.invoice > 0 ? 'предходен месец: разход <b>' + (R.prev_month.revenue > 0 ? Math.round(R.prev_month.invoice / R.prev_month.revenue * 1000) / 10 : '—') + '%</b> от оборота · ' + (R.prev_month.behind ? '<b style="color:var(--bad)">плащаме повече, отколкото изкарва</b>' : '<b style="color:var(--good)">изкарва повече от фактурата</b>') : 'няма фактура за предходния месец') + ' · фактурирано общо <b>' + money(T.cost || 0) + '</b>',
					'<div id="app-roi"><p class="ap-muted">Зареждане…</p></div>', true);
			} else {
				html += SEC('fate', 'Съдба на поръчките — 90 дни', 'взети / невзети / в движение', '<div id="ap-outcomes-body"><p class="ap-muted">Зареждане…</p></div>', false);
			}

			/* ── ПРАТКИ + КОДОВЕ ── */
			var codesRows = (p.coupons || []).map(function (c) {
				var link = c.vanity || c.ref_link;
				return '<tr><td><code class="ap-code">' + esc(c.coupon_code.toUpperCase()) + '</code></td><td class="ap-linkcell"><span class="ap-trunc" title="' + esc(link) + '">' + esc(link.replace(/^https?:\/\//, '')) + '</span><button type="button" class="ap-icobtn" data-copy="' + esc(link) + '" title="Копирай линка">⧉</button></td><td class="num">' + (c.clicks_30d || 0) + '</td><td>' + (c.active ? chip('active', 'Активен') : chip('archived', 'Спрян')) + '</td>' +
					'<td style="text-align:right"><button class="ap-btn sm ghost" data-toggle="' + c.id + '" data-to="' + (c.active ? 0 : 1) + '">' + (c.active ? 'Спри' : 'Активирай') + '</button></td></tr>';
			}).join('');
			html += '<div class="pp-two">' +
				SEC('ship', 'Служебни пратки', (S.shipments ? '<b>' + (S.qty || 0) + ' бр.</b> получени · ' + money(S.value || 0) + ' по каталог' + ((S.shipments || 0) > (S.delivered || 0) ? ' · <b style="color:var(--warn,#B3690E)">' + ((S.shipments || 0) - (S.delivered || 0)) + ' по пътя / отменени</b>' : '') : 'няма записани · впиши № на поръчка'), '<div id="app-ship"><p class="ap-muted">Зареждане…</p></div>', !!S.shipments, S.shipments ? '' : 'empty') +
				SEC('codes', 'Промо кодове', (codesTxt ? codesTxt + ' · ' + (p.coupons || []).reduce(function (a, c) { return a + (c.clicks_30d || 0); }, 0) + ' клика / 30 дни' : '<b style="color:var(--bad)">няма код</b> — без код няма продажби'),
					'<table class="ap-table"><thead><tr><th>Код</th><th>Линк</th><th style="text-align:right">Кликове 30д</th><th>Статус</th><th></th></tr></thead><tbody>' + (codesRows || '<tr><td colspan="5" class="empty">Няма кодове.</td></tr>') + '</tbody></table>' +
					'<div class="ap-add ap-actions" style="margin-top:12px"><button class="ap-btn sm" id="app-addcode">＋ Промо код</button><span class="ap-hint">свързва съществуващ купон или създава нов; старите поръчки влизат като история</span></div>', !(p.coupons || []).length, (p.coupons || []).length ? '' : 'empty') +
				'</div>';

			/* ── ОФЕРТИ (лични правила) ── */
			html += SEC('offers', 'Оферти · правила', (fixed ? 'фиксиран договор — правилата влияят само на отстъпката за клиента и на „би било"' : 'лични условия по оферта, бият глобалните'), '<div id="app-offers"><p class="ap-muted">Зареждане…</p></div>', false);

			/* ── ДАННИ ── */
			var dataSum = (fixed ? (p.company_name ? esc(p.company_name) + ' · ЕИК ' + esc(p.company_id || '—') + ' · ' : '<b style="color:var(--bad)">без фирмени данни</b> · ') : 'ставка <b>' + p.rate + '%</b> · ') + esc(p.payment_method || 'bank') + ' · IBAN <b>' + (p.iban ? esc(p.iban.slice(0, 6)) + '…' : '—') + '</b>' + (p.tracked_from ? ' · следи се от ' + esc(p.tracked_from) : ' · <b style="color:var(--bad)">без начална дата</b>');
			var dataBody =
				'<div class="pp-grp"><b>Профил</b><div class="pp-r4">' +
					'<div class="ap-field"><label>Име</label><input class="ap-input" id="app-name" value="' + esc(p.display_name) + '"></div>' +
					'<div class="ap-field"><label>Статус</label><select class="ap-select" id="app-status" style="width:100%">' + ['applicant', 'active', 'paused', 'archived'].map(function (s) { return '<option value="' + s + '"' + (p.status === s ? ' selected' : '') + '>' + PSTATUS_BG[s] + '</option>'; }).join('') + '</select></div>' +
					'<div class="ap-field"><label title="Поръчки преди датата = история, без комисиона">Следи се от</label><input class="ap-input" id="app-from" type="date" value="' + esc(p.tracked_from || '') + '"></div>' +
					'<div class="ap-field"><label>История преди старта</label><div style="display:flex;gap:6px"><input class="ap-input" id="app-life" value="' + esc(p.lifetime_imported || '') + '" placeholder="—"><button class="ap-btn ghost sm" id="app-hist" title="преизчислява старите поръчки спрямо датата">↻</button></div></div>' +
				'</div><div class="ap-field"><label>Бележки</label><textarea class="ap-textarea" id="app-notes" rows="2">' + esc(p.notes) + '</textarea></div></div>' +
				'<div class="pp-grp"><b>Възнаграждение</b><div class="ap-row2">' +
					'<div class="ap-field"><label>Модел на плащане</label><select class="ap-select" id="app-model" style="width:100%"><option value="commission"' + (!fixed ? ' selected' : '') + '>Комисиона от продажбите (по ставката, през кода)</option><option value="fixed"' + (fixed ? ' selected' : '') + '>Фиксирано с фактура (продажбите се следят, комисиона не се дължи)</option></select></div>' +
					'<div class="ap-field"><label>' + (fixed ? 'Ставка (%) — само за сравнение „ако беше на комисиона"' : 'Ставка (%) — комисионата НА ПАРТНЬОРА (отстъпката за клиента е в купона)') + '</label><input class="ap-input" id="app-rate" type="number" step="0.001" value="' + esc(p.rate) + '"></div>' +
				'</div><div id="app-company" class="pp-company' + (fixed ? '' : ' off') + '"><div class="pp-r4">' +
					'<div class="ap-field"><label>Фирма</label><input class="ap-input" id="app-cname" value="' + esc(p.company_name || '') + '" placeholder="Фирма ЕООД"></div>' +
					'<div class="ap-field"><label>ЕИК</label><input class="ap-input" id="app-cid" value="' + esc(p.company_id || '') + '"></div>' +
					'<div class="ap-field"><label>ДДС №</label><input class="ap-input" id="app-cvat" value="' + esc(p.company_vat || '') + '" placeholder="BG…"></div>' +
					'<div class="ap-field"><label>МОЛ</label><input class="ap-input" id="app-cmol" value="' + esc(p.company_mol || '') + '"></div>' +
				'</div><div class="ap-field"><label>Адрес</label><input class="ap-input" id="app-caddr" value="' + esc(p.company_address || '') + '"></div>' +
				'<div class="pp-msg"><div class="ap-sub" style="margin-top:14px">Съобщение в портала (този партньор · празно = глобалният текст)</div>' +
				'<div class="pp-msg-prev' + (p.msg_preview && p.msg_preview.text ? '' : ' none') + '"><b>Сега партньорът вижда:</b> ' + (p.msg_preview && p.msg_preview.text ? esc(p.msg_preview.text) : 'нищо') + '<br><span class="ap-muted">' + esc((p.msg_preview && p.msg_preview.why) || '') + '</span></div>' +
				'<div class="ap-row2"><div class="ap-field"><label>Когато комисионата е ПОД фактурата</label><textarea class="ap-textarea" id="app-msgb" rows="3" placeholder="(глобалният текст)">' + esc(p.msg_behind || '') + '</textarea></div><div class="ap-field"><label>Когато е НАД фактурата</label><textarea class="ap-textarea" id="app-msga" rows="3" placeholder="(глобалният текст)">' + esc(p.msg_ahead || '') + '</textarea></div></div>' +
				'<div class="ap-row3"><div class="ap-field"><label>{wish} · до 27-о число</label><input class="ap-input" id="app-msgwm" value="' + esc(p.msg_wish_mid || '') + '" placeholder="до края на месеца…"></div><div class="ap-field"><label>{wish} · 28-о и след</label><input class="ap-input" id="app-msgwe" value="' + esc(p.msg_wish_end || '') + '" placeholder="другия месец…"></div><div class="ap-field"><label>{wish} · нов месец без фактура</label><input class="ap-input" id="app-msgwn" value="' + esc(p.msg_wish_next || '') + '" placeholder="през текущия месец…"></div></div>' +
				'<p class="ap-hint">Месецът в съобщението е текущият, щом има входирана фактура за него; иначе предходният (докато не входираш новата). Плейсхолдъри: {name} {month} {commission} {invoice} {revenue} {orders} {rate} {brand} {wish}.</p></div>' +
				'</div></div>' +
				'<div class="pp-grp"><b>Плащане</b><div class="pp-r4">' +
					'<div class="ap-field"><label>Метод</label><input class="ap-input" id="app-method" value="' + esc(p.payment_method) + '"></div>' +
					'<div class="ap-field"><label>IBAN</label><input class="ap-input" id="app-iban" value="' + esc(p.iban || '') + '" placeholder="BG.."></div>' +
					'<div class="ap-field"><label>BIC (авто за БГ банки)</label><input class="ap-input" id="app-bic" value="' + esc(p.bic || '') + '"></div>' +
					'<div class="ap-field"><label>Имена по документ (за Wise)</label><input class="ap-input" id="app-legal" value="' + esc(p.legal_name || '') + '"></div>' +
				'</div><div class="ap-field"><label>Бележки за плащането (свободен текст — не влияят на сметката)</label><textarea class="ap-textarea" id="app-details" rows="2">' + esc(p.payment_details) + '</textarea></div></div>' +
				'<div style="display:flex;gap:10px;justify-content:flex-end;align-items:center"><span class="ap-muted">Пауза/Архив спира и кодовете. Ctrl+S записва.</span><button class="ap-btn leaf" id="app-save">Запази промените</button></div>';
			html += SEC('data', 'Данни', dataSum, dataBody, !p.tracked_from || (fixed && !p.company_name));

			/* ── ИСТОРИЯ ── */
			if (p.history_count) html += SEC('hist', 'История преди старта', '<b>' + p.history_count + '</b> поръчки · ' + esc(p.lifetime_imported || '') + ' · 0 € комисиона · не влиза в никоя сметка', '<div id="pp-hist-host"></div><div style="margin-top:8px"><a href="#/commissions?status=history&partner=' + id + '" class="ap-btn sm ghost">Всички ' + p.history_count + ' →</a></div>', false);

			/* ── ДОСТЪП · БЕЛЕЖКИ · РЪЧЕН РЕД ── */
			html += SEC('access', 'Достъп · бележки · ръчен ред', 'портал /partner · парола, линк за вход, дневник, ръчна корекция в книгата',
				'<div class="pp-grp"><b>Достъп до портала</b><div class="ap-inline">' +
				'<div class="ap-field w-m"><label>Нова парола (мин. 8)</label><input class="ap-input" id="app-newpw" type="text" placeholder="временна парола" autocomplete="off"></div>' +
				'<button class="ap-btn" id="app-setpw">Сетни паролата</button><button class="ap-btn ghost" id="app-resetlink">Прати линк за нова парола</button></div><p class="ap-hint" id="app-pw-out" style="margin-top:8px"></p></div>' +
				'<div class="pp-grp"><b>Дневник</b><div class="ap-inline"><div class="ap-field"><label>Нов запис</label><input class="ap-input" id="app-note-in" placeholder="Обади се, иска банери за stories…"></div><button class="ap-btn" id="app-note-add">Добави</button></div>' +
				'<pre id="app-notes-view" class="ap-log">' + esc(p.notes || '—') + '</pre></div>' +
				'<div class="pp-grp"><b>Ръчен ред в книгата</b><div class="ap-inline">' +
				'<div class="ap-field w-xs"><label>Сума (± EUR)</label><input class="ap-input" id="app-adj-amount" type="number" step="0.01" placeholder="25.00"></div>' +
				'<div class="ap-field"><label>Бележка (задължителна)</label><input class="ap-input" id="app-adj-note" placeholder="Бонус за юнската кампания"></div>' +
				'<button class="ap-btn" id="app-adj-add">Добави</button></div></div>', false);

			$title.textContent = p.display_name;
			$view.innerHTML = html;

			document.getElementById('app-model').addEventListener('change', function () { document.getElementById('app-company').classList.toggle('off', this.value !== 'fixed'); });
			if (fixed) roiPanel(id);
			shipPanel(id);
			offersPartnerPanel(id, document.getElementById('app-offers'));
			$view.querySelectorAll('.pp-sec').forEach(function (d) { d.addEventListener('toggle', function () { try { localStorage.setItem('pp_sec_' + d.dataset.sec, d.open ? '1' : '0'); } catch (e) {} }); });
			$view.querySelectorAll('.pp-subnav a').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); var t = $view.querySelector(a.getAttribute('href')); if (!t) return; t.open = true; t.scrollIntoView({ behavior: 'smooth', block: 'start' }); $view.querySelectorAll('.pp-subnav a').forEach(function (x) { x.classList.toggle('on', x === a); }); }); });
			$view.querySelectorAll('[data-copy]').forEach(function (b) { b.addEventListener('click', function () { navigator.clipboard.writeText(b.dataset.copy).then(function () { toast('Линкът е копиран'); }); }); });
			document.getElementById('app-save').addEventListener('click', function () {
				var btn = this; btn.disabled = true; btn.textContent = 'Записва…';
				var V = function (i) { var e = document.getElementById(i); return e ? e.value : ''; };
				api('partners/' + id, { method: 'POST', body: {
					display_name: document.getElementById('app-name').value,
					status: document.getElementById('app-status').value,
					rate: document.getElementById('app-rate').value,
					payment_method: document.getElementById('app-method').value,
					legal_name: document.getElementById('app-legal').value,
					iban: document.getElementById('app-iban').value,
					bic: document.getElementById('app-bic').value,
					payment_details: document.getElementById('app-details').value,
					notes: document.getElementById('app-notes').value,
					tracked_from: V('app-from'),
					lifetime_imported: V('app-life'),
					pay_model: V('app-model'), company_name: V('app-cname'), company_id: V('app-cid'), company_vat: V('app-cvat'), company_mol: V('app-cmol'), company_address: V('app-caddr'),
					msg_behind: V('app-msgb'), msg_ahead: V('app-msga'), msg_wish_mid: V('app-msgwm'), msg_wish_end: V('app-msgwe'), msg_wish_next: V('app-msgwn')
				}}).then(function () {
					if (!(p.coupons || []).length) { toast('Запазено'); viewPartner(id); refreshOwed(); return; }
					/* след Запази: проверка на всички поръчки с кодовете му → историята се преизчислява спрямо „Следи се от" */
					return api('proof/history/' + id, { method: 'POST', body: { tracked_from: V('app-from') } }).then(function (h) {
						notice('Запазено', 'Проверени са всички поръчки с кодовете: ' + (h.rows && h.rows.rows ? h.rows.rows + ' стари са записани ред по ред като история (' + h.history.won + ' спечелени / ' + money(h.history.revenue) + ').' : 'няма стари.') + '\nСледи се от ' + h.tracked_from + '.' + liveLine(h.live));
						viewPartner(id); refreshOwed();
					});
				}).catch(function (e) { btn.disabled = false; btn.textContent = 'Запази промените'; toast('Не се записа: ' + e.message, true); });
			});
			document.getElementById('app-hist').addEventListener('click', function () {
				var b = this; b.disabled = true;
				api('proof/history/' + id, { method: 'POST', body: { tracked_from: document.getElementById('app-from').value } }).then(function (r) {
					b.disabled = false; notice('Проверено', (r.history.won ? r.history.won + ' спечелени поръчки / ' + money(r.history.revenue) + ' преди ' + r.tracked_from + ' — записани като история.' : 'Няма поръчки с кодовете преди ' + r.tracked_from + '.') + liveLine(r.live));
					viewPartner(id);
				}).catch(function (e) { b.disabled = false; toast(e.message, true); });
			});

			document.getElementById('app-addcode').addEventListener('click', function () { couponModal(id, p); });

			$view.querySelectorAll('[data-toggle]').forEach(function (btn) {
				btn.addEventListener('click', function () {
					api('coupons/' + btn.dataset.toggle + '/toggle', { method: 'POST', body: { active: btn.dataset.to === '1' } })
						.then(function () { toast(btn.dataset.to === '1' ? 'Кодът е активиран' : 'Кодът е спрян'); viewPartner(id); })
						.catch(function (e) { toast(e.message, true); });
				});
			});

			pagedTable($view.querySelector('#pp-sales-host'), realRows, 20, function (sl) { return ledgerTable(sl, { partner: false, actions: true }); }, function () { bindLedgerActions(function () { viewPartner(id); }); bindRowLinks(); bindOrderInfo(); });
			pagedTable($view.querySelector('#pp-hist-host'), histRows, 20, function (sl) { return ledgerTable(sl, { partner: false, actions: false }); }, function () { bindRowLinks(); bindOrderInfo(); });

			api('partners/' + id + '/outcomes').then(function (o) {
				var body = document.getElementById('ap-outcomes-body');
				if (!body) return;
				var lostBits = [];
				if (o.lost_by.refunded)  lostBits.push(o.lost_by.refunded + ' върнати');
				if (o.lost_by.cancelled) lostBits.push(o.lost_by.cancelled + ' отказани');
				if (o.lost_by.failed)    lostBits.push(o.lost_by.failed + ' провалени');
				var movingBits = Object.keys(o.moving_by || {}).map(function (k) { return o.moving_by[k] + ' ' + k; });
				var warn = o.lost_pct >= 25 && (o.won + o.lost) >= 5;
				body.innerHTML =
					'<div class="ap-oi-grid" style="margin-top:6px">' +
					'<div><span class="l">Взети (доставени)</span><strong style="color:var(--good);font-size:19px">' + o.won + '</strong></div>' +
					'<div><span class="l">Невзети</span><strong style="color:' + (warn ? 'var(--bad)' : 'var(--ink)') + ';font-size:19px">' + o.lost + '</strong>' + (lostBits.length ? ' <span class="ap-muted">(' + lostBits.join(', ') + ')</span>' : '') + '</div>' +
					'<div><span class="l">В движение</span><strong style="font-size:19px">' + o.moving + '</strong>' + (movingBits.length ? ' <span class="ap-muted">(' + movingBits.join(', ') + ')</span>' : '') + '</div>' +
					'<div><span class="l">% невзети от решените</span><strong style="font-size:19px;color:' + (warn ? 'var(--bad)' : 'var(--good)') + '">' + o.lost_pct + '%</strong></div>' +
					'</div>' + (warn ? '<p style="color:var(--bad);font-weight:800;margin:10px 0 0">⚠ Над прага — провери за self-buy схема или проблемен трафик.</p>' : '');
			}).catch(function () {});

			var noteBtn = document.getElementById('app-note-add');
			if (noteBtn) noteBtn.addEventListener('click', function () {
				var inp = document.getElementById('app-note-in');
				if (!inp.value.trim()) return;
				api('partners/' + id + '/note', { method: 'POST', body: { note: inp.value } }).then(function (r) {
					var nEd = document.getElementById('app-notes'); if (nEd) nEd.value = r.notes;
					var nVw = document.getElementById('app-notes-view'); if (nVw) nVw.textContent = r.notes;
					inp.value = '';
					toast('Бележката е добавена');
				}).catch(function (e) { toast(e.message, true); });
			});

			var pwBtn = document.getElementById('app-setpw');
			if (pwBtn) pwBtn.addEventListener('click', function () {
				var pw = document.getElementById('app-newpw').value;
				if (pw.length < 8) { toast('Минимум 8 знака', true); return; }
				if (!confirm('Сетваш нова парола на партньора. Старата спира да работи веднага. Продължаваме?')) return;
				api('partners/' + id + '/password', { method: 'POST', body: { password: pw } }).then(function (r) {
					document.getElementById('app-pw-out').textContent = 'Готово ✓ Кажи му: login ' + r.login + ' / новата парола.';
					toast('Паролата е сменена');
				}).catch(function (e) { toast(e.message, true); });
			});
			var rlBtn = document.getElementById('app-resetlink');
			if (rlBtn) rlBtn.addEventListener('click', function () {
				rlBtn.disabled = true;
				api('partners/' + id + '/reset-link', { method: 'POST' }).then(function (r) {
					rlBtn.disabled = false;
					var out = document.getElementById('app-pw-out');
					out.innerHTML = (r.emailed ? 'Изпратен на ' + esc(r.email) + ' ✓ · ' : 'Email НЕ тръгна (SendGrid?) · ') + '<a href="#" id="app-copy-rl">копирай линка</a> и му го прати директно.';
					document.getElementById('app-copy-rl').addEventListener('click', function (e) {
						e.preventDefault();
						navigator.clipboard.writeText(r.url).then(function () { toast('Линкът е копиран — валиден 24ч'); });
					});
				}).catch(function (e) { rlBtn.disabled = false; toast(e.message, true); });
			});

			var adjBtn = document.getElementById('app-adj-add');
			if (adjBtn) adjBtn.addEventListener('click', function () {
				var amount = document.getElementById('app-adj-amount').value,
					note = document.getElementById('app-adj-note').value;
				if (!confirm('Ръчен ред: ' + amount + ' EUR — "' + note + '". Книгата е append-only, редът остава завинаги. Продължаваме?')) return;
				api('partners/' + id + '/adjustment', { method: 'POST', body: { amount: amount, note: note } })
					.then(function () { toast('Редът е добавен'); viewPartner(id); refreshOwed(); })
					.catch(function (e) { toast(e.message, true); });
			});
			bindRowLinks();
			bindOrderInfo();
		}).catch(function (e) { viewError(e); });
	}

	/* ---------------- Commissions ---------------- */

	function viewCommissions(qs) {
		loading('Комисиони');
		var status = qs.status || '';
		var pid = qs.partner || '';
		var pg = Math.max(1, parseInt(qs.pg || '1', 10) || 1);
		var q = ['page=' + pg];
		if (status) q.push('status=' + encodeURIComponent(status));
		if (pid) q.push('partner_id=' + encodeURIComponent(pid));

		Promise.all([ api('commissions' + (q.length ? '?' + q.join('&') : '')), api('partners') ]).then(function (res) {
			var rows = res[0].rows, counts = res[0].counts || {}, partners = res[1];

			function goto(s, page) {
				var parts = [];
				if (s) parts.push('status=' + s);
				if (pid) parts.push('partner=' + pid);
				if (page && page > 1) parts.push('pg=' + page);
				return '#/commissions' + (parts.length ? '?' + parts.join('&') : '');
			}
			var TAB_BG = { all: 'Всички', pending: 'Изчакващи', approved: 'За изплащане', paid: 'Изплатени', reversed: 'Сторнирани', history: 'История', offers: 'По оферти' };
			var tabs = '<div class="ap-tabs">' + Object.keys(TAB_BG).map(function (s) {
				var val = s === 'all' ? '' : s;
				return '<button class="ap-tab' + (status === val ? ' on' : '') + '" data-tab="' + val + '">' + TAB_BG[s] + (s === 'offers' ? '' : '<span class="c">' + (counts[s] || 0) + '</span>') + '</button>';
			}).join('') + '</div>';

			if (status === 'offers') {
				$title.textContent = 'Комисиони';
				$view.innerHTML = '<div class="ap-panel">' + tabs + '<div id="of-ledger"><p class="ap-muted" style="padding:16px 18px">Зареждане…</p></div></div>';
				$view.querySelectorAll('.ap-tab').forEach(function (b) { b.addEventListener('click', function () { location.hash = goto(b.dataset.tab); }); });
				offersLedger(document.getElementById('of-ledger'), qs);
				return;
			}
			var sum = rows.reduce(function (a, r) { return a + (Number(r.amount) || 0); }, 0);
			var pages = res[0].pages || 1, total = res[0].total || rows.length;
			var totline = '<div style="display:flex;gap:18px;padding:10px 18px;font-weight:800;border-top:2px solid var(--line);align-items:center;flex-wrap:wrap">' +
				'<span>' + total + ' реда · страница ' + pg + '/' + pages + '</span>' +
				'<span>Сума на страницата: <span class="' + (sum < 0 ? 'neg' : 'pos') + '">' + money(sum) + '</span></span>' +
				'<span class="sp" style="flex:1"></span>' +
				(pg > 1 ? '<button class="ap-btn ghost sm" id="apc-prev">‹ Предишни 20</button>' : '') +
				(pg < pages ? '<button class="ap-btn ghost sm" id="apc-next">Следващи 20 ›</button>' : '') +
				'<button class="ap-btn ghost sm" id="apc-csv">CSV ↓</button></div>';

			var html = '<div class="ap-panel">' + tabs + '<div class="ap-filters">' +
				'<input class="ap-input" id="apc-order" type="search" placeholder="Поръчка №…" style="width:130px"> ' +
				'<select class="ap-select" id="apc-partner"><option value="">Всички партньори</option>' +
					partners.map(function (p) { return '<option value="' + p.partner_id + '"' + (String(p.partner_id) === pid ? ' selected' : '') + '>' + esc(p.display_name) + '</option>'; }).join('') +
				'</select>' +
				'<span class="sp" style="flex:1"></span>' +
				'<button class="ap-btn ghost sm" id="apc-sweep" title="Обхожда доставените поръчки от последните 120 дни (на страници) и създава изпуснатите комисиони с датата на поръчката">🔄 Претърси доставени</button>' +
			'</div>' + ledgerTable(rows, { partner: true, actions: true }) + totline + '</div>';

			$title.textContent = 'Комисиони';
			$view.innerHTML = html;

			$view.querySelectorAll('.ap-tab').forEach(function (b) {
				b.addEventListener('click', function () { location.hash = goto(b.dataset.tab); });
			});
			var pv = document.getElementById('apc-prev'), nx = document.getElementById('apc-next');
			if (pv) pv.addEventListener('click', function () { location.hash = goto(status, pg - 1); });
			if (nx) nx.addEventListener('click', function () { location.hash = goto(status, pg + 1); });
			var oq = document.getElementById('apc-order');
			if (oq) oq.addEventListener('input', function () {
				var v = oq.value.trim();
				$view.querySelectorAll('.ap-table tbody tr').forEach(function (tr) {
					if (tr.classList.contains('ap-oi-row')) return;
					tr.style.display = (!v || tr.textContent.indexOf('#' + v) !== -1) ? '' : 'none';
				});
			});
			var swBtn = document.getElementById('apc-sweep');
			function runSweep(pass) {
				pass = pass || 1;
				if (swBtn) { swBtn.disabled = true; swBtn.textContent = 'Претърсвам… (' + pass + ')'; }
				api('sweep', { method: 'POST' }).then(function (r) {
					var bf = r.backfill || {};
					if (!bf.done && pass < 200) {
						// историята се навакса на порции — въртим докато свърши
						if (swBtn) swBtn.textContent = '⏳ Наваксвам историята: ' + (bf.scanned || 0) + ' поръчки, ' + (bf.created || 0) + ' комисиони…';
						setTimeout(function () { runSweep(pass + 1); }, 400);
						return;
					}
					if (r.net_more && pass < 200) {
						// мрежата обхожда прозореца на страници по 300 — въртим до края
						if (swBtn) swBtn.textContent = '⏳ Мрежата: стр. ' + (r.net_page || pass) + ' · проверени ' + (r.scanned || 0) + '…';
						setTimeout(function () { runSweep(pass + 1); }, 400);
						return;
					}
					if (r.poison) toast('⚠️ Отровна поръчка в мрежата: ' + r.poison + ' — прескочена, виж Диагностика/Telegram', true);
					if (r.error) toast('⚠️ Sweep гръм: ' + r.error, true);
					toast(bf.finished && pass > 1
						? '✅ Историята е наваксана: проверени ' + (bf.scanned || 0) + ' поръчки, създадени ' + (bf.created || 0) + ' комисиони'
						: (r.created > 0
							? '🔄 Намерени и създадени: ' + r.created + ' комисиони (проверени ' + r.scanned + ' поръчки)'
							: 'Проверени ' + r.scanned + ' поръчки — нищо изпуснато' + (r.missing > r.created ? ' (' + (r.missing - r.created) + ' без код/партньор)' : '')));
					viewCommissions(qs);
				}).catch(function (e) { if (swBtn) { swBtn.disabled = false; swBtn.textContent = '🔄 Претърси доставени'; } toast(e.message, true); });
			}
			if (swBtn) swBtn.addEventListener('click', function () { runSweep(1); });
			// При отворен екран: ако наваксването не е завършило, стартирай само
			api('sweep-status').then(function (st) {
				if (st && st.backfill && !st.backfill.done) runSweep(1);
			}).catch(function () {});
			var csvBtn = document.getElementById('apc-csv');
			if (csvBtn) csvBtn.addEventListener('click', function () {
				tableToCsv(rows.map(function (r) {
					return [r.created_at, r.partner, r.order_id || '', (r.coupon_code || '').toUpperCase(), r.base_amount || '', r.rate_applied || '', r.amount, r.currency, r.status, r.type];
				}), ['Дата', 'Партньор', 'Поръчка', 'Код', 'База', 'Ставка %', 'Сума', 'Валута', 'Статус', 'Тип'],
				'commissions-' + (status || 'all') + '-' + new Date().toISOString().slice(0, 10) + '.csv');
			});
			document.getElementById('apc-partner').addEventListener('change', function () {
				pid = this.value;
				location.hash = goto(status);
				if (('#/' + (location.hash || '').slice(2)) === ('#/' + goto(status).slice(2))) route();
			});

			bindLedgerActions(function () { route(); refreshOwed(); });
			bindRowLinks();
			bindOrderInfo();
		}).catch(function (e) { viewError(e); });
	}

	/* ---------------- Payouts ---------------- */

	function tableToCsv(rows, headers, filename) {
		var csv = [headers.join(';')].concat(rows.map(function (r) {
			return r.map(function (c) { return '"' + String(c == null ? '' : c).replace(/"/g, '""') + '"'; }).join(';');
		})).join('\r\n');
		var blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' });
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob); a.download = filename;
		document.body.appendChild(a); a.click(); a.remove();
	}

	function viewRanking() {
		loading('Ранглиста');
		api('ranking').then(function (d) {
			var html = '<div class="ap-panel"><h2>Кого да натиснем — последните 30 дни<span class="sp"></span><button class="ap-btn ghost sm" id="ap-rank-csv">CSV ↓</button></h2>' +
				'<table class="ap-table"><thead><tr><th>#</th><th>Партньор</th><th style="text-align:right">Приходи 30д</th><th style="text-align:right">Комисиони</th><th style="text-align:right">Поръчки</th><th style="text-align:right">Кликове</th><th style="text-align:right">Конверсия</th><th style="text-align:right">Ставка</th></tr></thead><tbody>';
			if (!(d.active || []).length) html += '<tr><td colspan="8" class="empty">Още няма активност за 30 дни.</td></tr>';
			(d.active || []).forEach(function (p, i) {
				var medal = i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : (i + 1);
				html += '<tr class="click" data-go="#/partners/' + p.partner_id + '">' +
					'<td>' + medal + '</td><td><strong>' + esc(p.name) + '</strong></td>' +
					'<td class="num">' + money(p.rev30) + '</td>' +
					'<td class="num pos">' + money(p.comm30) + '</td>' +
					'<td class="num">' + p.orders30 + '</td>' +
					'<td class="num">' + p.clicks30 + '</td>' +
					'<td class="num">' + (p.conv === null ? '—' : p.conv + '%') + '</td>' +
					'<td class="num">' + p.rate + '%</td></tr>';
			});
			html += '</tbody></table></div>';

			html += '<div class="ap-panel"><h2>Заспали — без поръчка от 60+ дни (' + (d.sleeping || []).length + ')<span class="sp"></span>' +
				'<button class="ap-btn ghost sm" id="ap-sleep-emails">Копирай emails за реактивация</button></h2>' +
				'<table class="ap-table"><thead><tr><th>Партньор</th><th>Email</th><th>Последна поръчка</th><th style="text-align:right">Ставка</th></tr></thead><tbody>';
			if (!(d.sleeping || []).length) html += '<tr><td colspan="4" class="empty">Няма заспали — жива програма 💪</td></tr>';
			(d.sleeping || []).forEach(function (p) {
				html += '<tr class="click" data-go="#/partners/' + p.partner_id + '"><td>' + esc(p.name) + '</td><td class="ap-muted">' + esc(p.email || '—') + '</td><td class="ap-muted">' + esc(p.last) + '</td><td class="num">' + p.rate + '%</td></tr>';
			});
			html += '</tbody></table></div>';

			$title.textContent = 'Ранглиста';
			$view.innerHTML = html;
			bindRowLinks();
			var rc = document.getElementById('ap-rank-csv');
			if (rc) rc.addEventListener('click', function () {
				tableToCsv((d.active || []).map(function (p) {
					return [p.name, p.email, p.rev30, p.comm30, p.orders30, p.clicks30, p.conv === null ? '' : p.conv, p.rate];
				}), ['Партньор', 'Email', 'Приходи 30д', 'Комисиони 30д', 'Поръчки', 'Кликове', 'Конверсия %', 'Ставка %'], 'ranking-' + new Date().toISOString().slice(0, 10) + '.csv');
			});
			var se = document.getElementById('ap-sleep-emails');
			if (se) se.addEventListener('click', function () {
				var emails = (d.sleeping || []).map(function (p) { return p.email; }).filter(Boolean).join(', ');
				if (!emails) { toast('Няма emails', true); return; }
				navigator.clipboard.writeText(emails).then(function () { toast((d.sleeping || []).length + ' emails копирани — пусни им кампанията'); });
			});
		}).catch(function (e) { toast(e.message, true); });
	}

	function viewMarketing() {
		loading('Маркетинг');
		api('marketing').then(function (m) {
			function tierRows(str) {
				return (str || '').split(/[\r\n]+/).map(function (l) {
					var p = l.split('|').map(function (x) { return x.trim(); });
					return p.length >= 3 && p[0] ? { name: p[0], min: p[1], rate: p[2] } : null;
				}).filter(Boolean);
			}
			var tiers = tierRows(m.tiers);

			var html =
				'<div class="ap-panel"><h2>Партньорски нива<span class="sp"></span><label style="font-weight:800;font-size:13px"><input type="checkbox" id="mk-tiers-on"' + (m.tiers_enabled ? ' checked' : '') + '> активни</label></h2>' +
				'<div style="padding:0 18px 16px">' +
				'<table class="ap-table" style="max-width:640px"><thead><tr><th>Ниво</th><th>Мин. € / 30 дни</th><th>Ставка %</th><th style="text-align:right;width:120px">Партньори</th><th style="width:40px"></th></tr></thead><tbody id="mk-tbody"></tbody></table>' +
				'<button class="ap-btn ghost sm" id="mk-tadd">+ ниво</button>' +
				'<div id="mk-tchanges" style="margin-top:12px"></div>' +
				'<p class="ap-muted" style="margin-top:10px">Смяната на ставка важи само занапред (lock при checkout) и вдига сигнал + Telegram.</p>' +
				'</div></div>';

			html += '<div class="ap-panel"><h2>Бранд<span class="sp"></span></h2><div style="padding:0 18px 16px"><div class="mk-brandgrid">' +
				'<div>' +
				'<div class="ap-row2">' +
				'<div class="ap-field"><label>Име на бранда</label><input class="ap-input" id="mk-bname" value="' + esc(m.brand_name) + '"></div>' +
				'<div class="ap-field"><label>Фирма / юр. лице (платец в справките)</label><input class="ap-input" id="mk-bco" value="' + esc(m.brand_company) + '" placeholder="Фирма ЕООД"></div>' +
				'</div><div class="ap-row2">' +
				'<div class="ap-field"><label>ЕИК (по избор)</label><input class="ap-input" id="mk-beik" value="' + esc(m.brand_company_id || '') + '"></div>' +
				'<div class="ap-field"><label>Адрес на управление (по избор)</label><input class="ap-input" id="mk-baddr" value="' + esc(m.brand_company_address || '') + '"></div>' +
				'</div><div class="ap-row2">' +
				'<div class="ap-field"><label>Основен цвят</label><div style="display:flex;gap:8px"><input id="mk-baccent" type="color" value="' + esc(m.brand_accent) + '" style="width:52px;height:42px;border:2px solid var(--line);border-radius:10px;padding:2px;background:#fff"><input class="ap-input" id="mk-baccent-hex" value="' + esc(m.brand_accent) + '" style="width:110px"></div></div>' +
				'<div class="ap-field"><label>Втори цвят</label><div style="display:flex;gap:8px"><input id="mk-baccent2" type="color" value="' + esc(m.brand_accent2 || '#f8a41d') + '" style="width:52px;height:42px;border:2px solid var(--line);border-radius:10px;padding:2px;background:#fff"><input class="ap-input" id="mk-baccent2-hex" value="' + esc(m.brand_accent2 || '#f8a41d') + '" style="width:110px"></div></div>' +
				'</div><div class="ap-row2">' +
				'<div class="ap-field"><label>Лого URL (празно = вграденото)</label><input class="ap-input" id="mk-blogo" value="' + esc(m.brand_logo_url) + '"></div>' +
				'<div class="ap-field"><label>Суфикс на кодовете</label><input class="ap-input" id="mk-bsuffix" value="' + esc(m.brand_code_suffix || 'PROOF') + '"></div>' +
				'</div><div class="ap-row2">' +
				'<div class="ap-field"><label>Офсет № на справките (следваща = ID + офсет)</label><input class="ap-input" id="mk-stoffset" type="number" min="0" value="' + esc(m.statement_offset) + '"></div>' +
				'<div></div>' +
				'</div>' +
				'<div class="ap-field"><label>Изведена палитра</label><div id="mk-swatches" style="display:flex;gap:6px"></div></div>' +
				'</div>' +
				'<div><label style="font-size:10.5px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--muted)">Преглед на портала — на живо</label><div id="mk-bpreview" style="margin-top:6px"></div></div>' +
				'</div></div></div>';

			html += '<div class="ap-panel"><h2>Фиксиран договор — месечното съобщение в портала<span class="sp"></span></h2><div style="padding:0 18px 16px">' +
				'<div class="ap-field"><label>Когато комисионата от продажбите е ПО-МАЛКА от фактурата</label><textarea class="ap-textarea" id="mk-fxb" rows="3">' + esc(m.fixed_msg_behind || '') + '</textarea></div>' +
				'<div class="ap-field"><label>Когато е ПО-ГОЛЯМА или равна</label><textarea class="ap-textarea" id="mk-fxa" rows="3">' + esc(m.fixed_msg_ahead || '') + '</textarea></div>' +
				'<div class="ap-row3"><div class="ap-field"><label>{wish} · до 27-о число</label><input class="ap-input" id="mk-fxwm" value="' + esc(m.fixed_wish_mid || '') + '"></div><div class="ap-field"><label>{wish} · 28-о и след</label><input class="ap-input" id="mk-fxwe" value="' + esc(m.fixed_wish_end || '') + '"></div><div class="ap-field"><label>{wish} · нов месец без фактура</label><input class="ap-input" id="mk-fxwn" value="' + esc(m.fixed_wish_next || '') + '"></div></div>' +
				'<p class="ap-muted">Това са ГЛОБАЛНИТЕ текстове по подразбиране — всеки партньор може да има свои (картата → Данни → Възнаграждение). Месецът е текущият, щом има фактура за него, иначе предходният; {wish} се сменя според датата. Плейсхолдъри: <code class="ap-code">{name}</code> <code class="ap-code">{month}</code> <code class="ap-code">{commission}</code> <code class="ap-code">{invoice}</code> <code class="ap-code">{revenue}</code> <code class="ap-code">{orders}</code> <code class="ap-code">{rate}</code> <code class="ap-code">{brand}</code>. Празно = не се показва.</p>' +
				'</div></div>';
			html += '<div class="ap-panel"><h2>🎉 Кампания<span class="sp"></span></h2><div style="padding:0 18px 16px">' +
				'<textarea class="ap-textarea" id="mk-campaign" rows="2" placeholder="До неделя: {code} дава двойна отстъпка — пусни story днес!">' + esc(m.kit_campaign) + '</textarea>' +
				'<div class="ap-row2" style="margin-top:10px;max-width:460px">' +
				'<div class="ap-field"><label>От дата (празно = веднага)</label><input class="ap-input" id="mk-cfrom" type="date" value="' + esc(m.kit_campaign_from) + '"></div>' +
				'<div class="ap-field"><label>До дата (празно = безсрочно)</label><input class="ap-input" id="mk-cto" type="date" value="' + esc(m.kit_campaign_to) + '"></div>' +
				'</div>' +
				'<p class="ap-muted">Банерът се самопуска и самоспира по датите. Плейсхолдъри: <code class="ap-code">{code}</code> <code class="ap-code">{discount}</code> <code class="ap-code">{link}</code> <code class="ap-code">{name}</code> <code class="ap-code">{brand}</code></p>' +
				'</div></div>';

			html += '<div class="ap-panel"><h2>Шаблони „Готово за споделяне"<span class="sp"></span></h2><div style="padding:0 18px 16px">' +
				'<div class="ap-field"><label>Story</label><textarea class="ap-textarea" id="mk-story" rows="2">' + esc(m.kit_story) + '</textarea></div>' +
				'<div class="ap-field"><label>Пост</label><textarea class="ap-textarea" id="mk-post" rows="3">' + esc(m.kit_post) + '</textarea></div>' +
				'<div id="mk-preview"></div>' +
				'</div></div>';

			html += '<div class="ap-panel"><h2>🚚 Миграция от AffiliateWP<span class="sp"></span></h2><div style="padding:0 18px 16px">' +
				'<div class="ap-row2" style="max-width:640px">' +
				'<div class="ap-field"><label>Стари линк префикси (напр. hi) — /hi/КОД продължава да работи</label><input class="ap-input" id="mk-legacy" value="' + esc(m.legacy_link_prefixes || 'hi') + '"></div>' +
				'<div class="ap-field"><label>Slug на стария портал → 301 към /partner</label><input class="ap-input" id="mk-oldslug" value="' + esc(m.legacy_portal_slug || '') + '" placeholder="affiliate-area"></div>' +
				'</div>' +
				'<div class="ap-field"><label>Анонс до партньорите ({name} {brand} {portal})</label><textarea class="ap-textarea" id="mk-announce" rows="7">' + esc(m.migration_email || '') + '</textarea></div>' +
				'<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">' +
				'<button class="ap-btn ghost sm" id="mk-ann-test">Тествай върху МЕН първо</button>' +
				'<button class="ap-btn leaf sm" id="mk-ann-send">Изпрати до всички партньори</button>' +
				'<span class="ap-muted" id="mk-ann-status" style="font-size:12.5px"></span>' +
				'</div>' +
				'<p class="ap-muted" style="margin-top:8px">Залпът минава през опашката: 1 писмо / 4 сек (SendGrid-friendly). Достъпът им вече работи — същият email и парола влизат в /partner.</p>' +
				'</div></div>';

			html += '<div class="ap-panel"><h2>Telegram известия<span class="sp"></span></h2><div style="padding:0 18px 16px">' +
				'<div class="ap-row2" style="max-width:640px">' +
				'<div class="ap-field"><label>Bot token (от @BotFather)</label><input class="ap-input" id="mk-tgtoken" value="' + esc(m.tg_token) + '" placeholder="123456:ABC…"></div>' +
				'<div class="ap-field"><label>Chat ID (от @userinfobot)</label><input class="ap-input" id="mk-tgchat" value="' + esc(m.tg_chat) + '" placeholder="8837…"></div>' +
				'</div>' +
				'<button class="ap-btn ghost sm" id="mk-tgtest">Изпрати тестово съобщение</button>' +
				'<p class="ap-muted" style="margin-top:8px">Известия за: нов партньор · комисиона · изплащане · смяна на ниво · червена диагностика. Запази преди теста.</p>' +
				'</div></div>' +
				'<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px"><button class="ap-btn leaf" id="mk-save">Запази — на живо е веднага</button></div>';

			$title.textContent = 'Маркетинг';
			$view.innerHTML = html;

			/* ---- Tier редактор ---- */
			var tbody = document.getElementById('mk-tbody'), tchg = document.getElementById('mk-tchanges'), tprevTimer = null;
			function tiersToStr() {
				return Array.prototype.map.call(tbody.querySelectorAll('tr'), function (tr) {
					var i = tr.querySelectorAll('input');
					return i[0].value.trim() + '|' + i[1].value.trim() + '|' + i[2].value.trim();
				}).filter(function (l) { return l.split('|')[0] !== ''; }).join('\n');
			}
			function tierPreview() {
				clearTimeout(tprevTimer);
				tprevTimer = setTimeout(function () {
					api('tiers-preview', { method: 'POST', body: { tiers: tiersToStr() } }).then(function (r) {
						(r.tiers || []).forEach(function (t) {
							var tr = Array.prototype.find.call(tbody.querySelectorAll('tr'), function (row) {
								return row.querySelector('input').value.trim() === t.name;
							});
							if (tr) tr.querySelector('.mk-count').textContent = t.count + ' 👥';
						});
						tchg.innerHTML = (r.changes || []).length
							? '<p style="font-weight:800">При запис ще сменят ставка (' + r.changes.length + '):</p><ul style="margin:4px 0 0 18px;font-size:13px">' +
								r.changes.map(function (c) { return '<li>' + esc(c.name) + ': ' + c.from + '% → <strong>' + c.to + '%</strong> (' + c.sum30 + ' € / 30д)</li>'; }).join('') + '</ul>'
							: '<p class="ap-muted">Никой не сменя ставка при тези прагове.</p>';
					}).catch(function () {});
				}, 450);
			}
			function addTierRow(t) {
				var tr = document.createElement('tr');
				tr.innerHTML = '<td><input class="ap-input" value="' + esc(t.name || '') + '" placeholder="Сребро"></td>' +
					'<td><input class="ap-input" type="number" step="1" value="' + esc(t.min || '') + '" style="width:110px"></td>' +
					'<td><input class="ap-input" type="number" step="0.5" value="' + esc(t.rate || '') + '" style="width:90px"></td>' +
					'<td style="text-align:right"><span class="mk-count ap-muted">—</span></td>' +
					'<td><button class="ap-btn ghost sm mk-tdel" title="Махни">×</button></td>';
				tr.querySelectorAll('input').forEach(function (i) { i.addEventListener('input', tierPreview); });
				tr.querySelector('.mk-tdel').addEventListener('click', function () { tr.remove(); tierPreview(); });
				tbody.appendChild(tr);
			}
			(tiers.length ? tiers : [{ name: 'Бронз', min: '0', rate: '10' }]).forEach(addTierRow);
			document.getElementById('mk-tadd').addEventListener('click', function () { addTierRow({}); });
			tierPreview();

			/* ---- Бранд preview (същата математика като PHP shade) ---- */
			function shade(hex, pct) {
				hex = hex.replace('#', '');
				if (hex.length === 3) hex = hex.replace(/(.)/g, '$1$1');
				var mix = pct >= 0 ? 255 : 0, p = Math.abs(pct), out = '#';
				for (var k = 0; k < 6; k += 2) {
					var c = parseInt(hex.substr(k, 2), 16);
					out += ('0' + Math.round(c + (mix - c) * p).toString(16)).slice(-2);
				}
				return out;
			}
			function brandPreview() {
				var a = document.getElementById('mk-baccent').value, a2 = document.getElementById('mk-baccent2').value;
				var name = document.getElementById('mk-bname').value || 'бранд';
				var logo = document.getElementById('mk-blogo').value;
				var pal = { c: a, deep: shade(a, -0.14), soft: shade(a, 0.78), blush: shade(a, 0.88), amber: a2 };
				document.getElementById('mk-swatches').innerHTML = [pal.c, pal.deep, pal.soft, pal.blush, pal.amber].map(function (c) {
					return '<span title="' + c + '" style="width:34px;height:34px;border-radius:10px;background:' + c + ';border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.12)"></span>';
				}).join('');
				document.getElementById('mk-bpreview').innerHTML =
					'<div style="background:' + pal.blush + ';border-radius:18px;padding:16px;font-family:Montserrat,sans-serif">' +
					'<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">' +
					(logo ? '<img src="' + esc(logo) + '" style="height:26px;object-fit:contain" onerror="this.style.display=\'none\'">' : '<strong style="color:' + pal.deep + '">' + esc(name) + '</strong>') +
					'<span style="font-size:11px;color:#8a7a74">Партньорски портал</span></div>' +
					'<div style="background:linear-gradient(100deg,' + pal.c + ',' + pal.amber + ');color:#fff;border-radius:12px;padding:10px 12px;font-size:12px;font-weight:700;margin-bottom:10px">🎉 Кампания: примерен банер</div>' +
					'<div style="background:#fff;border-radius:14px;padding:12px;margin-bottom:10px"><div style="font-size:10px;font-weight:800;color:#8a7a74;text-transform:uppercase">За изплащане</div><div style="font-size:20px;font-weight:800;color:' + pal.deep + '">124,50 €</div></div>' +
					'<button style="background:' + pal.c + ';color:#fff;border:0;border-radius:999px;padding:9px 20px;font-weight:800;font-size:13px">Копирай линка</button>' +
					'</div>';
			}
			['mk-baccent', 'mk-baccent2', 'mk-bname', 'mk-blogo'].forEach(function (id) {
				document.getElementById(id).addEventListener('input', brandPreview);
			});
			['mk-baccent', 'mk-baccent2'].forEach(function (id) {
				var pick = document.getElementById(id), hx = document.getElementById(id + '-hex');
				pick.addEventListener('input', function () { hx.value = pick.value; });
				hx.addEventListener('input', function () { if (/^#[0-9a-fA-F]{6}$/.test(hx.value)) { pick.value = hx.value; brandPreview(); } });
			});
			brandPreview();

			/* ---- Кит preview ---- */
			var sfx = (m.brand_code_suffix || 'PROOF').toUpperCase();
			var SAMPLE = { code: 'JOANA' + sfx, discount: '10%', link: (BOOT.home || '') + '/p/JOANA' + sfx, name: 'Йоана', brand: m.brand_name || BOOT.site || 'бранда' };
			function fill(t) { return (t || '').replace(/\{(code|discount|link|name|brand)\}/g, function (_, k) { return SAMPLE[k]; }); }
			function kitPreview() {
				document.getElementById('mk-preview').innerHTML =
					'<p class="ap-muted" style="font-size:12.5px;margin-top:6px"><strong>Как ще го види Йоана:</strong><br>Story: ' + esc(fill(document.getElementById('mk-story').value)) + '<br>Пост: ' + esc(fill(document.getElementById('mk-post').value)) + '</p>';
			}
			['mk-story', 'mk-post'].forEach(function (id) { document.getElementById(id).addEventListener('input', kitPreview); });
			kitPreview();

			/* ---- Миграция ---- */
			function annStatus() {
				api('migration/status').then(function (st) {
					if (!st || !st.queued) return;
					var el = document.getElementById('mk-ann-status');
					if (el) el.textContent = 'Кампания от ' + (st.started || '') + ': ' + (st.sent || 0) + '/' + st.queued + ' изпратени' + (st.failed ? ' · ' + st.failed + ' провалени' : '');
					if ((st.sent || 0) + (st.failed || 0) < st.queued) setTimeout(annStatus, 6000);
				}).catch(function () {});
			}
			annStatus();
			document.getElementById('mk-ann-test').addEventListener('click', function () {
				var b = this; b.disabled = true;
				api('migration/announce', { method: 'POST', body: { test: 1, body: document.getElementById('mk-announce').value } })
					.then(function (r) { b.disabled = false; toast('Тестът тръгна към ' + r.email + ' — провери пощата си'); })
					.catch(function (e) { b.disabled = false; toast(e.message, true); });
			});
			document.getElementById('mk-ann-send').addEventListener('click', function () {
				if (!confirm('Изпращаш анонса до ВСИЧКИ активни партньори (батчирано, 1/4 сек). Пусна ли първо теста върху себе си?')) return;
				var b = this; b.disabled = true;
				api('migration/announce', { method: 'POST', body: { body: document.getElementById('mk-announce').value } })
					.then(function (r) { toast(r.queued + ' писма на опашката'); annStatus(); })
					.catch(function (e) { b.disabled = false; toast(e.message, true); });
			});

			/* ---- Telegram тест ---- */
			document.getElementById('mk-tgtest').addEventListener('click', function () {
				var b = this; b.disabled = true;
				api('telegram-test', { method: 'POST' }).then(function () { b.disabled = false; toast('Провери Telegram — трябва да е там ✓'); })
					.catch(function (e) { b.disabled = false; toast(e.message, true); });
			});

			/* ---- Запази ---- */
			document.getElementById('mk-save').addEventListener('click', function () {
				var btn = this; btn.disabled = true;
				api('marketing', { method: 'POST', body: {
					kit_campaign: document.getElementById('mk-campaign').value,
					kit_campaign_from: document.getElementById('mk-cfrom').value,
					kit_campaign_to: document.getElementById('mk-cto').value,
					fixed_msg_behind: document.getElementById('mk-fxb').value,
					fixed_msg_ahead: document.getElementById('mk-fxa').value,
					fixed_wish_mid: document.getElementById('mk-fxwm').value, fixed_wish_end: document.getElementById('mk-fxwe').value, fixed_wish_next: document.getElementById('mk-fxwn').value,
					kit_story: document.getElementById('mk-story').value,
					kit_post: document.getElementById('mk-post').value,
					tiers_enabled: document.getElementById('mk-tiers-on').checked ? 1 : 0,
					tiers: tiersToStr(),
					brand_name: document.getElementById('mk-bname').value,
					brand_company: document.getElementById('mk-bco').value,
					brand_company_id: document.getElementById('mk-beik').value,
					brand_company_address: document.getElementById('mk-baddr').value,
					brand_accent: document.getElementById('mk-baccent').value,
					brand_accent2: document.getElementById('mk-baccent2').value,
					brand_logo_url: document.getElementById('mk-blogo').value,
					brand_code_suffix: document.getElementById('mk-bsuffix').value,
					tg_token: document.getElementById('mk-tgtoken').value,
					tg_chat: document.getElementById('mk-tgchat').value,
					statement_offset: document.getElementById('mk-stoffset').value,
					legacy_link_prefixes: document.getElementById('mk-legacy').value,
					legacy_portal_slug: document.getElementById('mk-oldslug').value
				} }).then(function () { btn.disabled = false; toast('Запазено — на живо е'); })
					.catch(function (e) { btn.disabled = false; toast(e.message, true); });
			});
		}).catch(function (e) { toast(e.message, true); });
	}


	/* ---------------- Proof: пакети + подновявания (1.0.0) ---------------- */
	/* ═══════════ Правила по оферти (2.0) ═══════════ */
	var OF_COMM = { rate: 'по ставката на партньора', pct: 'процент от офертата', fixed: 'фиксирана сума за 1 бр.', none: 'без комисиона' };
	var OF_DISC = { coupon: 'по купона', pct: 'процент', fixed: 'фиксирана сума за 1 бр.', none: 'без отстъпка' };
	var OF_REN = { inherit: 'както глобалното', none: 'никога', first_n: 'първите N подновявания', forever: 'винаги' };
	var OF_RENC = { same: 'както първата поръчка', pct: 'процент от подновяването', fixed: 'фиксирана сума за 1 бр.' };
	var OF_APPLIES = { any: '1× и 🔁', onetime: 'само 1×', sub: 'само 🔁' };
	function ofEmptyRule() { return { comm: { mode: 'rate', value: 0 }, disc: { mode: 'coupon', value: 0 }, ren: { mode: 'inherit', n: 3, cmode: 'same', value: 0 } }; }
	function ofNum(v) { return String(Math.round((Number(v) || 0) * 100) / 100).replace('.', ','); }
	function ofCommTxt(r, rate) { var c = r.comm || {}; return c.mode === 'fixed' ? 'фикс ' + ofNum(c.value) + ' € / бр.' : c.mode === 'pct' ? ofNum(c.value) + '% от офертата' : c.mode === 'none' ? 'без комисиона' : 'по ставката' + (rate != null ? ' (' + ofNum(rate) + '%)' : ''); }
	function ofDiscTxt(r) { var d = r.disc || {}; return d.mode === 'pct' ? '−' + ofNum(d.value) + '%' : d.mode === 'fixed' ? '−' + ofNum(d.value) + ' € / бр.' : d.mode === 'none' ? 'без отстъпка' : 'по купона'; }
	function ofRenTxt(r) { var n = r.ren || {}; if (n.mode === 'inherit' || !n.mode) return 'както глобалното'; if (n.mode === 'none') return 'никога'; var how = n.cmode === 'pct' ? ' × ' + ofNum(n.value) + '%' : n.cmode === 'fixed' ? ' × фикс ' + ofNum(n.value) + ' €' : ' · както 1-вата'; return (n.mode === 'first_n' ? 'първите ' + (n.n || 3) : 'винаги') + how; }
	/* карти: catalog [{scope,label,source,retired,manual,sub_capable}], rules {scope:{applies:rule}}, opts {rate, personal:{scope:{applies:rule}} (в картата на партньора), counts, onEdit(scope, applies)} */
	function offerCards(catalog, rules, opts) {
		opts = opts || {}; var personal = opts.personal || null;
		if (!catalog.length) return '<p class="ap-muted">Няма оферти — Shrine не е активен и няма ръчни оферти.</p>';
		return '<div class="of-grid">' + catalog.map(function (c) {
			var mine = personal ? (personal[c.scope] || null) : null, glob = rules[c.scope] || null;
			var set = mine || glob, own = !!mine, empty = !set || !Object.keys(set).length;
			var badges = '';
			if (set && !empty) Object.keys(set).forEach(function (ap) { badges += '<span class="bd ' + (ap === 'sub' ? 'sub' : ap === 'onetime' ? 'one' : 'both') + '">' + OF_APPLIES[ap] + '</span>'; });
			if (personal) badges += own ? '<span class="bd me">лично</span>' : (glob ? '<span class="bd gl">глобално</span>' : '');
			else if (opts.counts && opts.counts[c.scope]) badges += '<span class="bd me" title="партньори със свое правило">' + opts.counts[c.scope] + ' лични</span>';
			var body = '';
			if (empty) body = '<span class="bd new">без правило → по ставката · по купона</span>';
			else Object.keys(set).forEach(function (ap) { var r = set[ap];
				body += (Object.keys(set).length > 1 ? '<div class="of-ap">' + OF_APPLIES[ap] + '</div>' : '') +
					'<div class="kv"><span>Комисиона</span><b>' + esc(ofCommTxt(r, opts.rate)) + '</b></div><div class="kv"><span>Клиент</span><b>' + esc(ofDiscTxt(r)) + '</b></div>' +
					((ap !== 'onetime' && c.sub_capable !== false) ? '<div class="kv"><span>Подновявания</span><b>🔁 ' + esc(ofRenTxt(r)) + '</b></div>' : '');
			});
			var foot = '';
			if (personal && own && glob) foot = '<span class="ap-muted">глобално: ' + esc(Object.keys(glob).map(function (ap) { return ofCommTxt(glob[ap]) + ' · ' + ofDiscTxt(glob[ap]); }).join(' | ')) + '</span>';
			var firstAp = set && !empty ? Object.keys(set)[0] : 'any';
			return '<div class="of-card' + (c.retired ? ' off' : '') + (own ? ' own' : '') + (empty ? ' empty' : '') + '" data-scope="' + esc(c.scope) + '">' +
				'<div class="hd"><div class="tt"><b>' + esc(c.label) + '</b><div class="sc">' + esc(c.scope) + ' · ' + esc(c.source) + '</div></div><div class="bds">' + badges + '</div></div>' +
				(c.retired ? '<span class="bd off">спряна · правилото се пази за историята</span>' : '') +
				'<div class="body">' + body + '</div>' +
				'<div class="ft">' + foot + '<span class="sp"></span>' + (c.retired && empty ? '' : '<button type="button" class="ap-btn sm' + (empty ? '' : ' ghost') + '" data-ofedit="' + esc(c.scope) + '" data-ap="' + firstAp + '">' + (empty ? 'Задай правило' : (own || !personal ? 'Промени' : 'Задай лично')) + '</button>') + '</div></div>';
		}).join('') + '</div>';
	}
	/* модал за едно правило. o: {label, scope, applies, rule, rate, globalHint, personal, onSave(applies, rule, deleteOthers), onDelete(applies)} */
	function offerModal(o) {
		var r = JSON.parse(JSON.stringify(o.rule || ofEmptyRule())); var applies = o.applies || 'any';
		var ov = document.createElement('div'); ov.className = 'ap-overlay';
		function sel(id, map, cur) { return '<select class="ap-select" id="' + id + '">' + Object.keys(map).map(function (k) { return '<option value="' + k + '"' + (k === cur ? ' selected' : '') + '>' + map[k] + '</option>'; }).join('') + '</select>'; }
		ov.innerHTML = '<div class="ap-modal of-modal" role="dialog" aria-modal="true" style="width:560px">' +
			'<h2 style="margin-bottom:2px">' + esc(o.label) + (o.personal ? ' <span class="bd me">лично за партньора</span>' : '') + '</h2><p class="ap-muted" style="margin:0 0 14px">' + esc(o.scope) + (o.globalHint ? ' · глобално: ' + esc(o.globalHint) : '') + '</p>' +
			'<div class="pp-grp"><b>Важи за</b><div class="seg" id="of-ap">' + Object.keys(OF_APPLIES).map(function (k) { return '<button type="button" data-ap="' + k + '" class="' + (k === applies ? 'on' : '') + '">' + OF_APPLIES[k] + '</button>'; }).join('') + '</div><p class="ap-hint" style="margin-top:8px">„само 🔁" = отделно правило за абонаментната версия на офертата; ако липсва, важи общото „1× и 🔁".</p></div>' +
			'<div class="pp-grp"><b>Комисиона за партньора</b><div class="ap-row2 of-r"><div class="ap-field"><label>Начин</label>' + sel('of-cm', OF_COMM, r.comm.mode) + '</div><div class="ap-field"><label>Стойност</label><input class="ap-input" id="of-cv" type="number" step="0.01" min="0" value="' + esc(r.comm.value) + '"></div></div></div>' +
			'<div class="pp-grp"><b>Отстъпка за клиента върху офертата</b><div class="ap-row2 of-r"><div class="ap-field"><label>Начин</label>' + sel('of-dm', OF_DISC, r.disc.mode) + '</div><div class="ap-field"><label>Стойност</label><input class="ap-input" id="of-dv" type="number" step="0.01" min="0" value="' + esc(r.disc.value) + '"></div></div><p class="ap-hint" style="margin-top:8px">Купони „фиксирана сума за количката" — само „по купона" или „без". Останалите редове в поръчката са по купона.</p></div>' +
			'<div class="pp-grp" id="of-reng"><b>Подновявания (само 🔁)</b><div class="ap-row2 of-r"><div class="ap-field"><label>Комисиона при подновяване</label>' + sel('of-rm', OF_REN, r.ren.mode) + '</div><div class="ap-field"><label>N</label><input class="ap-input" id="of-rn" type="number" min="1" max="120" value="' + esc(r.ren.n || 3) + '"></div></div>' +
			'<div class="ap-row2 of-r" style="margin-top:10px"><div class="ap-field"><label>Начин</label>' + sel('of-rc', OF_RENC, r.ren.cmode) + '</div><div class="ap-field"><label>Стойност</label><input class="ap-input" id="of-rv" type="number" step="0.01" min="0" value="' + esc(r.ren.value) + '"></div></div></div>' +
			'<div class="of-ex" id="of-ex"></div>' +
			'<div class="ap-actions">' + (o.onDelete ? '<button type="button" class="ap-btn ghost sm" id="of-del">Изтрий правилото</button>' : '') + '<span class="sp"></span><button type="button" class="ap-btn ghost" id="of-cancel">Отказ</button><button type="button" class="ap-btn" id="of-save">Запази</button></div></div>';
		document.body.appendChild(ov);
		var $ = function (id) { return ov.querySelector('#' + id); };
		function read() {
			r.comm = { mode: $('of-cm').value, value: +$('of-cv').value || 0 }; r.disc = { mode: $('of-dm').value, value: +$('of-dv').value || 0 };
			r.ren = { mode: $('of-rm').value, n: +$('of-rn').value || 3, cmode: $('of-rc').value, value: +$('of-rv').value || 0 };
		}
		function sync() {
			read();
			$('of-cv').disabled = r.comm.mode === 'rate' || r.comm.mode === 'none'; $('of-dv').disabled = r.disc.mode === 'coupon' || r.disc.mode === 'none';
			$('of-rn').disabled = r.ren.mode !== 'first_n'; $('of-rc').disabled = r.ren.mode === 'inherit' || r.ren.mode === 'none'; $('of-rv').disabled = $('of-rc').disabled || r.ren.cmode === 'same';
			$('of-reng').classList.toggle('dim', applies === 'onetime');
			/* жив пример */
			var rate = +o.rate || 10, off = 89, rest = 24.9, cdisc = 10;
			var offPaid = r.disc.mode === 'pct' ? off * (1 - r.disc.value / 100) : r.disc.mode === 'fixed' ? Math.max(0, off - r.disc.value) : r.disc.mode === 'none' ? off : off * (1 - cdisc / 100);
			var restPaid = rest * (1 - cdisc / 100);
			var cm = r.comm.mode === 'fixed' ? r.comm.value : r.comm.mode === 'pct' ? offPaid * r.comm.value / 100 : r.comm.mode === 'none' ? 0 : offPaid * rate / 100;
			var cr = restPaid * rate / 100;
			var ren = '';
			if (applies !== 'onetime') {
				if (r.ren.mode === 'none') ren = 'подновяване: без комисиона';
				else if (r.ren.mode === 'inherit') ren = 'подновяване: по глобалната настройка';
				else { var ra = r.ren.cmode === 'pct' ? offPaid * r.ren.value / 100 : r.ren.cmode === 'fixed' ? r.ren.value : cm; ren = (r.ren.mode === 'first_n' ? 'първите ' + r.ren.n + ' подновявания' : 'всяко подновяване') + ': <b>' + ofNum(ra) + ' €</b>'; }
			}
			$('of-ex').innerHTML = '<b>Пример</b> · код с ' + ofNum(rate) + '% / −' + cdisc + '%, оферта 89,00 € + друг продукт 24,90 €<br>Клиент плаща: оферта <b>' + ofNum(offPaid) + '</b> · друго <b>' + ofNum(restPaid) + '</b><br>Партньор: <b>' + ofNum(cm) + ' + ' + ofNum(cr) + ' = ' + ofNum(cm + cr) + ' €</b>' + (ren ? '<br>' + ren : '');
		}
		ov.querySelectorAll('select, input').forEach(function (el) { el.addEventListener('change', sync); el.addEventListener('input', sync); });
		ov.querySelectorAll('#of-ap button').forEach(function (b) { b.addEventListener('click', function () { applies = b.dataset.ap; ov.querySelectorAll('#of-ap button').forEach(function (x) { x.classList.toggle('on', x === b); }); sync(); }); });
		function close() { ov.remove(); document.removeEventListener('keydown', onKey); }
		function onKey(e) { if (e.key === 'Escape') close(); }
		document.addEventListener('keydown', onKey);
		$('of-cancel').addEventListener('click', close); ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
		$('of-save').addEventListener('click', function () { read(); if ((r.comm.mode === 'pct' || r.comm.mode === 'fixed') && !(r.comm.value > 0)) { toast('Въведи стойност за комисионата.', true); return; } if ((r.disc.mode === 'pct' || r.disc.mode === 'fixed') && !(r.disc.value > 0)) { toast('Въведи стойност за отстъпката.', true); return; } o.onSave(applies, r, o.applies && o.applies !== applies ? o.applies : null); close(); });
		if (o.onDelete) $('of-del').addEventListener('click', function () { if (!confirm('Изтриваш правилото за ' + o.label + ' (' + OF_APPLIES[o.applies || 'any'] + ')? Старите поръчки не се променят.')) return; o.onDelete(o.applies || 'any'); close(); });
		sync();
	}
	/* модал „ръчна оферта": име + продукти/категории */
	function manualOfferModal(existing, onSave) {
		var ov = document.createElement('div'); ov.className = 'ap-overlay'; var ids = (existing && existing.match ? existing.match.ids : []).slice(); var names = {};
		ov.innerHTML = '<div class="ap-modal" role="dialog" style="width:560px"><h2>' + (existing ? 'Ръчна оферта' : 'Нова ръчна оферта') + '</h2>' +
			'<div class="ap-row2"><div class="ap-field"><label>Име</label><input class="ap-input" id="mo-label" value="' + esc(existing ? existing.label : '') + '" placeholder="напр. Аксесоари"></div><div class="ap-field"><label>Тип</label><select class="ap-select" id="mo-type"><option value="product"' + (existing && existing.match && existing.match.type === 'product' ? ' selected' : '') + '>продукти</option><option value="cat"' + (existing && existing.match && existing.match.type === 'cat' ? ' selected' : '') + '>категории</option></select></div></div>' +
			'<div class="ap-field" style="margin-top:12px"><label>Търси</label><input class="ap-input" id="mo-q" placeholder="пиши поне 2 букви…" autocomplete="off"></div><div class="ap-cm-list" id="mo-list" style="margin-top:8px"><p class="ap-muted" style="margin:6px 0">Резултатите се показват тук.</p></div>' +
			'<div id="mo-sel" style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap"></div>' +
			'<div class="ap-actions"><span class="sp"></span><button type="button" class="ap-btn ghost" id="mo-cancel">Отказ</button><button type="button" class="ap-btn" id="mo-save">Запази</button></div></div>';
		document.body.appendChild(ov);
		var $ = function (id) { return ov.querySelector('#' + id); };
		function drawSel() { $('mo-sel').innerHTML = ids.length ? ids.map(function (id) { return '<span class="ap-chip approved">' + esc(names[id] || ('#' + id)) + ' <button type="button" class="ap-icobtn" data-rm="' + id + '" style="width:18px;height:18px;font-size:10px">×</button></span>'; }).join('') : '<span class="ap-muted">нищо избрано</span>'; ov.querySelectorAll('[data-rm]').forEach(function (b) { b.addEventListener('click', function () { ids = ids.filter(function (x) { return x !== +b.dataset.rm; }); drawSel(); }); }); }
		var t; $('mo-q').addEventListener('input', function () { clearTimeout(t); var q = $('mo-q').value.trim(); if (q.length < 2) return; t = setTimeout(function () { api('proof/offers/products?q=' + encodeURIComponent(q)).then(function (res) { var list = $('mo-type').value === 'cat' ? res.cats : res.products; $('mo-list').innerHTML = list.length ? list.map(function (x) { return '<button type="button" class="ap-cm-row" data-id="' + x.id + '" data-name="' + esc(x.name) + '"><b>' + esc(x.name) + '</b><span>#' + x.id + '</span></button>'; }).join('') : '<p class="ap-muted" style="margin:6px 0">Няма резултат.</p>'; ov.querySelectorAll('[data-id]').forEach(function (b) { b.addEventListener('click', function () { var id = +b.dataset.id; names[id] = b.dataset.name; if (ids.indexOf(id) < 0) ids.push(id); drawSel(); }); }); }); }, 250); });
		$('mo-cancel').addEventListener('click', function () { ov.remove(); }); ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
		$('mo-save').addEventListener('click', function () { var label = $('mo-label').value.trim(); if (!label) { toast('Дай име на офертата.', true); return; } if (!ids.length) { toast('Избери поне един продукт / категория.', true); return; } onSave({ scope: existing ? existing.scope : '', label: label, type: $('mo-type').value, ids: ids }); ov.remove(); });
		drawSel();
	}
	/* екран „Оферти" (глобално) — вмъква се в viewProof */
	function offersPanel(host) {
		api('proof/offers').then(function (d) {
			var rules = d.rules || {}; var cat = d.catalog || []; var filter = 'all';
			function draw() {
				var list = cat.filter(function (c) { if (filter === 'sub') return c.sub_capable !== false && !c.retired; if (filter === 'off') return c.retired; if (filter === 'set') return rules[c.scope] && Object.keys(rules[c.scope]).length; return !c.retired || (rules[c.scope] && Object.keys(rules[c.scope]).length); });
				var nOff = cat.filter(function (c) { return c.retired; }).length, nSet = cat.filter(function (c) { return rules[c.scope] && Object.keys(rules[c.scope]).length; }).length;
				host.innerHTML = '<div class="of-tb"><div class="seg">' + [['all', 'Всички ' + cat.filter(function (c) { return !c.retired; }).length], ['set', 'С правило ' + nSet], ['off', 'Спрени ' + nOff]].map(function (x) { return '<button type="button" data-f="' + x[0] + '" class="' + (filter === x[0] ? 'on' : '') + '">' + x[1] + '</button>'; }).join('') + '</div><span class="sp"></span><button type="button" class="ap-btn sm ghost" id="of-manual">＋ ръчна оферта</button></div>' +
					offerCards(list, rules, { counts: d.partner_counts || {} }) +
					'<p class="ap-hint" style="margin-top:12px">Извън офертите = ставката на партньора и отстъпката на купона. Правилото се замразява в поръчката при checkout — промяна тук важи само за нови поръчки. Подновявания „както глобалното" = настройката „Подновявания на абонаменти" по-долу.</p>';
				host.querySelectorAll('.seg button').forEach(function (b) { b.addEventListener('click', function () { filter = b.dataset.f; draw(); }); });
				host.querySelectorAll('[data-ofedit]').forEach(function (b) { b.addEventListener('click', function () { var scope = b.dataset.ofedit, ap = b.dataset.ap, c = cat.filter(function (x) { return x.scope === scope; })[0]; var has = rules[scope] && rules[scope][ap];
					offerModal({ label: c.label, scope: scope, applies: has ? ap : 'any', rule: has ? rules[scope][ap] : null, rate: null,
						onSave: function (newAp, rule, oldAp) { rules[scope] = rules[scope] || {}; if (oldAp && oldAp !== newAp) delete rules[scope][oldAp]; rules[scope][newAp] = rule; save(); },
						onDelete: has ? function (delAp) { delete rules[scope][delAp]; if (!Object.keys(rules[scope]).length) delete rules[scope]; save(); } : null }); }); });
				var mb = host.querySelector('#of-manual'); if (mb) mb.addEventListener('click', function () { manualOfferModal(null, function (m) { var manual = {}; cat.forEach(function (c) { if (c.manual) manual[c.scope] = { label: c.label, type: c.match.type, ids: c.match.ids, retired: c.retired ? 1 : 0 }; }); var key = 'man:' + m.label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40); if (!key.replace('man:', '')) key = 'man:offer-' + Date.now().toString(36); manual[key] = { label: m.label, type: m.type, ids: m.ids }; api('proof/offers', { method: 'POST', body: { manual: manual } }).then(function (nd) { cat = nd.catalog || []; rules = nd.rules || {}; toast('Офертата е добавена'); draw(); }).catch(function (e) { toast(e.message, true); }); }); });
			}
			function save() { api('proof/offers', { method: 'POST', body: { rules: rules } }).then(function (nd) { rules = nd.rules || {}; cat = nd.catalog || []; toast('Запазено — важи за нови поръчки'); draw(); }).catch(function (e) { toast(e.message, true); }); }
			draw();
		}).catch(function (e) { host.innerHTML = '<p class="ap-muted" style="color:var(--bad)">Офертите не се заредиха: ' + esc(e.message) + '</p>'; });
	}
	/* секция „Оферти" в картата на партньора */
	function offersPartnerPanel(id, host) {
		api('proof/offers/partner/' + id).then(function (d) {
			var rules = d.rules || {}, glob = d.global || {}, cat = d.catalog || [];
			function draw() {
				host.innerHTML = offerCards(cat, glob, { personal: rules, rate: d.rate }) + '<p class="ap-hint" style="margin-top:12px">Сините карти са личните правила на партньора и бият глобалните. Промяната важи за нови поръчки (замразява се при checkout).</p>';
				host.querySelectorAll('[data-ofedit]').forEach(function (b) { b.addEventListener('click', function () { var scope = b.dataset.ofedit, ap = b.dataset.ap, c = cat.filter(function (x) { return x.scope === scope; })[0]; var mine = rules[scope] && rules[scope][ap]; var g = glob[scope] && (glob[scope][ap] || glob[scope].any);
					offerModal({ label: c.label, scope: scope, applies: mine ? ap : (g ? (glob[scope][ap] ? ap : 'any') : 'any'), rule: mine || g || null, rate: d.rate, personal: true, globalHint: g ? ofCommTxt(g) + ' · ' + ofDiscTxt(g) : 'няма',
						onSave: function (newAp, rule, oldAp) { rules[scope] = rules[scope] || {}; if (oldAp && oldAp !== newAp) delete rules[scope][oldAp]; rules[scope][newAp] = rule; save(); },
						onDelete: mine ? function (delAp) { delete rules[scope][delAp]; if (!Object.keys(rules[scope]).length) delete rules[scope]; save(); } : null }); }); });
			}
			function save() { api('proof/offers/partner/' + id, { method: 'POST', body: { rules: rules } }).then(function (nd) { rules = nd.rules || {}; toast('Запазено — важи за нови поръчки'); draw(); }).catch(function (e) { toast(e.message, true); }); }
			draw();
		}).catch(function (e) { host.innerHTML = '<p class="ap-muted">' + esc(e.message) + '</p>'; });
	}
	/* таб „По оферти" в Комисиони */
	function offersLedger(host, qs) {
		var scope = qs.scope || '', pid = qs.partner || '', pg = Math.max(1, parseInt(qs.pg || '1', 10) || 1);
		api('proof/offers/ledger?page=' + pg + (scope ? '&scope=' + encodeURIComponent(scope) : '') + (pid ? '&partner_id=' + encodeURIComponent(pid) : '')).then(function (d) {
			var tiles = (d.totals || []).map(function (t) { return '<a href="#/commissions?status=offers' + (pid ? '&partner=' + pid : '') + (scope === t.scope ? '' : '&scope=' + encodeURIComponent(t.scope)) + '" class="of-tile' + (scope === t.scope ? ' on' : '') + '"><div class="l">' + esc(t.label) + '</div><div class="v">' + t.orders + ' <small>поръчки</small></div><div class="s">комисиона <b>' + money(t.amount) + '</b> · база ' + money(t.base) + (t.subs || t.renewals ? ' · 🔁 ' + (t.subs + t.renewals) : '') + '</div></a>'; }).join('');
			var rows = (d.rows || []).map(function (r) {
				return '<tr><td>' + dt(r.created_at) + '</td><td style="white-space:nowrap"><a href="#/partners/' + r.partner_id + '"><strong>' + esc(r.partner) + '</strong></a></td><td style="white-space:nowrap"><strong>#' + r.order_id + '</strong> <code class="ap-code">' + esc((r.coupon_code || '').toUpperCase()) + '</code></td>' +
					'<td><span class="split">' + r.items.map(function (i) { return '<span class="' + (i.scope === '_regular' ? '' : i.kind === 'renewal' ? 's' : 'e') + '" title="' + esc(i.source) + '">' + esc(i.label) + (i.kind === 'sub' ? ' 🔁' : i.kind === 'renewal' ? ' 🔁 №' + i.renewal_n : '') + ' · ' + esc(i.text) + (i.source === 'partner' ? ' <i>(лично)</i>' : '') + '</span>'; }).join('') + '</span></td>' +
					'<td class="num ' + (r.status === 'approved' ? 'pos' : r.status === 'paid' ? 'paid' : '') + '">' + money(r.amount, r.currency) + '</td><td>' + chip(r.status) + '</td></tr>';
			}).join('');
			host.innerHTML = '<div style="padding:12px 18px 4px"><div class="of-tiles">' + (tiles || '<p class="ap-muted">Още няма разбивки — появяват се с първите комисиони след 2.0.</p>') + '</div>' + (scope ? '<p style="margin:8px 0 0"><a href="#/commissions?status=offers' + (pid ? '&partner=' + pid : '') + '" class="ap-btn sm ghost">× само ' + esc(scope) + '</a></p>' : '') + '</div>' +
				'<table class="ap-table"><thead><tr><th>Дата</th><th>Партньор</th><th>Поръчка</th><th>Разбивка по оферти</th><th style="text-align:right">Сума</th><th>Статус</th></tr></thead><tbody>' + (rows || '<tr><td colspan="6" class="empty">Няма редове.</td></tr>') + '</tbody></table>' +
				'<div class="pp-pager" style="padding:0 18px 14px;border:0"><span class="ap-muted">' + d.total + ' комисиони · страница ' + d.page + '/' + d.pages + '</span><span class="sp"></span>' + (d.page > 1 ? '<a class="ap-btn ghost sm" href="#/commissions?status=offers&pg=' + (d.page - 1) + (scope ? '&scope=' + encodeURIComponent(scope) : '') + (pid ? '&partner=' + pid : '') + '">‹ Предишни</a>' : '') + (d.page < d.pages ? ' <a class="ap-btn ghost sm" href="#/commissions?status=offers&pg=' + (d.page + 1) + (scope ? '&scope=' + encodeURIComponent(scope) : '') + (pid ? '&partner=' + pid : '') + '">Следващи ›</a>' : '') + '</div>';
		}).catch(function (e) { host.innerHTML = '<p class="ap-muted" style="padding:16px;color:var(--bad)">' + esc(e.message) + '</p>'; });
	}

	function viewProof() {
		loading('Proof правила');
		api('proof/settings').then(function (m) {
			var html = '<div class="ap-panel"><h2>Правила по оферти<span class="sp"></span><span class="ap-muted" style="font-weight:600;text-transform:none;letter-spacing:0">един код · различни условия по оферта · 1× и 🔁</span></h2><div id="of-host" style="padding:0 18px 16px"><p class="ap-muted">Зареждане…</p></div></div>';
			var wcs = m.wc_statuses || [], won = m.won_statuses || [];
			html += '<div class="ap-panel"><h2>Кои статуси са „спечелена поръчка"<span class="sp"></span><span class="ap-muted" style="font-weight:600;text-transform:none;letter-spacing:0">от WooCommerce, вкл. custom статусите на куриерските плъгини</span></h2><div style="padding:0 18px 16px">' +
				'<div class="ap-stgrid">' + wcs.map(function (st) {
					var on = won.indexOf(st.key) >= 0;
					return '<label class="ap-st' + (on ? ' on' : '') + (st.dead ? ' dead' : '') + '"><input type="checkbox" class="pr-won" value="' + esc(st.key) + '"' + (on ? ' checked' : '') + (st.dead ? ' disabled' : '') + '><span class="k">' + esc(st.label) + '</span><code>' + esc(st.key) + '</code>' + (st.dead ? '<span class="ap-muted">= връщане</span>' : '') + '</label>';
				}).join('') + '</div>' +
				'<p class="ap-hint" style="margin-top:12px">Поръчка в избран статус носи комисиона (одобрява се след периода на задържане). Останалите статуси са „по пътя" — изчакваща. Отказана / върната / неуспешна винаги е сторно. Промяната важи веднага и за фоновата проверка.</p>' +
				'</div></div>';
			html += '<div class="ap-panel"><h2>Подновявания на абонаменти (Sublium)<span class="sp"></span></h2><div style="padding:0 18px 16px"><div class="ap-row2">' +
				'<div class="ap-field"><label>Комисиона върху подновяванията</label><select class="ap-input" id="pr-rmode"><option value="none"' + (m.renewal_mode === 'none' ? ' selected' : '') + '>Никога — само първата поръчка</option><option value="first_n"' + (m.renewal_mode === 'first_n' ? ' selected' : '') + '>Първите N подновявания</option><option value="forever"' + (m.renewal_mode === 'forever' ? ' selected' : '') + '>Всички, докато абонаментът е активен</option></select></div>' +
				'<div class="ap-field"><label>N (за „първите N")</label><input class="ap-input" id="pr-rn" type="number" min="1" max="120" value="' + esc(m.renewal_n || 3) + '"></div></div>' +
				'<div class="ap-field"><label>Ставка за подновяване, % (празно = същите правила като първата поръчка)</label><input class="ap-input" id="pr-rrate" type="number" step="0.1" min="0" max="100" value="' + esc(m.renewal_rate || '') + '" style="max-width:160px"></div>' +
				'<p class="ap-muted">Подновяването няма купон при checkout — партньорът се взима от lock-а на родителската поръчка на същия абонамент (_sublium_subscription_id). Еднократните поръчки са винаги само веднъж. Броенето „№k" гледа колко по-ранни подновявания на абонамента вече са с комисиона.</p>' +
				'</div></div>' +
				'<div class="ap-actions"><button class="ap-btn" id="pr-save">Запази</button><span class="ap-hint">важи за поръчки от този момент (lock при checkout)</span></div>';
			$view.innerHTML = html;
			document.querySelectorAll('.pr-won').forEach(function (c) { c.addEventListener('change', function () { c.closest('.ap-st').classList.toggle('on', c.checked); }); });
			offersPanel(document.getElementById('of-host'));
			document.getElementById('pr-save').addEventListener('click', function () {
				var btn = this; btn.disabled = true;
				var wonSel = Array.prototype.map.call(document.querySelectorAll('.pr-won:checked'), function (c) { return c.value; });
				if (!wonSel.length) { btn.disabled = false; toast('Избери поне един „спечелен" статус.', true); return; }
				api('proof/settings', { method: 'POST', body: { won_statuses: wonSel, renewal_mode: document.getElementById('pr-rmode').value, renewal_n: document.getElementById('pr-rn').value, renewal_rate: document.getElementById('pr-rrate').value } })
					.then(function () { btn.disabled = false; toast('Запазено — на живо е'); }).catch(function (e) { btn.disabled = false; toast(e.message, true); });
			});
		}).catch(function (e) { toast(e.message, true); });
	}

	/* ---------------- Proof: импорт на съществуващи купони (Advanced Coupons) ---------------- */
	function viewImport() {
		loading('Импорт на купони');
		function load(q) {
			return api('proof/import/coupons?history=1' + (q ? '&q=' + encodeURIComponent(q) : '')).then(function (d) {
				var rows = (d.coupons || []).map(function (c) {
					var disc = c.type === 'percent' ? c.amount + '%' : (c.type === 'fixed_cart' ? c.amount + ' € (кошница)' : c.type + ' ' + c.amount);
					return '<tr data-id="' + c.id + '"' + (c.expired ? ' style="opacity:.55"' : '') + '><td><b>' + esc(c.code) + '</b>' + (c.acfw ? ' <span class="ap-muted" title="Advanced Coupons: BOGO / cart conditions остават на купона">ACFW</span>' : '') + '<br><span class="ap-muted" style="font-size:11px">' + esc(c.desc || '') + '</span></td>' +
						'<td>' + esc(disc) + (c.products ? '<br><span class="ap-muted" style="font-size:11px">' + c.products + ' продукта</span>' : '') + '</td>' +
						'<td class="num">' + c.usage + (c.limit ? '/' + c.limit : '') + (c.history ? '<br><span class="ap-muted" style="font-weight:600;font-size:11px" title="спечелени поръчки / оборот с този код досега">' + c.history.won + ' пор. · ' + c.history.revenue + ' €</span>' : '') + '</td><td>' + (c.expires ? esc(c.expires) + (c.expired ? ' ⚠' : '') : '—') + '</td>' +
						'<td><input class="ap-input im-name" placeholder="Име на партньора" value="' + esc(c.emails[0] ? '' : '') + '" style="min-width:150px"></td>' +
						'<td><input class="ap-input im-email" type="email" placeholder="email" value="' + esc(c.emails[0] || '') + '" style="min-width:180px"></td>' +
						'<td><input class="ap-input im-rate" type="number" step="0.1" placeholder="%" style="width:70px"></td>' +
						'<td><input class="ap-input im-from" type="date" value="' + new Date().toISOString().slice(0, 10) + '" title="Следи се от — старите поръчки остават история"></td>' +
						'<td><button class="ap-btn sm im-go">Направи партньор</button></td></tr>';
				}).join('');
				$view.innerHTML = '<div class="ap-panel"><h2>Купони без партньор<span class="sp"></span><span class="ap-muted" style="font-weight:600">' + (d.coupons || []).length + ' налични · ' + d.mapped + ' вече партньорски</span></h2><div style="padding:0 18px 16px">' +
					'<div style="display:flex;gap:8px;margin:0 0 12px"><input class="ap-input" id="im-q" placeholder="Търси код / описание…" value="' + esc(q || '') + '" style="max-width:320px"></div>' +
					'<table class="ap-table"><thead><tr><th>Код</th><th>Отстъпка</th><th>Ползван</th><th>Изтича</th><th>Партньор</th><th>Email</th><th>Ставка</th><th>Следи от</th><th></th></tr></thead><tbody>' + (rows || '<tr><td colspan="9" class="empty">Няма неосиновени купони</td></tr>') + '</tbody></table>' +
					'<p class="ap-muted" style="margin-top:10px">Кодът остава СЪЩИЯТ и продължава да работи както досега (Advanced Coupons условията не се пипат). Създава се партньор (или се закача към съществуващ по email), кодът се картира към него, individual_use се сваля (gate-ът пази ексклузивността). Ставка празна = по подразбиране. Email задължителен — това е логинът за портала /partner. „Следи от" = от коя дата поръчките носят комисиона; всичко преди нея се записва като история при партньора (виждаш го в картата му), без пари.</p>' +
					'</div></div>';
				var qi = document.getElementById('im-q'), t;
				qi.addEventListener('input', function () { clearTimeout(t); var v = this.value; t = setTimeout(function () { load(v).then(function () { var e = document.getElementById('im-q'); if (e) { e.focus(); e.setSelectionRange(v.length, v.length); } }); }, 350); });
				document.querySelectorAll('.im-go').forEach(function (b) {
					b.addEventListener('click', function () {
						var tr = this.closest('tr'), name = tr.querySelector('.im-name').value.trim(), email = tr.querySelector('.im-email').value.trim(), rate = tr.querySelector('.im-rate').value;
						if (!email) { toast('Email е задължителен (логинът в портала).', true); tr.querySelector('.im-email').focus(); return; }
						var btn = this; btn.disabled = true;
						api('proof/import/adopt', { method: 'POST', body: { wc_id: +tr.dataset.id, name: name, email: email, rate: rate, tracked_from: tr.querySelector('.im-from').value } }).then(function (r) {
							toast(tr.querySelector('b').textContent + ' → ' + r.partner + ' ✓ следи се от ' + r.tracked_from + (r.history && r.history.won ? ' · история ' + r.history.won + ' пор.' : '')); tr.remove();
						}).catch(function (e) { btn.disabled = false; toast(e.message, true); });
					});
				});
			});
		}
		load('').catch(function (e) { toast(e.message, true); });
	}

	function loadFeed() {
		var box = document.getElementById('ap-feed');
		if (!box) return;
		api('feed?ts=' + Date.now()).then(function (items) {
			if (!document.getElementById('ap-feed')) return;
			var ICO = { click: '👆', order: '🛒', commission: '💰', payout: '↧', partner: '⭐', code: '🏷', tier: '🏆' };
			var frow = function (e) {
				return '<li><span class="t">' + dt(e.t) + '</span><span class="i">' + (ICO[e.type] || '·') + '</span>' + esc(e.msg) + '</li>';
			};
			box.innerHTML = items.length
				? '<ul class="ap-feed">' + items.slice(0, 5).map(frow).join('') + '</ul>' +
					(items.length > 5 ? '<details><summary style="cursor:pointer;font-weight:800;font-size:13px;padding:4px 0;color:var(--muted,#93817a)">още ' + (items.length - 5) + ' събития…</summary><ul class="ap-feed">' + items.slice(5, 20).map(frow).join('') + '</ul></details>' : '')
				: '<p class="ap-muted">Тихо е — сподели някой линк 😉</p>';
		}).catch(function () {});
	}

	function viewPayouts() {
		loading('Изплащания');

		// Месечна каденца: по подразбиране плащаме всичко одобрено до края
		// на ПРЕДИШНИЯ месец (в началото на юли се плаща юни).
		var MONTHS_BG = ['януари','февруари','март','април','май','юни','юли','август','септември','октомври','ноември','декември'];
		var now = new Date();
		var sel = viewPayouts._sel || { y: now.getFullYear(), m: now.getMonth() - 1 };
		if (sel.m < 0) { sel.m += 12; sel.y -= 1; }
		viewPayouts._sel = sel;
		function cutoff() { return new Date(Date.UTC(sel.y, sel.m + 1, 0)).toISOString().slice(0, 10); } // последен ден на месеца
		function periodLabel() { return 'Период: ' + MONTHS_BG[sel.m] + ' ' + sel.y + ' (до ' + cutoff().split('-').reverse().join('.') + ')'; }

		Promise.all([ api('payables?until=' + cutoff()), api('payouts') ]).then(function (res) {
			var payables = res[0], payouts = res[1];

			// Селектор: последните 12 месеца
			var opts = '';
			for (var i = 0; i < 12; i++) {
				var d = new Date(now.getFullYear(), now.getMonth() - i, 1);
				var v = d.getFullYear() + '-' + d.getMonth();
				opts += '<option value="' + v + '"' + (d.getFullYear() === sel.y && d.getMonth() === sel.m ? ' selected' : '') + '>' + MONTHS_BG[d.getMonth()] + ' ' + d.getFullYear() + '</option>';
			}

			var sum = payables.reduce(function (a, b) { return a + b.net; }, 0);
			var noIban = payables.filter(function (b) { return !b.has_iban; }).length;

			var html = '<div class="ap-panel"><h2>Месечно изплащане<span class="sp"></span></h2>' +
				'<div class="ap-toolbar">' +
				'<select class="ap-select" id="ap-paymonth">' + opts + '</select>' +
				'<label class="ap-check"><input type="checkbox" id="ap-pay-email"' + (BOOT.emailOnPayout ? ' checked' : '') + '> ✉️ имейл до партньора</label>' +
				'<span class="sp"></span>' +
				'<button class="ap-btn ghost sm" id="ap-annual" title="Всичко изплатено по партньори за годината — за счетоводството">Годишен отчет CSV</button>' +
				'<button class="ap-btn leaf sm" id="ap-pay-selected"' + (payables.length ? '' : ' disabled') + '>Изплати избраните</button></div>' +
				'<p class="ap-muted" style="padding:0 18px 8px">Плащаш всичко одобрено <strong>до ' + cutoff().split('-').reverse().join('.') + '</strong> включително: <strong>' + money(sum) + '</strong> към ' + payables.length + ' партньори.' +
				(noIban ? ' <span style="color:#a86b09;font-weight:800">⚠ ' + noIban + ' без IBAN → отиват в Wise „по email" файла.</span>' : '') + '</p>' +
				'<table class="ap-table"><thead><tr><th style="width:30px"><input type="checkbox" id="ap-payall" checked></th><th>Партньор</th><th style="text-align:right">Нето дължимо</th><th style="text-align:right">Редове</th><th>Период на редовете</th><th>IBAN</th><th></th></tr></thead><tbody>';
			if (!payables.length) html += '<tr><td colspan="7" class="empty">Нищо дължимо до тази дата — книгата е чиста.</td></tr>';
			payables.forEach(function (b) {
				html += '<tr>' +
					'<td><input type="checkbox" class="ap-paycheck" value="' + b.partner_id + '" checked></td>' +
					'<td><a href="#/partners/' + b.partner_id + '" style="color:inherit"><strong>' + esc(b.partner) + '</strong></a></td>' +
					'<td class="num pos">' + money(b.net, b.currency) + '</td>' +
					'<td class="num">' + b.rows + '</td>' +
					'<td class="ap-muted">' + esc((b.period_from || '').split('-').reverse().join('.')) + ' – ' + esc((b.period_to || '').split('-').reverse().join('.')) + '</td>' +
					'<td>' + (b.has_iban ? '<span style="color:var(--good);font-weight:800">✓</span>' : '<span style="color:#a86b09;font-weight:800">email</span>') + '</td>' +
					'<td style="text-align:right"><button class="ap-btn sm leaf" data-paynow="' + b.partner_id + '">Изплати сега</button></td>' +
				'</tr>';
			});
			html += '</tbody></table></div>';

			// История — групирана по месец на изплащането, с период
			html += '<div class="ap-panel"><h2>История<span class="sp"></span></h2>' +
				'<table class="ap-table"><thead><tr><th>№</th><th>Дата</th><th>Партньор</th><th style="text-align:right">Сума</th><th>Период</th><th>Партида</th><th></th></tr></thead><tbody>';
			if (!payouts.length) html += '<tr><td colspan="7" class="empty">Все още няма изплащания.</td></tr>';
			var lastMonth = '';
			payouts.forEach(function (r) {
				var d = new Date((r.created_at || '').replace(' ', 'T'));
				var mk = isNaN(d) ? '' : MONTHS_BG[d.getMonth()] + ' ' + d.getFullYear();
				if (mk && mk !== lastMonth) {
					lastMonth = mk;
					html += '<tr class="ap-mhead"><td colspan="7">' + mk + '</td></tr>';
				}
				html += '<tr class="click" data-go="#/payouts/' + r.payout_id + '">' +
					'<td><strong>#' + r.payout_id + '</strong></td><td>' + dt(r.created_at) + '</td><td>' + esc(r.partner) + '</td>' +
					'<td class="num">' + money(r.amount, r.currency) + '</td>' +
					'<td class="ap-muted">' + esc((r.note || '—').replace(/^Период:\s*/, '')) + '</td>' +
					'<td><code class="ap-code">' + esc(r.reference) + '</code></td>' +
					'<td style="text-align:right;white-space:nowrap"><button class="ap-btn sm ghost" data-csv="' + esc(r.reference) + '">CSV</button> <button class="ap-btn sm ghost" data-wise="' + esc(r.reference) + '">Wise</button> <button class="ap-btn sm ghost" data-notify="' + esc(r.reference) + '" title="Прати имейлите СЛЕД като качиш файла в Wise">✉️ Уведоми</button></td></tr>';
			});
			html += '</tbody></table></div>';

			$title.textContent = 'Изплащания';
			$view.innerHTML = html;

			var annBtn = document.getElementById('ap-annual');
			if (annBtn) annBtn.addEventListener('click', function () {
				var y = prompt('Година:', String(new Date().getFullYear() - (new Date().getMonth() === 0 ? 1 : 0)));
				if (!y) return;
				api('annual?year=' + encodeURIComponent(y)).then(function (r) {
					if (!r.rows.length) { toast('Няма изплащания за ' + r.year, true); return; }
					tableToCsv(r.rows.map(function (x) {
						return [x.partner, x.legal_name, x.email, x.iban, x.payouts, x.total, x.first, x.last];
					}), ['Партньор', 'Имена (документ)', 'Email', 'IBAN', 'Бр. изплащания', 'Общо EUR', 'Първо', 'Последно'],
					'annual-' + r.year + '.csv');
					toast('Годишен отчет ' + r.year + ': ' + r.rows.length + ' партньори · общо ' + r.grand_total + ' €');
				}).catch(function (e) { toast(e.message, true); });
			});

			var monthSel = document.getElementById('ap-paymonth');
			if (monthSel) monthSel.addEventListener('change', function () {
				var p = monthSel.value.split('-');
				viewPayouts._sel = { y: parseInt(p[0], 10), m: parseInt(p[1], 10) };
				viewPayouts();
			});

			var all = document.getElementById('ap-payall');
			if (all) all.addEventListener('change', function () {
				$view.querySelectorAll('.ap-paycheck').forEach(function (c) { c.checked = all.checked; });
			});

			var payInFlight = false; // Одит F02 (UI слой): без двоен клик; сървърната idempotency идва в PR-02
			function doPay(ids, label) {
				if (payInFlight) { toast('Изплащането вече върви — изчакай отговора', true); return; }
				if (!ids.length) { toast('Няма избрани партньори', true); return; }
				if (!confirm(periodLabel() + '.\nВсички одобрени редове до ' + cutoff().split('-').reverse().join('.') + ' за ' + label + ' ще бъдат отбелязани като ИЗПЛАТЕНИ. Продължаваме?')) return;
				var mailBox = document.getElementById('ap-pay-email');
				payInFlight = true;
				api('pay', { method: 'POST', body: { partner_ids: ids, until: cutoff(), period_label: periodLabel(), send_email: mailBox ? (mailBox.checked ? 1 : 0) : 1 } }).finally(function () { payInFlight = false; }).then(function (r) {
					(r.report || []).forEach(function (line) { toast(line); });
					downloadCsv(r.batch);
					downloadCsv(r.batch, 'wise');
					viewPayouts(); refreshOwed();
				}).catch(function (e) { toast(e.message, true); });
			}

			var paySel = document.getElementById('ap-pay-selected');
			if (paySel) paySel.addEventListener('click', function () {
				var ids = Array.prototype.map.call($view.querySelectorAll('.ap-paycheck:checked'), function (c) { return parseInt(c.value, 10); });
				doPay(ids, ids.length + ' партньора');
			});
			$view.querySelectorAll('[data-paynow]').forEach(function (btn) {
				btn.addEventListener('click', function () { doPay([parseInt(btn.dataset.paynow, 10)], 'този партньор'); });
			});
			$view.querySelectorAll('[data-csv]').forEach(function (btn) {
				btn.addEventListener('click', function (e) { e.stopPropagation(); downloadCsv(btn.dataset.csv); });
			});
			$view.querySelectorAll('[data-wise]').forEach(function (btn) {
				btn.addEventListener('click', function (e) { e.stopPropagation(); downloadCsv(btn.dataset.wise, 'wise'); });
			});
			$view.querySelectorAll('[data-notify]').forEach(function (btn) {
				btn.addEventListener('click', function (e) {
					e.stopPropagation();
					if (!confirm('Имейл „Изплатихме ти" до всички от партида ' + btn.dataset.notify + '?\nПрати го СЛЕД като файлът е качен в Wise. Вече уведомените се прескачат.')) return;
					btn.disabled = true;
					api('payouts/notify', { method: 'POST', body: { batch: btn.dataset.notify } }).then(function (r) {
						btn.disabled = false;
						toast('✉️ Пратени: ' + r.sent + (r.skipped ? ' · прескочени (уведомени/без имейл): ' + r.skipped : ''));
					}).catch(function (err) { btn.disabled = false; toast(err.message, true); });
				});
			});
			bindRowLinks();
			bindOrderInfo();
		}).catch(function (e) { viewError(e); });
	}

	function viewPayout(id) {
		loading('Изплащане');
		api('payouts/' + id).then(function (p) {
			var html = '<a class="ap-back" href="#/payouts">&larr; Всички изплащания</a>' +
				'<div class="ap-detail-head" style="margin-top:8px"><h2>Изплащане #' + p.payout_id + '</h2>' +
				'<span class="ap-muted">' + esc(p.partner) + ' · ' + dt(p.created_at) + ' · партида <code class="ap-code">' + esc(p.reference) + '</code></span>' +
				'<span style="flex:1"></span><button class="ap-btn ghost sm" id="app-csv">CSV</button> <button class="ap-btn ghost sm" id="app-wise">Wise</button> <a class="ap-btn ghost sm" id="app-stmt" target="_blank" rel="noopener" style="text-decoration:none">Справка ↗</a> <a class="ap-btn ghost sm" id="app-stmt-all" target="_blank" rel="noopener" style="text-decoration:none" title="Всички справки от партидата — един печат">Цялата партида ↗</a></div>';

			html += '<div class="ap-slips" style="max-width:340px"><div class="ap-slip leaf"><div class="lbl">Изплатена сума</div><div class="val">' + money(p.amount, p.currency) + '</div><div class="sub">' + p.rows.length + ' реда в книгата</div></div></div>';

			html += '<div class="ap-panel"><h2>Включени поръчки<span class="sp"></span></h2>' + ledgerTable(p.rows, { partner: false, actions: false }) + '</div>';

			$title.textContent = 'Изплащане #' + p.payout_id;
			$view.innerHTML = html;
			document.getElementById('app-csv').addEventListener('click', function () { downloadCsv(p.reference); });
			document.getElementById('app-wise').addEventListener('click', function () { downloadCsv(p.reference, 'wise'); });
			var stmt = document.getElementById('app-stmt');
			if (stmt) stmt.href = (BOOT.home || '') + '/partner?statement=' + p.payout_id;
			var allst = document.getElementById('app-stmt-all');
			if (allst) allst.href = (BOOT.home || '') + '/partner?statements=' + encodeURIComponent(p.reference);
			bindRowLinks();
			bindOrderInfo();
		}).catch(function (e) { viewError(e); });
	}

	function downloadCsv(batch, format) {
		api('payouts-csv?batch=' + encodeURIComponent(batch) + (format ? '&format=' + format : '')).then(function (r) {
			var files = (r.files && r.files.length) ? r.files : [{ filename: r.filename, csv: r.csv }];
			files.forEach(function (f, i) {
				setTimeout(function () {
					var blob = new Blob([f.csv], { type: 'text/csv;charset=utf-8' });
					var a = document.createElement('a');
					a.href = URL.createObjectURL(blob);
					a.download = f.filename;
					document.body.appendChild(a); a.click(); a.remove();
					setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
				}, i * 350);
			});
			if (files.length > 1) toast('Два Wise файла: банкови сметки + по email (партньори без IBAN)');
		}).catch(function (e) { toast(e.message, true); });
	}

	/* ---------------- Diagnostics ---------------- */

	function viewDiagnostics() {
		loading('Диагностика');
		function render(checks) {
			var counts = { pass: 0, warn: 0, fail: 0 };
			checks.forEach(function (c) { counts[c.status] = (counts[c.status] || 0) + 1; });

			var head = counts.fail ? '<span style="color:var(--bad);font-weight:800">✗ ' + counts.fail + ' критични проблема</span>'
				: counts.warn ? '<span style="color:#a86b09;font-weight:800">⚠ ' + counts.warn + ' предупреждения</span>'
				: '<span style="color:var(--good);font-weight:800">✓ Веригата на атрибуция е здрава</span>';

			var html = '<div class="ap-panel"><h2>Проверка на веригата<span class="sp"></span>' + head +
				'<button class="ap-btn sm ghost" id="ap-diag-backup" style="margin-left:12px">Свали пълен бекъп (JSON)</button> ' +
				'<button class="ap-btn sm ghost" id="ap-diag-run" style="margin-left:8px">Пусни отново</button></h2>' +
				'<ul class="ap-diag">';
			checks.forEach(function (c) {
				var dot = c.status === 'pass' ? 'var(--good)' : c.status === 'warn' ? 'var(--amber)' : 'var(--bad)';
				html += '<li><span class="dot" style="background:' + dot + '"></span><div><strong>' + esc(c.name) + '</strong>' +
					(c.detail ? '<br><span class="ap-muted">' + esc(c.detail) + '</span>' : '') + '</div></li>';
			});
			html += '</ul><p class="ap-muted" style="padding:0 18px 16px">За пълен end-to-end тест: направи staging поръчка с партньорски код, опитай втори код (трябва да бъде отказан), премини поръчката през ' + '„Delivered“' + ' и провери комисионата тук.</p></div>';

			$title.textContent = 'Диагностика';
						html += '<div class="ap-panel" id="ap-recon"><h2>Сверка на книгата<span class="sp"></span>' +
				'<button class="ap-btn sm ghost" id="ap-recon-run">Провери сега</button></h2>' +
				'<div id="ap-recon-body" style="padding:0 16px 14px"><p class="ap-muted" style="padding-top:10px">Зареждане…</p></div></div>';
			$view.innerHTML = html;
			initRecon();
			document.getElementById('ap-diag-run').addEventListener('click', viewDiagnostics);
			document.getElementById('ap-diag-backup').addEventListener('click', function () {
				api('export').then(function (r) {
					var blob = new Blob([r.json], { type: 'application/json' });
					var a = document.createElement('a');
					a.href = URL.createObjectURL(blob); a.download = r.filename;
					document.body.appendChild(a); a.click(); a.remove();
					toast('Бекъпът е свален — пази го извън сървъра');
				}).catch(function (e) { toast(e.message, true); });
			});
		}
		api('diagnostics/run', { method: 'POST' }).then(render)
			.catch(function (e) { $view.innerHTML = '<p class="ap-muted">' + esc(e.message) + '</p>'; });
	}

	/* ---------------- shared ledger table ---------------- */

	/* Клиентска пагинация: host получава таблицата за текущата страница + пейджър. render(slice) връща HTML; after() се вика след всяко рисуване (за bind-ове). */
	function pagedTable(host, rows, per, render, after) {
		if (!host) return;
		rows = rows || []; per = per || 20;
		var page = 0, n = Math.max(1, Math.ceil(rows.length / per));
		function draw() {
			var from = page * per, slice = rows.slice(from, from + per), pg = '';
			if (n > 1) {
				var btns = '';
				for (var i = 0; i < n; i++) {
					if (n > 9 && Math.abs(i - page) > 2 && i !== 0 && i !== n - 1) { if (btns.slice(-1) !== '…') btns += '…'; continue; }
					btns += '<button type="button" class="pg' + (i === page ? ' on' : '') + '" data-pg="' + i + '">' + (i + 1) + '</button>';
				}
				pg = '<div class="pp-pager"><span class="ap-muted">' + (from + 1) + '–' + Math.min(rows.length, from + per) + ' от ' + rows.length + '</span><span class="sp"></span>' +
					'<button type="button" class="pg" data-pg="' + (page - 1) + '"' + (page === 0 ? ' disabled' : '') + '>‹</button>' + btns + '<button type="button" class="pg" data-pg="' + (page + 1) + '"' + (page >= n - 1 ? ' disabled' : '') + '>›</button></div>';
			}
			host.innerHTML = render(slice) + pg;
			host.querySelectorAll('[data-pg]').forEach(function (b) { b.addEventListener('click', function () { var t = +b.dataset.pg; if (t < 0 || t >= n || t === page) return; page = t; draw(); try { host.scrollIntoView({ block: 'nearest' }); } catch (x) {} }); });
			if (after) after();
		}
		draw();
	}

	function ledgerTable(rows, opts) {
		var html = '<table class="ap-table"><thead><tr><th>Дата</th>' +
			(opts.partner ? '<th>Партньор</th>' : '') +
			'<th>Поръчка</th><th>Описание</th><th>Код</th><th style="text-align:right">База · ставка</th><th style="text-align:right">Сума</th><th>Статус</th>' +
			(opts.actions ? '<th></th>' : '') + '</tr></thead><tbody>';

		if (!rows || !rows.length) {
			html += '<tr><td colspan="10" class="empty">Няма движения.</td></tr></tbody></table>';
			return html;
		}
		rows.forEach(function (r) {
			var neg = r.amount < 0;
			var actions = '';
			if (opts.actions && r.status !== 'paid' && r.status !== 'history' && r.status !== 'fixed') {
				if (r.status === 'pending')   actions += '<button class="ap-btn sm ghost" data-cstatus="' + r.commission_id + '" data-to="approved">Одобри</button> ';
				if (r.status !== 'reversed')  actions += '<button class="ap-btn sm danger" data-cstatus="' + r.commission_id + '" data-to="reversed">Сторно</button>';
				if (r.status === 'reversed')  actions += '<button class="ap-btn sm ghost" data-cstatus="' + r.commission_id + '" data-to="pending">Възстанови</button>';
			}
			html += '<tr>' +
				'<td>' + dt(r.created_at) + '</td>' +
				(opts.partner ? '<td style="white-space:nowrap"><a href="#/partners/' + r.partner_id + '" style="color:inherit"><strong>' + esc(r.partner) + '</strong></a></td>' : '') +
				'<td style="white-space:nowrap">' + (r.order_id ? '<button class="ap-oi-btn" data-oi="' + r.order_id + '" title="Инфо за поръчката">▸</button> <a href="' + esc(r.order_url) + '" target="_blank" rel="noopener"><strong>#' + r.order_id + '</strong></a>' : '<span class="ap-muted">—</span>') + '</td>' +
				'<td>' + (r.products ? '<span class="ap-desc" title="' + esc(r.products) + '">' + esc(r.products) + '</span>' : '<span class="ap-muted">—</span>') + '</td>' +
				'<td>' + (r.coupon_code ? '<code class="ap-code">' + esc(r.coupon_code.toUpperCase()) + '</code>' : (r.type === 'import' ? chip('import', 'Импорт') : (r.type === 'manual' ? chip('import', 'Ръчен') : '—'))) + '</td>' +
				'<td class="num" style="font-weight:600">' + (r.base_amount ? money(r.base_amount, '') : '—') + (r.rate_applied ? ' <span class="ap-muted">× ' + rateFmt(r.rate_applied) + '%</span>' : '') + '</td>' +
				'<td class="num ' + (neg ? 'neg' : (r.status === 'approved' ? 'pos' : '')) + '">' + money(r.amount, r.currency) + '</td>' +
				'<td>' + (r.type === 'reversal' ? chip('reversal', 'Корекция') : chip(r.status)) + '</td>' +
				(opts.actions ? '<td style="text-align:right;white-space:nowrap">' + (actions || '<span class="ap-muted">—</span>') + '</td>' : '') +
			'</tr>';
		});
		html += '</tbody></table>';
		return html;
	}

	/* Разгъващо инфо за поръчка — без да напускаш приложението */
	var oiCache = {};
	function bindOrderInfo() {
		$view.querySelectorAll('.ap-oi-btn').forEach(function (btn) {
			if (btn.dataset.bound) return;
			btn.dataset.bound = 1;
			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				var tr = btn.closest('tr'), oid = btn.dataset.oi;
				var open = tr.nextElementSibling && tr.nextElementSibling.classList.contains('ap-oi-row');
				if (open) { tr.nextElementSibling.remove(); btn.textContent = '▸'; return; }
				btn.textContent = '▾';
				var cols = tr.children.length;
				var det = document.createElement('tr');
				det.className = 'ap-oi-row';
				det.innerHTML = '<td colspan="' + cols + '"><div class="ap-oi">Зареждане…</div></td>';
				tr.parentNode.insertBefore(det, tr.nextSibling);

				function render(o) {
					var items = (o.items || []).map(function (i) {
						return '<li>' + esc(i.name) + ' × ' + i.qty + ' — ' + money(i.total, o.currency) + '</li>';
					}).join('');
					var lockLine = o.lock && o.lock.coupon_code
						? '<div><span class="l">Атрибуция</span>' + esc((o.lock.coupon_code || '').toUpperCase()) + ' → ' + esc(o.lock.partner || '') + ' (' + esc(String(o.lock.rate || '?')) + '%, ' + (o.lock.source === 'link' ? 'линк' : 'код') + ')</div>'
						: '';
					det.querySelector('.ap-oi').innerHTML =
						'<div class="ap-oi-grid">' +
							'<div><span class="l">Статус</span>' + chip(o.status, esc(o.status_label)) + '</div>' +
							'<div><span class="l">Дата</span>' + esc(o.date) + '</div>' +
							'<div><span class="l">Клиент</span><strong>' + esc(o.customer || '—') + '</strong>' + (o.city ? ', ' + esc(o.city) : '') + '</div>' +
							'<div><span class="l">Телефон</span>' + esc(o.phone || '—') + '</div>' +
							'<div><span class="l">Плащане</span>' + esc(o.payment || '—') + '</div>' +
							'<div><span class="l">Общо</span><strong>' + money(o.total, o.currency) + '</strong>' + (o.shipping ? ' <span class="ap-muted">(дост. ' + money(o.shipping, o.currency) + ')</span>' : '') + '</div>' +
							'<div><span class="l">Купони</span>' + ((o.coupons || []).map(function (c) { return '<code class="ap-code">' + esc(c) + '</code>'; }).join(' ') || '—') + '</div>' +
							'<div><span class="l">Партн. cost</span>' + (o.partner_cost ? money(o.partner_cost, o.currency) : '—') + '</div>' +
							lockLine +
						'</div>' +
						(items ? '<ul class="ap-oi-items">' + items + '</ul>' : '') +
						'<a class="ap-muted" style="font-size:12px" href="' + esc(o.edit_url) + '" target="_blank" rel="noopener">Отвори в WooCommerce ↗</a>';
				}

				if (oiCache[oid]) { render(oiCache[oid]); return; }
				api('order-info/' + oid).then(function (o) { oiCache[oid] = o; render(o); })
					.catch(function (err) { det.querySelector('.ap-oi').innerHTML = '<span class="ap-muted">' + esc(err.message) + '</span>'; });
			});
		});
	}

	function bindLedgerActions(after) {
		$view.querySelectorAll('[data-cstatus]').forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				api('commissions/' + btn.dataset.cstatus + '/status', { method: 'POST', body: { to: btn.dataset.to } })
					.then(function () { toast('Статусът е променен'); after(); })
					.catch(function (err) { toast(err.message, true); });
			});
		});
	}

	function bindRowLinks() {
		$view.querySelectorAll('tr[data-go]').forEach(function (tr) {
			tr.addEventListener('click', function (e) {
				if (e.target.closest('a, button, input')) return;
				location.hash = tr.dataset.go;
			});
		});
	}

	/* ---------------- boot ---------------- */
	refreshOwed();
	setInterval(refreshOwed, 90000);
	route();
})();
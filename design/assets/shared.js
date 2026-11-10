/* fin-manage 시안 공통 틀: 상단바 · 아래 메뉴 · 푸터를 그려 넣는다.
   페이지는 <body data-page="..." data-role="fin|dept|approver" data-user="이름" data-title="역할 이름"> 만 적는다.
   실제 구현(SvelteKit)에서는 이 부분이 +layout.svelte 가 된다. */
(function () {
	const I = {
		home: '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
		check: '<path d="M20 6L9 17l-5-5"/>',
		doc: '<path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
		wallet: '<path d="M3 7a2 2 0 012-2h13v4"/><path d="M3 7v11a2 2 0 002 2h15a1 1 0 001-1V9a1 1 0 00-1-1H5a2 2 0 01-2-1z"/><circle cx="16.5" cy="14" r="1.2"/>',
		coins: '<ellipse cx="9" cy="7" rx="6" ry="3"/><path d="M3 7v5c0 1.7 2.7 3 6 3s6-1.3 6-3V7"/><path d="M15 10.5c3 0 6 1 6 2.5v4c0 1.7-2.7 3-6 3-1.6 0-3-.3-4-.8"/>',
		chart: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
		receipt: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
		plus: '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
		menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
		user: '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0112 0v1"/>',
		users: '<circle cx="9" cy="8" r="4"/><path d="M2 21v-1a7 7 0 0114 0v1"/><path d="M17 4a4 4 0 010 8M22 21v-1a6 6 0 00-4-5.6"/>',
		logout: '<path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><path d="M10 17l5-5-5-5M15 12H3"/>',
		search: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
		camera: '<path d="M4 8h3l2-3h6l2 3h3a1 1 0 011 1v10a1 1 0 01-1 1H4a1 1 0 01-1-1V9a1 1 0 011-1z"/><circle cx="12" cy="13" r="3.5"/>',
		lock: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/>',
		info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/>',
		building: '<path d="M4 21V5l8-3 8 3v16"/><path d="M9 21v-5h6v5M9 9h.01M15 9h.01M9 13h.01M15 13h.01"/>',
		clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		alert: '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18v.01"/>',
		trash: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
		up: '<path d="M12 19V5"/><path d="M5 12l7-7 7 7"/>'
	};
	window.icon = (n, cls) => '<span class="' + (cls || 'ico-s') + '"><svg viewBox="0 0 24 24" aria-hidden="true">' + (I[n] || '') + '</svg></span>';
	const ch = '<svg width="10" height="10" viewBox="0 0 12 8" aria-hidden="true"><path d="M1 1.5l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

	const b = document.body;
	const page = b.dataset.page || '';
	const role = b.dataset.role || 'fin';
	const user = b.dataset.user || '김재정';
	const title = b.dataset.title || '재정 담당';
	const church = '샘플교회';

	// 메뉴: 역할에 따라 다르게 보인다 (서버가 같은 기준으로 다시 막는다)
	const dd = (key, label, items) => ({ key, label, items });
	const MENUS = {
		fin: [
			{ key: 'dashboard', label: '대시보드', href: 'dashboard.html' },
			dd('off', '헌금', [['offerings.html', '헌금 입력', '주일 계수 · 빠른 입력 · 마감']]),
			dd('exp', '지출', [['voucher-new.html', '지출결의서 쓰기', '기안 · 증빙 · 결재선 미리보기'], ['approvals.html', '결재함', '결재 대기 · 완료 · 반려']]),
			{ key: 'budget', label: '예산', href: 'budget.html' },
			{ key: 'receipts', label: '영수증', href: 'receipts.html' },
			dd('admin', '관리자', [['admin-users.html', '재정 사용 승인', '교적 계정 승인 · 역할 · 열람 지정'], ['admin-org.html', '재정 단위 · 담당자', '회계 · 부서장 · 위원장 지정'], ['admin-rules.html', '결재 규칙 · 설정', '금액 구간 · 직접 지출 · 주민번호']])
		],
		dept: [
			{ key: 'dashboard', label: '홈', href: 'dashboard.html' },
			{ key: 'vnew', label: '지출결의서', href: 'voucher-new.html' },
			{ key: 'budget', label: '우리 부서 예산', href: 'budget.html' }
		],
		approver: [
			{ key: 'dashboard', label: '홈', href: 'dashboard.html' },
			{ key: 'approvals', label: '결재함', href: 'approvals.html', count: 3 },
			{ key: 'budget', label: '예산', href: 'budget.html' }
		]
	};
	const menu = MENUS[role] || MENUS.fin;
	const groupOf = { 'offerings': 'off', 'voucher-new': 'exp', 'approvals': 'exp', 'admin-users': 'admin', 'admin-org': 'admin', 'admin-rules': 'admin' };
	const active = (m) => (m.items ? groupOf[page] === m.key : m.key === page);
	let nav = '';
	menu.forEach((m) => {
		if (m.items) {
			nav += '<div class="dd' + (active(m) ? ' active' : '') + '"><a href="' + m.items[0][0] + '" class="dd-btn" aria-haspopup="true">' + m.label + ch + '</a><div class="dd-menu">' +
				m.items.map((i) => '<a href="' + i[0] + '"' + (i[0] === page + '.html' ? ' class="active"' : '') + '><b>' + i[1] + '</b><small>' + i[2] + '</small></a>').join('') + '</div></div>';
		} else {
			nav += '<a href="' + m.href + '"' + (active(m) ? ' class="active"' : '') + '>' + m.label + (m.count ? '<span class="count">' + m.count + '</span>' : '') + '</a>';
		}
	});

	const header =
		'<div class="bar"><a class="brand" href="dashboard.html"><img class="logo" src="assets/logo.png" alt="" width="38" height="38"><span><b>' + church + ' 재정 관리</b></span></a>' +
		'<button class="burger" type="button" aria-label="메뉴 열기" id="burger"><span></span><span></span><span></span></button>' +
		'<nav class="main-nav" id="mainnav" aria-label="주 메뉴">' + nav +
		'<div class="nav-tools"><div class="perm"><button type="button" class="tool-btn" id="userbtn">' + icon('user') + user + ' <small class="role">' + title + '</small></button>' +
		'<div class="perm-panel" id="userpanel" hidden><b>' + user + '</b> <span class="tag">' + title + '</span>' +
		'<p class="muted">교적 관리와 같은 계정입니다. 비밀번호는 교적 관리에서 바꿉니다. 재정 권한은 재정 관리자가 정합니다.</p><hr>' +
		'<a class="btn ghost sm" href="login.html">교적 관리에서 비밀번호 바꾸기</a></div></div>' +
		'<a class="tool-btn" href="login.html">' + icon('logout') + '로그아웃</a></div></nav></div>';

	const tab = (href, ico, label, key, count) =>
		'<a href="' + href + '"' + (page === key ? ' class="on"' : '') + '><svg viewBox="0 0 24 24" aria-hidden="true">' + I[ico] + '</svg>' + label + (count ? '<span class="badge-dot">' + count + '</span>' : '') + '</a>';
	let tabbar = tab('dashboard.html', 'home', '홈', 'dashboard');
	if (role === 'approver') tabbar += tab('approvals.html', 'check', '결재함', 'approvals', 3);
	if (role === 'dept') tabbar += tab('voucher-new.html', 'plus', '기안', 'voucher-new') + tab('budget.html', 'chart', '예산', 'budget');
	if (role === 'fin') tabbar += tab('approvals.html', 'check', '결재함', 'approvals', 3) + tab('voucher-new.html', 'plus', '기안', 'voucher-new') + tab('offerings.html', 'coins', '헌금', 'offerings');
	tabbar += '<button type="button" id="tabmenu"><svg viewBox="0 0 24 24" aria-hidden="true">' + I.menu + '</svg>메뉴</button>';

	const hdr = document.getElementById('hdr');
	if (hdr) { hdr.className = 'site-header'; hdr.innerHTML = header; }
	const tb = document.getElementById('tab');
	if (tb) { tb.className = 'tabbar'; tb.innerHTML = tabbar; }
	const ft = document.getElementById('ftr');
	if (ft) {
		ft.className = 'site-footer';
		ft.innerHTML = '<div class="foot"><div class="foot-brand"><b>' + church + ' 재정 관리</b><small class="foot-note">개인별 헌금 열람, 결재, 영수증 발급은 모두 기록에 남습니다.</small></div>' +
			'<div class="foot-copy"><span>디자인 시안 · 모든 이름과 금액은 예시입니다</span><small>프로그램 fin-manage · 교적 관리(ch-manage)와 같은 디자인</small></div></div>';
	}

	// 상단바 줄어들기 · 메뉴 열기
	const hd = document.querySelector('.site-header');
	const onScroll = () => hd && hd.style.setProperty('--hb-p', Math.round(Math.min(1, scrollY / 160) * 100) / 100);
	addEventListener('scroll', onScroll, { passive: true }); onScroll();
	const nv = document.getElementById('mainnav');
	const tg = () => nv.classList.toggle('open');
	document.getElementById('burger')?.addEventListener('click', tg);
	document.getElementById('tabmenu')?.addEventListener('click', tg);
	const up = document.getElementById('userpanel');
	document.getElementById('userbtn')?.addEventListener('click', (e) => { e.stopPropagation(); up.hidden = !up.hidden; });
	addEventListener('click', (e) => { if (up && !e.target.closest('.perm')) up.hidden = true; });

	// data-i="이름" 자리에 아이콘을 넣는다
	document.querySelectorAll('[data-i]').forEach((el) => { el.insertAdjacentHTML('afterbegin', icon(el.dataset.i)); });
	document.querySelectorAll('[data-ico]').forEach((el) => { el.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">' + I[el.dataset.ico] + '</svg>'; });

	// 탭 전환 (.seg / .tabs 의 data-show = 보일 .state 의 id)
	document.querySelectorAll('[data-tabs]').forEach((box) => {
		box.querySelectorAll('[data-show]').forEach((btn) => btn.addEventListener('click', () => {
			box.querySelectorAll('[data-show]').forEach((x) => x.classList.toggle('on', x === btn));
			document.querySelectorAll('.state[data-group="' + box.dataset.tabs + '"]').forEach((s) => s.classList.toggle('on', s.id === btn.dataset.show));
		}));
	});
	window.won = (n) => Number(n || 0).toLocaleString('ko-KR');
})();

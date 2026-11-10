<script lang="ts">
	// 화면 공통 틀: 로그인(교적 계정), 재정 사용 신청·승인 대기 안내, 상단 메뉴, 휴대폰 아래 메뉴
	import './app.css';
	import './fm.css';
	import { onMount } from 'svelte';
	import { page } from '$app/state';
	import { goto } from '$app/navigation';
	import { auth } from '#lib/auth.svelte.ts';
	import { hrefTo, stripBase } from '#lib/paths.ts';

	let { children } = $props();

	// 30분 동안 쓰지 않으면 로그아웃 (서버 세션도 같은 시간에 끝남)
	const IDLE_MS = 30 * 60 * 1000;
	let lf = $state({ loginId: '', password: '' });
	let reason = $state('');
	let busy = $state(false);
	let msg = $state('');
	let last = Date.now();
	let menuOpen = $state(false);
	let userOpen = $state(false);
	let scrollY = $state(0);
	// 스크롤을 내리는 만큼 상단바가 조금씩 줄어듦 (맨 위 0 → 160px 내리면 1)
	const hbP = $derived(Math.round(Math.min(1, Math.max(0, scrollY / 160)) * 100) / 100);

	const me = $derived(auth.me);
	const active = $derived(me?.state === 'active');
	const path = $derived(stripBase(page.url.pathname));
	const isAdmin = $derived(!!me?.access?.isAdmin);
	const isStaff = $derived(isAdmin || !!me?.access?.roles.includes('재정 담당'));
	const canOffer = $derived(!!me?.access?.roles.some((r) => ['재정 담당', '계수 위원', '재정위원장', '담임목사', '감사', '열람'].includes(r)));
	const canReport = $derived(!!me?.access?.roles.some((r) => ['재정 담당', '재정위원장', '담임목사', '감사', '열람'].includes(r)) || !!me?.access?.canViewDonors);
	let rcPw = $state('');
	let rcMsg = $state('');
	let rcBusy = $state(false);
	async function doReconfirm(e: SubmitEvent) {
		e.preventDefault();
		rcMsg = '';
		rcBusy = true;
		try {
			await auth.submitReconfirm(rcPw);
			rcPw = '';
		} catch (err) {
			rcMsg = errMsg(err, '확인하지 못했습니다.');
			rcPw = '';
		} finally {
			rcBusy = false;
		}
	}
	const roleLabel = $derived(me?.access?.isAdmin ? '재정 관리자' : (me?.access?.roles[0] ?? ''));

	onMount(() => {
		auth.init();
		const touch = () => (last = Date.now());
		const outside = (e: MouseEvent) => {
			if (!(e.target as HTMLElement).closest?.('.perm')) userOpen = false;
		};
		window.addEventListener('click', touch);
		window.addEventListener('click', outside);
		window.addEventListener('keydown', touch);
		const t = setInterval(() => {
			if (auth.me && Date.now() - last > IDLE_MS) logout();
		}, 30000);
		return () => {
			window.removeEventListener('click', touch);
			window.removeEventListener('click', outside);
			window.removeEventListener('keydown', touch);
			clearInterval(t);
		};
	});

	// 화면을 옮기면 모바일 메뉴를 닫음
	$effect(() => {
		page.url.pathname;
		menuOpen = false;
	});

	const errMsg = (e: unknown, fallback: string) => (e instanceof Error ? e.message : fallback);

	async function logout() {
		userOpen = false;
		await auth.logout();
		lf = { loginId: '', password: '' };
		msg = '';
		goto(hrefTo('/'));
	}
	async function doLogin(e: SubmitEvent) {
		e.preventDefault();
		msg = '';
		busy = true;
		try {
			await auth.login(lf.loginId.trim(), lf.password);
			last = Date.now();
			lf = { loginId: '', password: '' };
		} catch (err) {
			msg = errMsg(err, '로그인하지 못했습니다.');
			lf.password = '';
		} finally {
			busy = false;
		}
	}
	async function doRequest(e: SubmitEvent) {
		e.preventDefault();
		msg = '';
		busy = true;
		try {
			await auth.requestAccess(reason.trim());
		} catch (err) {
			msg = errMsg(err, '신청을 보내지 못했습니다.');
		} finally {
			busy = false;
		}
	}
	async function recheck() {
		msg = '';
		try {
			await auth.refresh();
		} catch (err) {
			msg = errMsg(err, '다시 확인하지 못했습니다.');
		}
	}
</script>

<svelte:window bind:scrollY />

<div class="site">
	<header class="site-header" style:--hb-p={hbP}>
		<div class="bar">
			<a class="brand" href={hrefTo('/')}>
				<img class="logo" src={hrefTo('/logo.png')} alt="" width="38" height="38" />
				<span><b>{auth.church || '교회'} 재정 관리</b></span>
			</a>

			{#if active}
				<button class="burger" type="button" aria-label="메뉴 열기" aria-expanded={menuOpen} onclick={() => (menuOpen = !menuOpen)}>
					<span></span><span></span><span></span>
				</button>

				<nav class="main-nav" class:open={menuOpen} aria-label="주 메뉴">
					<a href={hrefTo('/')} class:active={path === '/'}>대시보드</a>
					{#if canOffer}<a href={hrefTo('/offerings')} class:active={path.startsWith('/offerings')}>헌금</a>{/if}
					{#if canReport}<a href={hrefTo('/reports')} class:active={path.startsWith('/reports')}>보고서</a>{/if}
					{#if isStaff}
						<a href={hrefTo('/units')} class:active={path.startsWith('/units')}>재정 단위</a>
						<a href={hrefTo('/base')} class:active={path.startsWith('/base')}>기준 자료</a>
					{/if}
					{#if isAdmin}
						<a href={hrefTo('/admin/users')} class:active={path.startsWith('/admin/users')}>재정 사용 승인</a>
					{/if}

					<div class="nav-tools">
						<div class="perm">
							<button type="button" class="tool-btn" aria-expanded={userOpen} onclick={() => (userOpen = !userOpen)}>
								{me?.user.name} <small class="role">{roleLabel}</small>
							</button>
							{#if userOpen}
								<div class="perm-panel">
									<b>{me?.user.name}</b> <span class="tag">{roleLabel}</span>
									<p class="muted">교적 관리와 같은 계정입니다. 비밀번호는 교적 관리에서 바꿉니다. 재정 권한은 재정 관리자가 정합니다.</p>
								</div>
							{/if}
						</div>
						<button type="button" class="tool-btn" onclick={logout}>로그아웃</button>
					</div>
				</nav>
			{/if}
		</div>
	</header>

	<main>
		{#if !auth.ready}
			<div class="card lock"><p class="muted">불러오는 중…</p></div>
		{:else if auth.notInstalled}
			<div class="card lock">
				<h2>아직 설치되지 않았습니다</h2>
				<p class="muted">주소 끝에 <b>/install/</b> 을 붙여 설치를 먼저 진행해 주세요.</p>
			</div>
		{:else if auth.netError}
			<div class="card lock">
				<h2>서버에 연결하지 못했습니다</h2>
				<p class="muted">{auth.netError}</p>
				<p><button type="button" onclick={() => location.reload()}>다시 시도</button></p>
			</div>
		{:else if !me}
			<div class="card lock">
				<img src={hrefTo('/logo.png')} alt="" width="64" height="64" style="display:block;margin:0 auto .8rem;border-radius:18px;box-shadow:0 6px 18px rgba(43,62,149,.25)" />
				<h2 class="brand-font">{auth.church || '교회'} 재정 관리</h2>
				<form onsubmit={doLogin}>
					<input bind:value={lf.loginId} placeholder="아이디" autocomplete="username" autocapitalize="off" required style="margin:.25rem 0" />
					<input type="password" bind:value={lf.password} placeholder="비밀번호" autocomplete="current-password" required style="margin:.25rem 0" />
					{#if msg}<p style="color:#a83c10;font-size:.88rem;margin:.5rem 0">{msg}</p>{/if}
					<button type="submit" style="width:100%;margin-top:.5rem" disabled={busy}>로그인</button>
				</form>
				<p class="muted fs-s" style="margin-top:1rem">교적 관리와 <b>같은 아이디·비밀번호</b>로 로그인합니다.<br />재정 사용을 승인받은 분만 들어갈 수 있고,<br />개인별 헌금 열람과 결재는 모두 기록에 남습니다.</p>
			</div>
		{:else if me.state === 'must_change'}
			<div class="card lock">
				<h2>비밀번호를 먼저 바꿔 주세요</h2>
				<p class="muted">임시 비밀번호로 로그인했어요. 교적 관리에서 나만 아는 비밀번호로 바꾼 뒤 다시 로그인해 주세요.</p>
				<p><button type="button" class="ghost" onclick={logout}>로그아웃</button></p>
			</div>
		{:else if me.state === 'nolink'}
			<div class="card lock">
				<h2>교인과 연결되어 있지 않아요</h2>
				<div class="warn" style="text-align:left;font-size:.88rem">이 계정은 교적에서 아직 <b>교인과 연결</b>되지 않았습니다. 재정은 결재·담당자를 교인 단위로 관리하므로, 교적 관리자에게 "내 계정을 교인과 연결해 주세요"라고 요청해 주세요.</div>
				<p><button type="button" class="ghost" onclick={logout}>로그아웃</button></p>
			</div>
		{:else if me.state === 'none'}
			<div class="card lock">
				<h2>재정 사용 신청</h2>
				<p class="muted fs-s">교적 계정으로 로그인했지만 아직 재정 사용을 신청하지 않았어요.</p>
				<div class="who"><span class="avatar">{me.user.name.slice(0, 1)}</span><div style="flex:1"><b>{me.user.name}</b> <span class="tag now">교인 연결됨</span><br /><small class="muted">교적 아이디 {me.user.loginId}</small></div></div>
				<form onsubmit={doRequest} style="text-align:left">
					<label class="fs-s muted" style="font-weight:600" for="reason">어떤 일로 쓰시나요? (재정 관리자가 승인할 때 참고합니다)</label>
					<textarea id="reason" rows="3" bind:value={reason} maxlength="255" placeholder="예: 유치부 회계 · 지출결의서 작성" style="margin:.3rem 0 .6rem"></textarea>
					{#if msg}<p style="color:#a83c10;font-size:.88rem;margin:.3rem 0">{msg}</p>{/if}
					<button type="submit" style="width:100%" disabled={busy}>재정 사용 신청</button>
				</form>
				<p><button type="button" class="ghost sm" onclick={logout}>로그아웃</button></p>
			</div>
		{:else if me.state === 'pending'}
			<div class="card lock">
				<h2>승인을 기다리고 있어요</h2>
				<div class="who"><span class="avatar">{me.user.name.slice(0, 1)}</span><div style="flex:1"><b>{me.user.name}</b> <span class="tag part">승인 대기</span><br /><small class="muted">{me.access?.requestedAt} 신청</small></div></div>
				<p class="muted fs-s">재정 관리자가 승인하고 역할을 정하면 바로 쓸 수 있어요.<br />승인되면 아래 버튼으로 다시 확인해 주세요.</p>
				{#if msg}<p style="color:#a83c10;font-size:.88rem">{msg}</p>{/if}
				<div class="linkrow"><button type="button" onclick={recheck}>다시 확인</button><button class="ghost" type="button" onclick={logout}>로그아웃</button></div>
			</div>
		{:else if me.state === 'suspended'}
			<div class="card lock">
				<h2>재정 사용이 정지되었어요</h2>
				<p class="muted">재정 관리자에게 문의해 주세요.</p>
				<p><button type="button" class="ghost" onclick={logout}>로그아웃</button></p>
			</div>
		{:else}
			{@render children()}
		{/if}
	</main>

	{#if active}
		<!-- 휴대폰 아래쪽 메뉴: 자주 쓰는 화면을 엄지로 바로 -->
		<nav class="tabbar" aria-label="빠른 메뉴">
			<a href={hrefTo('/')} class:on={path === '/'}>
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l9-8 9 8" /><path d="M5 10v10h14V10" /></svg>홈
			</a>
			<button type="button" class:on={menuOpen} onclick={() => (menuOpen = !menuOpen)}>
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>메뉴
			</button>
		</nav>
	{/if}

	<div class="modal-bg" class:open={auth.reconfirmOpen}>
		<form class="modal" onsubmit={doReconfirm}>
			<h3>비밀번호 확인</h3>
			<p class="muted fs-s">중요한 작업이라 교적 비밀번호를 한 번 더 확인합니다. (10분 동안 유효)</p>
			<input type="password" bind:value={rcPw} placeholder="비밀번호" autocomplete="current-password" required />
			{#if rcMsg}<p style="color:#a83c10;font-size:.88rem;margin:.5rem 0 0">{rcMsg}</p>{/if}
			<div class="row">
				<button type="button" class="ghost" onclick={() => auth.submitReconfirm(null)}>취소</button>
				<button type="submit" disabled={rcBusy}>확인</button>
			</div>
		</form>
	</div>

	<footer class="site-footer">
		<div class="foot">
			<div class="foot-brand">
				<b>{auth.church || '교회'} 재정 관리</b>
				<small class="foot-note">개인별 헌금 열람, 결재, 영수증 발급은 모두 기록에 남습니다.</small>
			</div>
			<div class="foot-copy">
				<span>ⓒ {new Date().getFullYear()} {auth.church}. All Rights Reserved.</span>
				<small>프로그램 <a href="https://github.com/byraju-hub/fin-manage" target="_blank" rel="noopener">fin-manage</a> · GNU AGPL-3.0</small>
			</div>
		</div>
	</footer>
</div>

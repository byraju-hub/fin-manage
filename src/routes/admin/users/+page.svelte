<script lang="ts">
	// 재정 사용 승인 · 역할 · 개인별 헌금 열람 지정 · 열람 기록 (재정 관리자 전용)
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';

	type Item = {
		id: number;
		loginId: string;
		name: string;
		linked: boolean;
		status: string;
		isAdmin: boolean;
		canViewDonors: boolean;
		roles: string[];
		reason: string;
		requestedAt: string;
		decidedAt: string | null;
		lastLoginAt: string | null;
	};
	type Log = { at: string; who: string; action: string; target: string };

	const ROLE_HELP: Record<string, string> = {
		'재정 담당': '헌금 입력·마감, 수입·지출 등록, 지급, 예산 입력, 보고서, 영수증 발급',
		재정위원장: '결재, 예산 확정, 월·연 마감 승인, 모든 보고서',
		담임목사: '결재선의 담임목사 단계, 모든 장부·결의서·보고서 열람',
		'계수 위원': '맡은 날의 헌금 입력만 (마감하면 더 볼 수 없음)',
		감사: '모든 장부·결의서·기록 열람 (읽기 전용)',
		열람: '보고서(합계) 열람'
	};

	let items = $state<Item[]>([]);
	let roles = $state<string[]>([]);
	let donorRoles = $state<string[]>([]);
	let log = $state<Log[]>([]);
	let tab = $state<'users' | 'log'>('users');
	let logKind = $state<'donor' | 'all'>('donor');
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let editing = $state<number | null>(null);
	let draft = $state<{ status: string; isAdmin: boolean; roles: string[]; canViewDonors: boolean }>({ status: '', isAdmin: false, roles: [], canViewDonors: false });

	const pending = $derived(items.filter((i) => i.status === '신청 대기'));
	const others = $derived(items.filter((i) => i.status !== '신청 대기'));
	const donorOk = $derived(draft.roles.some((r) => donorRoles.includes(r)));

	async function load() {
		try {
			const r = await auth.api<{ items: Item[]; roles: string[]; donorRoles: string[] }>('GET', '/admin/access');
			items = r.items;
			roles = r.roles;
			donorRoles = r.donorRoles;
			err = '';
		} catch (e) {
			err = e instanceof Error ? e.message : '불러오지 못했습니다.';
		}
	}
	async function loadLog() {
		try {
			log = (await auth.api<{ items: Log[] }>('GET', '/admin/log?kind=' + logKind)).items;
		} catch (e) {
			err = e instanceof Error ? e.message : '불러오지 못했습니다.';
		}
	}
	onMount(load);

	function edit(i: Item, approve = false) {
		editing = i.id;
		draft = { status: approve ? '사용' : i.status, isAdmin: i.isAdmin, roles: [...i.roles], canViewDonors: i.canViewDonors };
	}
	function toggleRole(r: string) {
		draft.roles = draft.roles.includes(r) ? draft.roles.filter((x) => x !== r) : [...draft.roles, r];
	}

	async function save(i: Item) {
		busy = true;
		notice = '';
		err = '';
		try {
			const body: Record<string, unknown> = { status: draft.status, isAdmin: draft.isAdmin, roles: draft.roles };
			// 개인별 헌금 열람은 사람이 직접 바꾼 경우에만 보낸다. (처음 승인할 때의 기본값은 서버가 역할에 따라 정한다.)
			if (i.status === '사용' || draft.canViewDonors !== i.canViewDonors) body.canViewDonors = donorOk && draft.canViewDonors;
			await auth.guard(() => auth.api('POST', '/admin/access/' + i.id, body));
			notice = i.name + ' 님의 설정을 저장했어요.';
			editing = null;
			await load();
		} catch (e) {
			if (!(e instanceof Error && e.message.includes('취소'))) err = e instanceof Error ? e.message : '저장하지 못했습니다.';
		} finally {
			busy = false;
		}
	}
	async function reject(i: Item) {
		if (!confirm(i.name + ' 님의 신청을 거절할까요? (본인이 다시 신청할 수 있어요)')) return;
		busy = true;
		try {
			await auth.guard(() => auth.api('POST', '/admin/access/' + i.id + '/reject', {}));
			notice = i.name + ' 님의 신청을 거절했어요.';
			await load();
		} catch (e) {
			err = e instanceof Error ? e.message : '처리하지 못했습니다.';
		} finally {
			busy = false;
		}
	}
	const tagClass = (s: string) => (s === '사용' ? 'now' : s === '정지' ? '' : 'part');
</script>

<div class="row page-head">
	<h1>재정 사용 승인 · 역할</h1>
	<span class="sp"></span>
	<div class="row">
		<button class="ghost sm" class:on={tab === 'users'} type="button" onclick={() => (tab = 'users')}>사용자</button>
		<button class="ghost sm" class:on={tab === 'log'} type="button" onclick={() => { tab = 'log'; loadLog(); }}>열람 기록</button>
	</div>
</div>

{#if err}<div class="warn">{err}</div>{/if}
{#if notice}<div class="note">{notice}</div>{/if}

{#if tab === 'users'}
	{#if pending.length}
		<div class="card">
			<div class="card-head"><h2>승인 대기 <span class="tag part">{pending.length}</span></h2><span class="sub">교적 계정으로 신청한 분</span></div>
			{#each pending as i (i.id)}
				<div class="pcard" style="margin-bottom:.5rem;flex-wrap:wrap">
					<span class="avatar">{i.name.slice(0, 1)}</span>
					<div class="grow"><b>{i.name}</b> <small>{i.loginId} · {i.requestedAt} 신청</small><small>{i.reason || '(사유 없음)'}</small></div>
					{#if !i.linked}<span class="tag part">교인 연결 안 됨</span>{/if}
					{#if editing !== i.id}
						<button class="sm" type="button" disabled={!i.linked} onclick={() => edit(i, true)}>승인하기</button>
						<button class="ghost sm" type="button" disabled={busy} onclick={() => reject(i)}>거절</button>
					{/if}
				</div>
				{#if editing === i.id}
					{@render editor(i)}
				{/if}
			{/each}
		</div>
	{/if}

	<div class="card">
		<div class="card-head"><h2>사용자</h2><span class="sub">재정 사용이 승인된 교적 계정</span></div>
		{#each others as i (i.id)}
			<div class="pcard" style="margin-bottom:.5rem;flex-wrap:wrap">
				<span class="avatar">{i.name.slice(0, 1)}</span>
				<div class="grow">
					<b>{i.name}</b> <span class="tag {tagClass(i.status)}">{i.status}</span>
					{#if i.isAdmin}<span class="sc">재정 관리자</span>{/if}
					{#each i.roles as r}<span class="sc {r === '담임목사' ? 'pastor' : ''}">{r}</span>{/each}
					<small>{i.loginId}{i.lastLoginAt ? ' · 마지막 접속 ' + i.lastLoginAt : ''}{i.canViewDonors ? ' · 개인별 헌금 열람 가능' : ''}</small>
				</div>
				{#if editing !== i.id}<button class="ghost sm" type="button" onclick={() => edit(i)}>설정</button>{/if}
			</div>
			{#if editing === i.id}
				{@render editor(i)}
			{/if}
		{:else}
			<p class="muted">아직 승인된 사용자가 없어요.</p>
		{/each}
	</div>

	<div class="card">
		<div class="card-head"><h2>역할이 할 수 있는 일</h2></div>
		<div class="scroll-x">
			<table class="sm">
				<tbody>
					<tr><td><span class="sc">재정 관리자</span></td><td>재정 사용 승인, 역할 지정, 설정. 재정 업무는 아래 역할을 따로 받아야 합니다.</td></tr>
					{#each Object.entries(ROLE_HELP) as [r, h]}
						<tr><td><span class="sc {r === '담임목사' ? 'pastor' : ''}">{r}</span></td><td>{h}</td></tr>
					{/each}
				</tbody>
			</table>
		</div>
	</div>
{:else}
	<div class="card">
		<div class="card-head">
			<h2>{logKind === 'donor' ? '개인별 헌금 · 주민번호 열람 기록' : '모든 접속 기록'}</h2>
			<button class="ghost sm" type="button" onclick={() => { logKind = logKind === 'donor' ? 'all' : 'donor'; loadLog(); }}>{logKind === 'donor' ? '전체 보기' : '열람만 보기'}</button>
		</div>
		<div class="scroll-x">
			<table class="sm">
				<thead><tr><th>일시</th><th>사람</th><th>내용</th></tr></thead>
				<tbody>
					{#each log as l}
						<tr><td>{l.at}</td><td>{l.who}</td><td>{l.action}{l.target ? ' · ' + l.target : ''}</td></tr>
					{:else}
						<tr><td colspan="3" class="muted">기록이 없어요.</td></tr>
					{/each}
				</tbody>
			</table>
		</div>
	</div>
{/if}

{#snippet editor(i: Item)}
	<div class="card" style="background:var(--primary-50);margin:.2rem 0 .8rem">
		<div class="role-row" style="grid-template-columns:6rem 1fr">
			<label for="st{i.id}">상태</label>
			<div>
				<select id="st{i.id}" bind:value={draft.status} style="width:auto">
					<option>신청 대기</option><option>사용</option><option>정지</option>
				</select>
			</div>
		</div>
		<div class="role-row" style="grid-template-columns:6rem 1fr">
			<label for="ad{i.id}">재정 관리자</label>
			<div class="row"><label class="switch"><input id="ad{i.id}" type="checkbox" bind:checked={draft.isAdmin} /><i></i></label><span class="fs-s muted">승인·역할·설정을 맡습니다</span></div>
		</div>
		<div class="role-row" style="grid-template-columns:6rem 1fr">
			<span style="font-weight:800;padding-top:.5rem">역할</span>
			<div>
				<div class="row" style="flex-wrap:wrap;gap:.4rem">
					{#each roles as r}
						<button type="button" class="sc {draft.roles.includes(r) ? '' : 'c'}" onclick={() => toggleRole(r)} aria-pressed={draft.roles.includes(r)}>{draft.roles.includes(r) ? '✓ ' : ''}{r}</button>
					{/each}
				</div>
				<p class="fs-s muted" style="margin:.5rem 0 0">{draft.roles.map((r) => r + ': ' + ROLE_HELP[r]).join(' / ') || '역할을 고르지 않으면 보고서도 볼 수 없어요.'}</p>
			</div>
		</div>
		<div class="role-row" style="grid-template-columns:6rem 1fr">
			<label for="dv{i.id}">개인별 헌금</label>
			<div>
				<div class="row"><label class="switch"><input id="dv{i.id}" type="checkbox" bind:checked={draft.canViewDonors} disabled={!donorOk} /><i></i></label><span class="fs-s muted">{donorOk ? '켜고 끈 기록이 남습니다' : '재정 담당·재정위원장·담임목사·감사 역할이 있어야 켤 수 있어요'}</span></div>
			</div>
		</div>
		<div class="row" style="justify-content:flex-end">
			<button class="ghost" type="button" onclick={() => (editing = null)}>닫기</button>
			<button type="button" disabled={busy} onclick={() => save(i)}>저장</button>
		</div>
	</div>
{/snippet}

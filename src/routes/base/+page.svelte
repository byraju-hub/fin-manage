<script lang="ts">
	// 기준 자료: 회계연도, 회계·계좌, 수입 항목 (재정 관리자·재정 담당이 고치고, 다른 사람은 보기만)
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';

	type Year = { year: number; start: string; end: string; status: string };
	type Fund = { id: number; name: string; isDefault: boolean; active: boolean };
	type Wallet = { id: number; fundId: number; name: string; kind: string; bank: string | null; acctTail: string | null; opening: number; active: boolean };
	type Acct = { id: number; parentId: number | null; name: string; isOffering: boolean; named: boolean; receiptable: boolean; needsUnit: boolean; needsPurpose: boolean; active: boolean };

	let years = $state<Year[]>([]);
	let funds = $state<Fund[]>([]);
	let wallets = $state<Wallet[]>([]);
	let accts = $state<Acct[]>([]);
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	const canEdit = $derived(!!auth.me?.access?.isAdmin || !!auth.me?.access?.roles.includes('재정 담당'));

	const thisYear = new Date().getFullYear();
	let ny = $state({ year: thisYear + 1, start: '', end: '' });
	$effect(() => {
		ny.start = `${ny.year}-01-01`;
		ny.end = `${ny.year}-12-31`;
	});
	let nf = $state('');
	let nw = $state({ fundId: 0, name: '', kind: '통장', bank: '', acctTail: '', opening: '' });
	let na = $state<{ parentId: number | null; name: string }>({ parentId: null, name: '' });

	async function load() {
		try {
			const b = await auth.api<{ years: Year[]; funds: Fund[]; wallets: Wallet[]; incomeAccounts: Acct[] }>('GET', '/base');
			years = b.years;
			funds = b.funds;
			wallets = b.wallets;
			accts = b.incomeAccounts;
			if (!nw.fundId && funds.length) nw.fundId = funds[0].id;
			err = '';
		} catch (e) {
			err = e instanceof Error ? e.message : '불러오지 못했습니다.';
		}
	}
	onMount(load);

	/** 저장 공통: 오류는 위에 보여 주고, 성공하면 다시 불러옴 */
	async function run(fn: () => Promise<unknown>, ok: string) {
		busy = true;
		err = '';
		notice = '';
		try {
			await auth.guard(fn);
			notice = ok;
			await load();
			return true;
		} catch (e) {
			if (!(e instanceof Error && e.message.includes('취소'))) err = e instanceof Error ? e.message : '저장하지 못했습니다.';
			return false;
		} finally {
			busy = false;
		}
	}

	const addYear = () => run(() => auth.api('POST', '/fiscal-years', ny), ny.year + '년 회계연도를 만들었어요.');
	const setYear = (y: Year, status: string) => {
		if (!confirm(`${y.year}년을 '${status}' 상태로 바꿀까요?`)) return;
		run(() => auth.api('POST', `/fiscal-years/${y.year}/status`, { status }), `${y.year}년을 ${status} 상태로 바꿨어요.`);
	};
	async function addFund() {
		if (await run(() => auth.api('POST', '/funds', { name: nf }), '회계를 추가했어요.')) nf = '';
	}
	async function addWallet() {
		const opening = nw.opening.trim() === '' ? 0 : Number(nw.opening.replace(/,/g, ''));
		if (await run(() => auth.api('POST', '/wallets', { ...nw, opening }), '계좌를 추가했어요.')) nw = { ...nw, name: '', bank: '', acctTail: '', opening: '' };
	}
	const toggleWallet = (w: Wallet) => run(() => auth.api('POST', '/wallets/' + w.id, { active: !w.active }), w.name + (w.active ? ' 사용을 중지했어요.' : ' 다시 사용해요.'));
	async function addAcct() {
		if (await run(() => auth.api('POST', '/income-accounts', { parentId: na.parentId, name: na.name, isOffering: true }), '수입 항목을 추가했어요.')) na.name = '';
	}
	const patchAcct = (a: Acct, body: Record<string, boolean>) => run(() => auth.api('POST', '/income-accounts/' + a.id, body), '저장했어요.');

	// 수입 항목 트리를 순서대로 펼침 (깊이 포함)
	const flat = $derived.by(() => {
		const out: { a: Acct; depth: number }[] = [];
		const walk = (pid: number | null, depth: number) => {
			for (const a of accts.filter((x) => x.parentId === pid)) {
				out.push({ a, depth });
				walk(a.id, depth + 1);
			}
		};
		walk(null, 0);
		return out;
	});
	const depthOf = (id: number | null): number => {
		let d = 0;
		while (id !== null) {
			d++;
			id = accts.find((x) => x.id === id)?.parentId ?? null;
		}
		return d;
	};
	const won = (n: number) => n.toLocaleString('ko-KR') + '원';
</script>

<div class="row page-head"><h1>기준 자료</h1></div>
{#if err}<div class="warn">{err}</div>{/if}
{#if notice}<div class="note">{notice}</div>{/if}
{#if !canEdit}<div class="note">보기만 할 수 있어요. 고치는 것은 재정 관리자와 재정 담당입니다.</div>{/if}

<div class="card">
	<div class="card-head"><h2>회계연도</h2><span class="sub">편성 중 → 진행 → 마감 순서로만 바뀝니다</span></div>
	<div class="scroll-x">
		<table class="sm">
			<thead><tr><th>연도</th><th>기간</th><th>상태</th><th></th></tr></thead>
			<tbody>
				{#each years as y (y.year)}
					<tr>
						<td><b>{y.year}</b></td><td>{y.start} ~ {y.end}</td>
						<td><span class="tag {y.status === '진행' ? 'now' : y.status === '마감' ? '' : 'part'}">{y.status}</span></td>
						<td class="right">
							{#if canEdit && y.status === '편성 중'}<button class="ghost sm" type="button" disabled={busy} onclick={() => setYear(y, '진행')}>진행 시작</button>{/if}
							{#if canEdit && y.status === '진행'}<button class="ghost sm" type="button" disabled={busy} onclick={() => setYear(y, '마감')}>마감</button>{/if}
						</td>
					</tr>
				{:else}
					<tr><td colspan="4" class="muted">회계연도가 없어요.</td></tr>
				{/each}
			</tbody>
		</table>
	</div>
	{#if canEdit}
		<form class="row mt" style="flex-wrap:wrap" onsubmit={(e) => { e.preventDefault(); addYear(); }}>
			<input type="number" bind:value={ny.year} min="2000" max="2100" style="width:6.5rem" aria-label="연도" />
			<input type="date" bind:value={ny.start} style="width:auto" aria-label="시작일" /> ~
			<input type="date" bind:value={ny.end} style="width:auto" aria-label="종료일" />
			<button class="sm" type="submit" disabled={busy}>회계연도 추가</button>
		</form>
	{/if}
</div>

<div class="card">
	<div class="card-head"><h2>회계 · 계좌</h2><span class="sub">기본은 일반회계 하나, 건축·선교 같은 특별회계를 더할 수 있어요</span></div>
	{#each funds as f (f.id)}
		<h3 style="margin:.8rem 0 .3rem">{f.name} {#if f.isDefault}<span class="tag now">기본</span>{/if}</h3>
		<div class="scroll-x">
			<table class="sm">
				<thead><tr><th>계좌</th><th>종류</th><th>은행 · 끝자리</th><th class="right">시작 잔액</th><th></th></tr></thead>
				<tbody>
					{#each wallets.filter((w) => w.fundId === f.id) as w (w.id)}
						<tr class:muted={!w.active}>
							<td>{w.name}</td><td>{w.kind}</td><td>{[w.bank, w.acctTail ? '···' + w.acctTail : ''].filter(Boolean).join(' ')}</td>
							<td class="right money">{won(w.opening)}</td>
							<td class="right">{#if canEdit}<button class="ghost sm" type="button" disabled={busy} onclick={() => toggleWallet(w)}>{w.active ? '중지' : '다시 사용'}</button>{/if}</td>
						</tr>
					{:else}
						<tr><td colspan="5" class="muted">계좌가 없어요.</td></tr>
					{/each}
				</tbody>
			</table>
		</div>
	{/each}
	{#if canEdit}
		<form class="form mt" onsubmit={(e) => { e.preventDefault(); addWallet(); }}>
			<h3 style="grid-column:1/-1;margin:.6rem 0 0">계좌 추가</h3>
			<select bind:value={nw.fundId} aria-label="회계">{#each funds as f}<option value={f.id}>{f.name}</option>{/each}</select>
			<input bind:value={nw.name} placeholder="계좌 이름 (예: 국민 주거래)" maxlength="40" required />
			<select bind:value={nw.kind} aria-label="종류"><option>통장</option><option>현금</option></select>
			<input bind:value={nw.bank} placeholder="은행" maxlength="30" />
			<input bind:value={nw.acctTail} placeholder="계좌번호 끝 4자리" maxlength="10" inputmode="numeric" />
			<input bind:value={nw.opening} placeholder="시작 잔액 (원)" inputmode="numeric" />
			<button class="sm" type="submit" disabled={busy}>계좌 추가</button>
		</form>
		<p class="fs-s muted">계좌번호는 끝 몇 자리만 저장합니다. 전체 번호는 넣지 마세요.</p>
		<form class="row mt" onsubmit={(e) => { e.preventDefault(); addFund(); }}>
			<input bind:value={nf} placeholder="특별회계 이름 (예: 건축회계)" maxlength="40" style="max-width:18rem" required />
			<button class="ghost sm" type="submit" disabled={busy}>회계 추가</button>
		</form>
	{/if}
</div>

<div class="card">
	<div class="card-head"><h2>수입 항목</h2><span class="sub">최대 3단계 · 헌금 종류도 여기서 정합니다</span></div>
	<div class="scroll-x">
		<table class="sm">
			<thead><tr><th>항목</th><th>이름 적음</th><th>영수증 포함</th><th>사용</th></tr></thead>
			<tbody>
				{#each flat as { a, depth } (a.id)}
					<tr class:muted={!a.active}>
						<td style="padding-left:{0.6 + depth * 1.3}rem">{depth ? '└ ' : ''}<b>{a.name}</b>
							{#if a.needsUnit}<small class="muted">부서 선택</small>{/if}{#if a.needsPurpose}<small class="muted">목적 입력</small>{/if}</td>
						<td><label class="switch"><input type="checkbox" checked={a.named} disabled={!canEdit || busy} onchange={() => patchAcct(a, { named: !a.named })} /><i></i></label></td>
						<td><label class="switch"><input type="checkbox" checked={a.receiptable} disabled={!canEdit || busy} onchange={() => patchAcct(a, { receiptable: !a.receiptable })} /><i></i></label></td>
						<td><label class="switch"><input type="checkbox" checked={a.active} disabled={!canEdit || busy} onchange={() => patchAcct(a, { active: !a.active })} /><i></i></label></td>
					</tr>
				{/each}
			</tbody>
		</table>
	</div>
	{#if canEdit}
		<form class="row mt" style="flex-wrap:wrap" onsubmit={(e) => { e.preventDefault(); addAcct(); }}>
			<select bind:value={na.parentId} style="width:auto" aria-label="상위 항목">
				<option value={null}>(최상위)</option>
				{#each flat.filter((x) => depthOf(x.a.id) < 3) as { a, depth } (a.id)}<option value={a.id}>{'· '.repeat(depth)}{a.name} 아래</option>{/each}
			</select>
			<input bind:value={na.name} placeholder="새 항목 이름" maxlength="40" style="max-width:16rem" required />
			<button class="sm" type="submit" disabled={busy}>항목 추가</button>
		</form>
		<p class="fs-s muted">항목은 지우지 않고 '사용'을 끕니다. 이미 헌금이 기록된 항목의 '영수증 포함'을 바꾸면 변경 기록이 남아요.</p>
	{/if}
</div>

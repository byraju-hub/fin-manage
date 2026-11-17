<script lang="ts">
	// 행사(프로젝트) 정산: 수련회·기도회처럼 회비(수입)와 지출을 한 행사로 묶어 따로 정산한다.
	// 지출결의서 내역·장부 수입/지출에서 '행사'를 고르면 여기에 모인다.
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { won, errText } from '#lib/fmt.ts';

	type Ev = { id: number; name: string; unitId: number | null; unit: string | null; startsOn: string | null; endsOn: string | null; budget: number; status: string; note: string | null; income: number; expense: number; pending: number; net: number };
	type Tx = { id: number; date: string; kind: string; amount: number; summary: string; vendor: string | null; acct: string | null; item: string | null };
	type Detail = Ev & { transactions: Tx[]; pendingItems: { docNo: string; title: string; status: string; summary: string; amount: number }[] };
	type Unit = { id: number; name: string; kind: string };

	let years = $state<number[]>([]);
	let year = $state(0);
	let items = $state<Ev[]>([]);
	let units = $state<Unit[]>([]);
	let det = $state<Detail | null>(null);
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let f = $state({ name: '', unitId: 0, startsOn: '', endsOn: '', budget: '', note: '' });
	const canEdit = $derived(!!auth.me?.access?.isAdmin || !!auth.me?.access?.roles.includes('재정 담당'));

	async function load() {
		if (!year) return;
		try {
			items = (await auth.api<{ items: Ev[] }>('GET', '/events?year=' + year)).items;
			units = (await auth.api<{ items: Unit[] }>('GET', '/units?year=' + year)).items;
			if (det) det = await auth.api<Detail>('GET', '/events/' + det.id);
			err = '';
		} catch (e) {
			err = errText(e);
		}
	}
	onMount(async () => {
		try {
			const r = await auth.api<{ years: { year: number; status: string }[] }>('GET', '/base');
			years = r.years.map((y) => y.year);
			year = (r.years.find((y) => y.status === '진행') ?? r.years[0])?.year ?? 0;
			await load();
		} catch (e) {
			err = errText(e);
		}
	});
	async function run(fn: () => Promise<unknown>, ok = '') {
		busy = true;
		err = '';
		notice = '';
		try {
			await auth.guard(fn);
			if (ok) notice = ok;
			await load();
			return true;
		} catch (e) {
			if (!(e instanceof Error && e.message.includes('취소'))) err = errText(e);
			return false;
		} finally {
			busy = false;
		}
	}
	async function add() {
		if (await run(() => auth.api('POST', '/events', { year, name: f.name, unitId: f.unitId || null, startsOn: f.startsOn || null, endsOn: f.endsOn || null, budget: Number(f.budget.replace(/,/g, '')) || 0, note: f.note }), '행사를 만들었어요. 이제 결의서·장부에서 이 행사를 고를 수 있어요.')) f = { name: '', unitId: 0, startsOn: '', endsOn: '', budget: '', note: '' };
	}
	const open = async (e: Ev) => (det = await auth.api<Detail>('GET', '/events/' + e.id));
	const setStatus = (e: Ev, status: string) => run(() => auth.api('POST', '/events/' + e.id, { status }), status === '종료' ? '행사를 종료했어요. 새 수입·지출은 연결할 수 없어요.' : '행사를 다시 열었어요.');
	const del = (e: Ev) => confirm(`'${e.name}' 행사를 지울까요?`) && run(() => auth.api('POST', `/events/${e.id}/delete`, {}), '지웠어요.').then(() => { if (det?.id === e.id) det = null; });
	const editBudget = (e: Ev) => {
		const v = prompt(`'${e.name}' 행사 예산(원)`, String(e.budget));
		if (v !== null) run(() => auth.api('POST', '/events/' + e.id, { budget: Number(v.replace(/,/g, '')) || 0 }));
	};
	const rate = (e: Ev) => (e.budget > 0 ? Math.round((e.expense * 100) / e.budget) + '%' : '');
</script>

<div class="row page-head no-print">
	<h1>행사 정산</h1>
	<span class="sp"></span>
	<select bind:value={year} onchange={() => { det = null; load(); }} style="width:auto" aria-label="회계연도">{#each years as y}<option value={y}>{y}년</option>{/each}</select>
	{#if det}<button class="ghost sm" type="button" onclick={() => window.print()}>정산서 인쇄</button>{/if}
</div>
{#if err}<div class="warn no-print">{err}</div>{/if}
{#if notice}<div class="note no-print">{notice}</div>{/if}

{#if canEdit}
	<details class="card no-print">
		<summary style="cursor:pointer"><b>새 행사 만들기</b> <span class="muted fs-s">수련회·기도회·바자회처럼 따로 정산할 일</span></summary>
		<form class="form mt" onsubmit={(e) => { e.preventDefault(); add(); }}>
			<input bind:value={f.name} placeholder="행사 이름 (예: 여름 수련회)" maxlength="60" required />
			<select bind:value={f.unitId} aria-label="주관 부서"><option value={0}>주관 부서 (선택)</option>{#each units.filter((u) => u.kind !== '예비비') as u}<option value={u.id}>{u.name}</option>{/each}</select>
			<div class="row"><input type="date" bind:value={f.startsOn} aria-label="시작일" /> ~ <input type="date" bind:value={f.endsOn} aria-label="끝나는 날" /></div>
			<input bind:value={f.budget} placeholder="행사 예산 (원, 선택)" inputmode="numeric" />
			<input bind:value={f.note} placeholder="메모 (선택)" maxlength="255" />
			<button type="submit" disabled={busy}>만들기</button>
		</form>
	</details>
{/if}

<div class="two two-l">
	<div class="card no-print">
		<div class="card-head"><h2>{year}년 행사</h2><span class="sub">{items.length}개</span></div>
		{#each items as e (e.id)}
			<button type="button" class="pcard ev" class:on={det?.id === e.id} onclick={() => open(e)}>
				<div class="grow"><b>{e.name}</b> <span class="tag {e.status === '진행' ? 'now' : ''}">{e.status}</span><small>{e.unit ?? '부서 없음'}{e.startsOn ? ' · ' + e.startsOn : ''}</small></div>
				<div style="text-align:right"><b class="money">{won(e.net)}</b><small>수입 {won(e.income)} · 지출 {won(e.expense)}</small></div>
			</button>
		{:else}
			<p class="muted">아직 행사가 없어요. 위에서 만들면 결의서·장부에서 연결할 수 있어요.</p>
		{/each}
	</div>

	<div class="card">
		{#if det}
			<div class="card-head"><h2>{det.name} 정산</h2><span class="tag {det.status === '진행' ? 'now' : ''}">{det.status}</span></div>
			<p class="fs-s muted">{det.unit ?? ''}{det.startsOn ? ' · ' + det.startsOn : ''}{det.endsOn ? ' ~ ' + det.endsOn : ''}{det.note ? ' · ' + det.note : ''}</p>
			<div class="kpis">
				<div class="kpi"><div><b>{won(det.income)}<span class="of">원</span></b><span>수입 (회비 등)</span></div></div>
				<div class="kpi"><div><b>{won(det.expense)}<span class="of">원</span></b><span>지출{det.budget ? ' · 예산 ' + won(det.budget) + ' (' + rate(det) + ')' : ''}</span></div></div>
				<div class="kpi"><div><b>{won(det.net)}<span class="of">원</span></b><span>남은 금액(수입 − 지출)</span></div></div>
				{#if det.pending}<div class="kpi"><div><b>{won(det.pending)}<span class="of">원</span></b><span>결재 중(지급 전)</span></div></div>{/if}
			</div>
			<div class="scroll-x"><table class="sm"><thead><tr><th>날짜</th><th>구분</th><th>적요</th><th class="right">금액</th></tr></thead>
				<tbody>
					{#each det.transactions as t (t.id)}<tr><td>{t.date}</td><td><span class="tag {t.kind === '수입' ? 'now' : 'part'}">{t.kind}</span></td><td>{t.summary}{#if t.acct || t.item}<small class="muted"> {t.acct ?? t.item}</small>{/if}</td><td class="right money">{won(t.amount)}</td></tr>
					{:else}<tr><td colspan="4" class="muted">연결된 수입·지출이 없어요.</td></tr>{/each}
					{#each det.pendingItems as p}<tr class="muted"><td></td><td>결재 중</td><td>{p.docNo} {p.title} · {p.summary} ({p.status})</td><td class="right">{won(p.amount)}</td></tr>{/each}
				</tbody></table></div>
			{#if canEdit}
				<div class="row mt no-print" style="flex-wrap:wrap">
					<button class="ghost sm" type="button" disabled={busy} onclick={() => editBudget(det!)}>예산 고치기</button>
					<button class="ghost sm" type="button" disabled={busy} onclick={() => setStatus(det!, det!.status === '진행' ? '종료' : '진행')}>{det.status === '진행' ? '행사 종료(정산 마침)' : '다시 열기'}</button>
					<button class="ghost sm" type="button" disabled={busy} onclick={() => del(det!)}>지우기</button>
				</div>
			{/if}
		{:else}
			<p class="muted">왼쪽에서 행사를 고르면 수입·지출 정산이 보여요.</p>
		{/if}
	</div>
</div>

<style>
	.ev { display: flex; justify-content: space-between; gap: 0.6rem; align-items: center; width: 100%; text-align: left; background: #fff; color: inherit; border: 1px solid var(--line); border-radius: 12px; padding: 0.6rem 0.8rem; margin-bottom: 0.4rem; cursor: pointer; }
	.ev.on { border-color: var(--primary); background: var(--primary-50); }
	.ev small { display: block; color: var(--muted); font-size: 0.74rem; }
</style>

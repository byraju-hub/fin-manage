<script lang="ts">
	// 보고서: 주간 헌금, 주보용 명단, 개인별 내역, 월 재정, 집행 현황
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import DonorPicker, { type Donor } from '#lib/DonorPicker.svelte';
	import { won, localDate, errText } from '#lib/fmt.ts';
	import { hrefTo } from '#lib/paths.ts';

	const roles = $derived(auth.me?.access?.roles ?? []);
	const canTotals = $derived(roles.some((r) => ['재정 담당', '재정위원장', '담임목사', '감사', '열람'].includes(r)));
	const isStaff = $derived(roles.includes('재정 담당'));
	const canDonor = $derived(!!auth.me?.access?.canViewDonors);

	type Tab = 'weekly' | 'bulletin' | 'person' | 'monthly' | 'exec' | 'settle';
	let tab = $state<Tab>('weekly');
	let err = $state('');
	const t = localDate();
	const wk = (end: string) => {
		const d = new Date(end + 'T00:00:00');
		d.setDate(d.getDate() - 6);
		return localDate(d);
	};
	let range = $state({ from: wk(t), to: t });
	type W = { from: string; to: string; items: { id: number; name: string; count: number; sum: number; prev: number; lastYear: number }[]; openBatches: number; batches: { batch_date: string; kind: string; counters: string | null; status: string }[] };
	let weekly = $state<W | null>(null);
	let bulletin = $state<{ groups: { account: string; names: string[] }[] } | null>(null);
	let hist = $state<{ donor: string | null; items: { d: string; acct: string; amount: number; method: string; memo: string | null; status: string }[]; total: number } | null>(null);
	let who = $state('');
	let whoName = $state('');
	let hr = $state({ from: t.slice(0, 4) + '-01-01', to: t.slice(0, 4) + '-12-31' });
	let ym = $state({ year: Number(t.slice(0, 4)), month: Number(t.slice(5, 7)) });
	type M = { period: string; carryIn: number; carryOut: number; income: { name: string; month: number; cum: number }[]; expense: { name: string; month: number; cum: number }[]; incomeTotal: number; expenseTotal: number; wallets: { name: string; balance: number }[] };
	let monthly = $state<M | null>(null);
	type X = { units: { unit: string; items: { name: string; budget: number; paid: number; pending: number; remain: number; rate: number }[] }[]; income: { name: string; budget: number; actual: number }[] };
	let exec = $state<X | null>(null);
	type S = { year: number; income: { name: string; budget: number; actual: number; prior: number }[]; expense: { unit: string; items: { name: string; budget: number; actual: number }[] }[]; carryIn: number; wallets: { name: string; balance: number }[] };
	let settle = $state<S | null>(null);

	async function load() {
		err = '';
		try {
			if (tab === 'weekly') weekly = await auth.api<W>('GET', `/reports/weekly?from=${range.from}&to=${range.to}`);
			else if (tab === 'bulletin') bulletin = await auth.api('GET', `/reports/bulletin?from=${range.from}&to=${range.to}`);
			else if (tab === 'person') {
				if (who) hist = await auth.api('GET', `/donors/history?donor=${encodeURIComponent(who)}&from=${hr.from}&to=${hr.to}`);
			} else if (tab === 'monthly') monthly = await auth.api<M>('GET', `/reports/monthly?year=${ym.year}&month=${ym.month}`);
			else if (tab === 'settle') settle = await auth.api<S>('GET', `/reports/settlement?year=${ym.year}`);
			else exec = await auth.api<X>('GET', `/reports/execution?year=${ym.year}`);
		} catch (e) {
			err = errText(e);
		}
	}
	onMount(() => {
		if (!canTotals && canDonor) tab = 'person';
		load();
	});
	const go = (x: Tab) => {
		tab = x;
		load();
	};
	const total = $derived(weekly?.items.reduce((s, i) => s + i.sum, 0) ?? 0);
	const totalPrev = $derived(weekly?.items.reduce((s, i) => s + i.prev, 0) ?? 0);
	const totalLy = $derived(weekly?.items.reduce((s, i) => s + i.lastYear, 0) ?? 0);
</script>

<div class="row page-head no-print">
	<h1>보고서</h1>
	<span class="sp"></span>
	<div class="row" style="flex-wrap:wrap">
		{#if canTotals}
			<button class="ghost sm" class:on={tab === 'weekly'} type="button" onclick={() => go('weekly')}>주간 헌금</button>
			<button class="ghost sm" class:on={tab === 'monthly'} type="button" onclick={() => go('monthly')}>월 재정</button>
			<button class="ghost sm" class:on={tab === 'exec'} type="button" onclick={() => go('exec')}>집행 현황</button>
			<button class="ghost sm" class:on={tab === 'settle'} type="button" onclick={() => go('settle')}>결산서</button>
		{/if}
		{#if isStaff}<button class="ghost sm" class:on={tab === 'bulletin'} type="button" onclick={() => go('bulletin')}>주보용 명단</button>{/if}
		{#if canDonor}<button class="ghost sm" class:on={tab === 'person'} type="button" onclick={() => go('person')}>개인별 내역</button>{/if}
		<button class="ghost sm" type="button" onclick={() => window.print()}>인쇄</button>
	</div>
</div>
{#if err}<div class="warn">{err}</div>{/if}

{#if tab === 'weekly' || tab === 'bulletin'}
	<form class="row no-print" style="flex-wrap:wrap" onsubmit={(e) => { e.preventDefault(); load(); }}>
		<input type="date" bind:value={range.from} style="width:auto" aria-label="시작일" /> ~ <input type="date" bind:value={range.to} style="width:auto" aria-label="끝일" />
		<button class="sm" type="submit">보기</button>
	</form>
{:else if tab === 'monthly' || tab === 'exec' || tab === 'settle'}
	<form class="row no-print" style="flex-wrap:wrap" onsubmit={(e) => { e.preventDefault(); load(); }}>
		<input type="number" bind:value={ym.year} style="width:6.5rem" aria-label="연도" />년
		{#if tab === 'settle'}<a class="ghost sm" style="padding:.35rem .7rem;border:1px solid var(--line-strong);border-radius:10px" href={hrefTo('/api/v1/export/settlement') + '?year=' + ym.year}>엑셀로 받기</a>{/if}
		{#if tab === 'monthly'}<select bind:value={ym.month} style="width:auto" aria-label="월">{#each Array.from({ length: 12 }, (_, i) => i + 1) as m}<option value={m}>{m}월</option>{/each}</select>{/if}
		<button class="sm" type="submit">보기</button>
	</form>
{/if}

{#if tab === 'weekly' && weekly}
	<div class="card">
		<div class="card-head"><h2>주간 헌금 보고</h2><span class="sub">{weekly.from} ~ {weekly.to} · 마감된 헌금</span></div>
		{#if weekly.openBatches}<div class="warn fs-s">아직 마감하지 않은 헌금 묶음이 {weekly.openBatches}개 있어요. 합계에는 마감된 것만 들어갑니다.</div>{/if}
		<div class="scroll-x">
			<table class="sm">
				<thead><tr><th>종류</th><th>건수</th><th class="right">이번</th><th class="right">지난 기간</th><th class="right">작년 같은 때</th></tr></thead>
				<tbody>
					{#each weekly.items.filter((i) => i.sum || i.prev || i.lastYear) as i (i.id)}
						<tr><td>{i.name}</td><td>{i.count}</td><td class="right money">{won(i.sum)}</td><td class="right">{won(i.prev)}</td><td class="right">{won(i.lastYear)}</td></tr>
					{:else}
						<tr><td colspan="5" class="muted">이 기간의 마감된 헌금이 없어요.</td></tr>
					{/each}
					<tr class="tot"><td>합계</td><td></td><td class="right money">{won(total)}</td><td class="right">{won(totalPrev)}</td><td class="right">{won(totalLy)}</td></tr>
				</tbody>
			</table>
		</div>
		{#if weekly.batches.length}<p class="fs-s muted">{weekly.batches.map((b) => `${b.batch_date} ${b.kind}${b.counters ? ' (계수 ' + b.counters + ')' : ''}`).join(' · ')}</p>{/if}
	</div>
{:else if tab === 'bulletin' && bulletin}
	<div class="card">
		<div class="card-head"><h2>주보용 헌금자 명단</h2><span class="sub">금액 없음 · 싣지 않기로 표시한 분 제외</span></div>
		{#each bulletin.groups as g}
			<p><b>{g.account}</b> — {g.names.join(', ')}</p>
		{:else}
			<p class="muted">이 기간에 이름이 있는 마감된 헌금이 없어요.</p>
		{/each}
	</div>
{:else if tab === 'person'}
	<div class="card no-print">
		<div class="card-head"><h2>개인별 헌금 내역</h2><span class="sub">열 때마다 접속 기록이 남습니다</span></div>
		<div class="row" style="flex-wrap:wrap;align-items:center">
			<div style="min-width:14rem;flex:1"><DonorPicker onpick={(d: Donor) => { who = d.key; whoName = d.label; load(); }} /></div>
			{#if whoName}<span class="sc">{whoName}</span>{/if}
			<input type="date" bind:value={hr.from} style="width:auto" /> ~ <input type="date" bind:value={hr.to} style="width:auto" />
			<button class="sm" type="button" onclick={load}>보기</button>
		</div>
	</div>
	{#if hist}
		<div class="card">
			<div class="card-head"><h2>{hist.donor ?? '헌금 내역 없음'}</h2><span class="sub">마감된 합계 {won(hist.total)}원</span></div>
			<table class="sm"><tbody>
				{#each hist.items as h}<tr class:muted={h.status !== '마감'}><td>{h.d}</td><td>{h.acct}</td><td class="right money">{won(h.amount)}</td><td>{h.method}{h.status !== '마감' ? ' (미마감)' : ''}</td><td>{h.memo ?? ''}</td></tr>{/each}
			</tbody></table>
		</div>
	{/if}
{:else if tab === 'monthly' && monthly}
	<div class="card">
		<div class="card-head"><h2>{monthly.period} 월 재정 보고</h2><span class="sub">제직회 보고용</span></div>
		<div class="kpis">
			<div class="kpi"><div><b>{won(monthly.carryIn)}</b><span>전월 이월</span></div></div>
			<div class="kpi"><div><b>{won(monthly.incomeTotal)}</b><span>이번 달 수입</span></div></div>
			<div class="kpi"><div><b>{won(monthly.expenseTotal)}</b><span>이번 달 지출</span></div></div>
			<div class="kpi"><div><b>{won(monthly.carryOut)}</b><span>차월 이월</span></div></div>
		</div>
		<div class="two">
			<div class="scroll-x"><table class="sm"><thead><tr><th>수입</th><th class="right">이번 달</th><th class="right">누계</th></tr></thead><tbody>
				{#each monthly.income as r}<tr><td>{r.name}</td><td class="right money">{won(r.month)}</td><td class="right">{won(r.cum)}</td></tr>{:else}<tr><td colspan="3" class="muted">수입이 없어요.</td></tr>{/each}
			</tbody></table></div>
			<div class="scroll-x"><table class="sm"><thead><tr><th>지출</th><th class="right">이번 달</th><th class="right">누계</th></tr></thead><tbody>
				{#each monthly.expense as r}<tr><td>{r.name}</td><td class="right money">{won(r.month)}</td><td class="right">{won(r.cum)}</td></tr>{:else}<tr><td colspan="3" class="muted">지출이 없어요.</td></tr>{/each}
			</tbody></table></div>
		</div>
		<h3>계좌 잔액 (월말)</h3>
		<table class="sm"><tbody>{#each monthly.wallets as w}<tr><td>{w.name}</td><td class="right money">{won(w.balance)}</td></tr>{/each}</tbody></table>
	</div>
{:else if tab === 'exec' && exec}
	<div class="card">
		<div class="card-head"><h2>{ym.year}년 예산 대비 집행 현황</h2><span class="sub">집행 = 지급 완료 · 결재 중은 따로</span></div>
		<div class="scroll-x">
			<table class="sm">
				<thead><tr><th>항목</th><th class="right">예산</th><th class="right">집행</th><th class="right">결재 중</th><th class="right">남은 금액</th><th class="right">집행률</th></tr></thead>
				<tbody>
					{#each exec.units as u}
						<tr class="tot"><td colspan="6">{u.unit}</td></tr>
						{#each u.items as i}<tr><td>{i.name}</td><td class="right">{won(i.budget)}</td><td class="right money">{won(i.paid)}</td><td class="right">{won(i.pending)}</td><td class="right {i.remain < 0 ? 'neg' : ''}">{won(i.remain)}</td><td class="right">{i.rate}%</td></tr>{/each}
					{:else}
						<tr><td colspan="6" class="muted">예산이 아직 없어요.</td></tr>
					{/each}
				</tbody>
			</table>
		</div>
		<h3>수입 예산 대비 실적</h3>
		<table class="sm"><thead><tr><th>수입 항목</th><th class="right">예산</th><th class="right">실적</th></tr></thead><tbody>{#each exec.income as i}<tr><td>{i.name}</td><td class="right">{won(i.budget)}</td><td class="right money">{won(i.actual)}</td></tr>{/each}</tbody></table>
	</div>
{/if}
{#if tab === 'settle' && settle}
	<div class="card">
		<div class="card-head"><h2>{settle.year}년 결산서</h2><span class="sub">공동의회 자료 · 예산 대비 결산, 수입은 전년 대비</span></div>
		<h3>수입</h3>
		<table class="sm"><thead><tr><th>항목</th><th class="right">예산</th><th class="right">결산</th><th class="right">전년</th></tr></thead><tbody>
			{#each settle.income as i}<tr><td>{i.name}</td><td class="right">{won(i.budget)}</td><td class="right money">{won(i.actual)}</td><td class="right">{won(i.prior)}</td></tr>{/each}
			<tr class="tot"><td>합계</td><td class="right">{won(settle.income.reduce((s, i) => s + i.budget, 0))}</td><td class="right money">{won(settle.income.reduce((s, i) => s + i.actual, 0))}</td><td class="right">{won(settle.income.reduce((s, i) => s + i.prior, 0))}</td></tr>
		</tbody></table>
		<h3>지출</h3>
		<table class="sm"><thead><tr><th>항목</th><th class="right">예산(변경 반영)</th><th class="right">결산</th></tr></thead><tbody>
			{#each settle.expense as u}<tr class="tot"><td colspan="3">{u.unit}</td></tr>{#each u.items as i}<tr><td>{i.name}</td><td class="right">{won(i.budget)}</td><td class="right money">{won(i.actual)}</td></tr>{/each}{/each}
			<tr class="tot"><td>합계</td><td class="right">{won(settle.expense.reduce((s, u) => s + u.items.reduce((x, i) => x + i.budget, 0), 0))}</td><td class="right money">{won(settle.expense.reduce((s, u) => s + u.items.reduce((x, i) => x + i.actual, 0), 0))}</td></tr>
		</tbody></table>
		<h3>연말 잔액{settle.carryIn ? ' (전기 이월금 ' + won(settle.carryIn) + '원)' : ''}</h3>
		<table class="sm"><tbody>{#each settle.wallets as w}<tr><td>{w.name}</td><td class="right money">{won(w.balance)}</td></tr>{/each}</tbody></table>
	</div>
{/if}

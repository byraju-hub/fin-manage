<script lang="ts">
	// 마감: 월 마감(실제 잔액 대조) → 재정위원장 승인, 직접 지출 확인, 연 마감(이월)
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { won, errText } from '#lib/fmt.ts';

	type M = { period: string; status: string; openBatches: number; directUnchecked: number; balances: Record<string, { name: string; book: number; actual: number }> | null; note: string | null; closedAt: string | null };
	type W = { id: number; name: string; balance: number; active: boolean };
	type D = { id: number; date: string; amount: number; summary: string; vendor: string | null; unit: string | null; item: string | null; checked: boolean };

	const roles = $derived(auth.me?.access?.roles ?? []);
	const isStaff = $derived(roles.includes('재정 담당'));
	const isChair = $derived(roles.includes('재정위원장'));
	let years = $state<number[]>([]);
	let year = $state(0);
	let months = $state<M[]>([]);
	let wallets = $state<W[]>([]);
	let yearStatus = $state('');
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let open = $state<string | null>(null);
	let actual = $state<Record<number, string>>({});
	let note = $state('');
	let directs = $state<D[]>([]);
	let canCheck = $state(false);

	async function boot() {
		try {
			const r = await auth.api<{ years: { year: number; status: string }[] }>('GET', '/base');
			years = r.years.map((y) => y.year);
			year = (r.years.find((y) => y.status === '진행') ?? r.years[0])?.year ?? 0;
			if (year) await load();
		} catch (e) {
			err = errText(e);
		}
	}
	async function load() {
		try {
			const r = await auth.api<{ yearStatus: string; months: M[]; balances: W[] }>('GET', '/closings?year=' + year);
			months = r.months;
			wallets = r.balances.filter((w) => w.active || w.balance);
			yearStatus = r.yearStatus;
			err = '';
		} catch (e) {
			err = errText(e);
		}
	}
	onMount(boot);

	async function run(fn: () => Promise<unknown>, ok: string) {
		busy = true;
		err = '';
		notice = '';
		try {
			await auth.guard(fn);
			notice = ok;
			await load();
			if (open) await openMonth(open);
			return true;
		} catch (e) {
			if (!(e instanceof Error && e.message.includes('취소'))) err = errText(e);
			return false;
		} finally {
			busy = false;
		}
	}
	async function openMonth(p: string) {
		open = p;
		const r = await auth.api<{ items: D[]; canCheck: boolean }>('GET', '/direct-expenses?month=' + p);
		directs = r.items;
		canCheck = r.canCheck;
		// 월말 장부 잔액을 기본값으로 채움
		const to = new Date(Number(p.slice(0, 4)), Number(p.slice(5, 7)), 0);
		const asOf = `${to.getFullYear()}-${String(to.getMonth() + 1).padStart(2, '0')}-${String(to.getDate()).padStart(2, '0')}`;
		const b = await auth.api<{ items: W[] }>('GET', '/wallets/balances?asOf=' + asOf);
		actual = Object.fromEntries(b.items.filter((w) => w.active || w.balance).map((w) => [w.id, '']));
		bookAt = Object.fromEntries(b.items.map((w) => [w.id, w.balance]));
		note = '';
	}
	let bookAt = $state<Record<number, number>>({});
	const num = (s: string) => Number(String(s).replace(/,/g, ''));
	const closeMonth = () => run(() => auth.api('POST', '/closings', { period: open, actual: Object.fromEntries(Object.entries(actual).map(([k, v]) => [k, v === '' ? '' : num(v)])), note }), open + ' 월을 마감했어요. 재정위원장의 승인을 기다립니다.');
	const approve = (p: string) => run(() => auth.api('POST', `/closings/${p}/approve`, {}), p + ' 월 마감을 승인했어요.');
	const reopen = (p: string) => {
		const reason = prompt(`${p} 월 마감을 풉니다. 사유를 적어 주세요. (기록에 남습니다)`);
		if (reason) run(() => auth.api('POST', `/closings/${p}/reopen`, { reason }), p + ' 월 마감을 풀었어요.');
	};
	const checkAll = () => run(() => auth.api('POST', '/direct-expenses/check', { ids: directs.filter((d) => !d.checked).map((d) => d.id) }), '직접 지출을 확인했어요.');
	const closeYear = () => confirm(`${year}년을 마감하고 계좌별 남은 돈을 ${year + 1}년 이월금으로 올립니다. 진행할까요?`) && run(() => auth.api('POST', `/fiscal-years/${year}/close-year`, {}), `${year}년을 마감했어요.`);
	const diffTxt = (m: M) => (m.balances ? Object.values(m.balances).filter((b) => b.book !== b.actual).map((b) => `${b.name} 차이 ${won(b.actual - b.book)}`).join(', ') : '');
</script>

<div class="row page-head">
	<h1>마감</h1>
	<span class="sp"></span>
	<select bind:value={year} onchange={() => { open = null; load(); }} style="width:auto" aria-label="회계연도">{#each years as y}<option value={y}>{y}년</option>{/each}</select>
</div>
{#if err}<div class="warn">{err}</div>{/if}
{#if notice}<div class="note">{notice}</div>{/if}

<div class="card">
	<div class="card-head"><h2>{year}년 월 마감</h2><span class="tag {yearStatus === '마감' ? '' : 'now'}">{yearStatus}</span></div>
	<p class="fs-s muted">앞 달부터 차례로 마감합니다. 마감하면 그 달의 헌금·거래를 고칠 수 없고, 재정위원장이 승인합니다. 마감을 풀 수 있는 사람도 재정위원장입니다.</p>
	<div class="scroll-x"><table class="sm">
		<thead><tr><th>월</th><th>상태</th><th>확인할 것</th><th></th></tr></thead>
		<tbody>
			{#each months as m (m.period)}
				<tr class:hit={open === m.period}>
					<td><b>{m.period}</b></td>
					<td><span class="tag {m.status === '승인됨' ? 'now' : m.status === '열림' ? '' : 'part'}">{m.status}</span>{#if diffTxt(m)}<small class="muted"> {diffTxt(m)}</small>{/if}</td>
					<td class="fs-s">{#if m.openBatches}헌금 미마감 {m.openBatches}개 {/if}{#if m.directUnchecked}직접 지출 미확인 {m.directUnchecked}건{/if}</td>
					<td class="right">
						<button class="ghost sm" type="button" onclick={() => openMonth(m.period)}>열기</button>
						{#if isChair && m.status === '마감(승인 대기)'}<button class="sm" type="button" disabled={busy} onclick={() => approve(m.period)}>승인</button>{/if}
						{#if isChair && m.status !== '열림' && yearStatus !== '마감'}<button class="ghost sm" type="button" disabled={busy} onclick={() => reopen(m.period)}>풀기</button>{/if}
					</td>
				</tr>
			{/each}
		</tbody>
	</table></div>
</div>

{#if open}
	{@const m = months.find((x) => x.period === open)}
	<div class="card">
		<div class="card-head"><h2>{open}</h2><span class="sub">{m?.status}</span></div>
		<h3>직접 지출 {directs.length}건</h3>
		<table class="sm"><tbody>
			{#each directs as d}<tr><td>{d.date}</td><td>{d.unit} · {d.item}</td><td>{d.summary}</td><td class="right money">{won(d.amount)}</td><td>{d.checked ? '확인됨' : '미확인'}</td></tr>{:else}<tr><td class="muted">직접 지출이 없어요.</td></tr>{/each}
		</tbody></table>
		{#if canCheck && directs.some((d) => !d.checked)}<div class="row mt"><button class="sm" type="button" disabled={busy} onclick={checkAll}>모두 확인</button></div>{/if}

		{#if m?.status === '열림' && isStaff}
			<h3>마감: 실제 잔액 대조</h3>
			<p class="fs-s muted">통장 잔액·현금 시재를 적으면 장부 잔액과 비교합니다. 다르면 사유가 필요해요.</p>
			<table class="sm"><thead><tr><th>계좌</th><th class="right">장부 잔액</th><th class="right">실제 잔액</th><th class="right">차이</th></tr></thead><tbody>
				{#each wallets as w}
					<tr><td>{w.name}</td><td class="right">{won(bookAt[w.id])}</td><td class="right"><input bind:value={actual[w.id]} inputmode="numeric" style="width:9rem;text-align:right;padding:.25rem .5rem" aria-label="{w.name} 실제 잔액" /></td>
						<td class="right {actual[w.id] !== '' && num(actual[w.id]) !== bookAt[w.id] ? 'neg' : ''}">{actual[w.id] === '' ? '' : won(num(actual[w.id]) - bookAt[w.id])}</td></tr>
				{/each}
			</tbody></table>
			<div class="row mt"><input bind:value={note} placeholder="차이가 있으면 사유" maxlength="255" style="flex:1" /><button type="button" disabled={busy} onclick={closeMonth}>{open} 월 마감</button></div>
		{:else if m?.balances}
			<h3>마감 때 잔액</h3>
			<table class="sm"><tbody>{#each Object.values(m.balances) as b}<tr><td>{b.name}</td><td class="right">장부 {won(b.book)}</td><td class="right">실제 {won(b.actual)}</td></tr>{/each}</tbody></table>
			{#if m.note}<p class="fs-s">사유: {m.note}</p>{/if}
		{/if}
	</div>
{/if}

{#if isChair && yearStatus !== '마감'}
	<div class="card">
		<div class="card-head"><h2>연 마감</h2><span class="sub">12개월이 모두 승인된 뒤</span></div>
		<p class="fs-s muted">계좌별 남은 돈을 다음 회계연도의 이월금으로 올리고 이 해를 마감합니다. 다음 해 회계연도가 먼저 만들어져 있어야 해요.</p>
		<button type="button" disabled={busy} onclick={closeYear}>{year}년 연 마감</button>
	</div>
{/if}

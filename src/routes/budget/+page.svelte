<script lang="ts">
	// 예산: 편성(확정 전)·확정·변경(확정 뒤). 부서 담당자는 자기 부서만 보인다.
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { won, localDate, errText } from '#lib/fmt.ts';

	type Item = { id: number; name: string; initial: number; budget: number; paid: number; pending: number; remain: number; rate: number; basis: string | null; nature: string | null; directOk: boolean; directLimit: number | null };
	type U = { id: number; parentId: number | null; kind: string; name: string; items: Item[] };
	type B = { year: number; status: string; confirmedOn: string | null; units: U[]; income: { accountId: number; name: string; budget: number; actual: number }[]; canEdit: boolean; canConfirm: boolean; isAdmin: boolean };
	type Chg = { id: number; amount: number; reason: string; decidedOn: string | null; from: string | null; to: string | null; at: string };

	let years = $state<number[]>([]);
	let year = $state(0);
	let b = $state<B | null>(null);
	let changes = $state<Chg[]>([]);
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let add = $state<Record<number, { name: string; amount: string; basis: string }>>({});
	let incomeEdit = $state<Record<number, string>>({});
	let confirmDate = $state(localDate());
	let ch = $state({ fromItemId: 0, toItemId: 0, amount: '', reason: '', decidedOn: '' });
	let dir = $state<Record<number, { ok: boolean; limit: string }>>({});

	const confirmed = $derived(!!b?.confirmedOn);
	const allItems = $derived((b?.units ?? []).flatMap((u) => u.items.map((i) => ({ ...i, unit: u.name }))));
	const sum = (xs: Item[], k: 'budget' | 'paid' | 'pending' | 'remain') => xs.reduce((s, i) => s + i[k], 0);

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
			b = await auth.api<B>('GET', '/budget?year=' + year);
			incomeEdit = Object.fromEntries(b.income.map((i) => [i.accountId, String(i.budget || '')]));
			dir = Object.fromEntries(b.units.flatMap((u) => u.items).map((i) => [i.id, { ok: i.directOk, limit: i.directLimit === null ? '' : String(i.directLimit) }]));
			if (b.canEdit || b.canConfirm || auth.me?.access?.roles.some((r) => ['재정위원장', '담임목사', '감사', '열람', '재정 담당'].includes(r))) changes = (await auth.api<{ items: Chg[] }>('GET', '/budget-changes?year=' + year)).items;
			err = '';
		} catch (e) {
			err = errText(e);
		}
	}
	onMount(boot);

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
	const num = (s: string) => Number(String(s).replace(/,/g, '') || 0);
	async function addItem(u: U) {
		const f = add[u.id];
		if (!f?.name) return;
		if (await run(() => auth.api('POST', '/budget-items', { unitId: u.id, name: f.name, amount: num(f.amount), basis: f.basis }), '예산 항목을 추가했어요.')) add[u.id] = { name: '', amount: '', basis: '' };
	}
	const editAmount = (i: Item) => {
		const v = prompt(`'${i.name}' 예산 금액(원)`, String(i.initial));
		if (v !== null) run(() => auth.api('POST', '/budget-items/' + i.id, { amount: num(v) }));
	};
	const delItem = (i: Item) => confirm(`'${i.name}' 항목을 지울까요?`) && run(() => auth.api('POST', `/budget-items/${i.id}/delete`, {}));
	const saveIncome = () => run(() => auth.api('POST', '/income-budgets', { year, items: Object.entries(incomeEdit).map(([k, v]) => ({ accountId: Number(k), amount: num(v) })) }), '수입 예산을 저장했어요.');
	const confirmBudget = () => confirm(`${year}년 예산을 확정할까요? 확정하면 직접 고칠 수 없고 추경·전용으로만 바꿉니다.`) && run(() => auth.api('POST', `/fiscal-years/${year}/budget-confirm`, { date: confirmDate }), '예산을 확정했어요.');
	const saveDirect = (i: Item) => run(() => auth.api('POST', `/budget-items/${i.id}/direct`, { directOk: dir[i.id].ok, directLimit: dir[i.id].limit === '' ? null : num(dir[i.id].limit) }), `'${i.name}' 직접 지출 설정을 저장했어요.`);
	async function change() {
		if (await run(() => auth.api('POST', '/budget-changes', { year, fromItemId: ch.fromItemId || null, toItemId: ch.toItemId || null, amount: num(ch.amount), reason: ch.reason, decidedOn: ch.decidedOn }), '예산 변경을 기록했어요.')) ch = { fromItemId: 0, toItemId: 0, amount: '', reason: '', decidedOn: '' };
	}
</script>

<div class="row page-head">
	<h1>예산</h1>
	<span class="sp"></span>
	<select bind:value={year} onchange={load} style="width:auto" aria-label="회계연도">{#each years as y}<option value={y}>{y}년</option>{/each}</select>
</div>
{#if err}<div class="warn">{err}</div>{/if}
{#if notice}<div class="note">{notice}</div>{/if}

{#if b}
	<div class="card">
		<div class="card-head">
			<h2>{year}년 예산 {confirmed ? '(확정 ' + b.confirmedOn + ')' : '(편성 중)'}</h2>
			<span class="tag {confirmed ? 'now' : 'part'}">{confirmed ? '확정됨' : '편성 중'}</span>
		</div>
		{#if !confirmed && b.canConfirm}
			<div class="row" style="flex-wrap:wrap"><span class="fs-s muted">공동의회에서 확정한 날</span><input type="date" bind:value={confirmDate} style="width:auto" /><button class="sm" type="button" disabled={busy} onclick={confirmBudget}>예산 확정</button></div>
		{:else if !confirmed}<p class="fs-s muted">예산 확정은 재정위원장이 합니다. 확정 전에는 항목을 직접 고칠 수 있어요.</p>{/if}
	</div>

	{#each b.units.filter((u) => u.kind !== '위원회' || u.items.length || b!.canEdit) as u (u.id)}
		<div class="card">
			<div class="card-head"><h2>{u.name}</h2><span class="sub">예산 {won(sum(u.items, 'budget'))} · 집행 {won(sum(u.items, 'paid'))} · 남음 {won(sum(u.items, 'remain'))}</span></div>
			<div class="scroll-x">
				<table class="sm">
					<thead><tr><th>항목</th><th class="right">예산</th><th class="right">집행</th><th class="right">결재 중</th><th class="right">남음</th><th class="right">%</th><th></th></tr></thead>
					<tbody>
						{#each u.items as i (i.id)}
							<tr>
								<td>{i.name}{#if i.basis}<small class="muted"> {i.basis}</small>{/if}{#if i.directOk}<span class="tag now" title="직접 지출 허용{i.directLimit ? ' (건당 ' + won(i.directLimit) + '원)' : ''}">직접</span>{/if}</td>
								<td class="right">{won(i.budget)}{#if i.budget !== i.initial}<small class="muted"> (처음 {won(i.initial)})</small>{/if}</td>
								<td class="right money">{won(i.paid)}</td><td class="right">{won(i.pending)}</td><td class="right {i.remain < 0 ? 'neg' : ''}">{won(i.remain)}</td><td class="right">{i.rate}</td>
								<td class="right">{#if b.canEdit && !confirmed}<button class="ghost sm" type="button" disabled={busy} onclick={() => editAmount(i)}>금액</button> <button class="ghost sm" type="button" disabled={busy} onclick={() => delItem(i)}>삭제</button>{/if}</td>
							</tr>
							{#if b.isAdmin}
								<tr><td colspan="7" class="fs-s muted">직접 지출
									<label class="switch" style="vertical-align:middle"><input type="checkbox" bind:checked={dir[i.id].ok} /><i></i></label>
									건당 한도 <input bind:value={dir[i.id].limit} inputmode="numeric" placeholder="없음" style="width:7rem;padding:.25rem .5rem" /> 원
									<button class="ghost sm" type="button" disabled={busy} onclick={() => saveDirect(i)}>저장</button></td></tr>
							{/if}
						{:else}
							<tr><td colspan="7" class="muted">예산 항목이 없어요.</td></tr>
						{/each}
					</tbody>
				</table>
			</div>
			{#if b.canEdit && !confirmed && u.kind !== '위원회'}
				{@const f = (add[u.id] ??= { name: '', amount: '', basis: '' })}
				<form class="row mt" style="flex-wrap:wrap" onsubmit={(e) => { e.preventDefault(); addItem(u); }}>
					<input bind:value={f.name} placeholder="항목 이름" maxlength="60" style="max-width:12rem" required />
					<input bind:value={f.amount} placeholder="예산 (원)" inputmode="numeric" style="max-width:9rem" />
					<input bind:value={f.basis} placeholder="산출 근거 (선택)" maxlength="255" style="max-width:14rem" />
					<button class="sm" type="submit" disabled={busy}>항목 추가</button>
				</form>
			{/if}
		</div>
	{/each}

	{#if b.income.length}
		<div class="card">
			<div class="card-head"><h2>수입 예산</h2><span class="sub">실적은 장부의 수입</span></div>
			<table class="sm">
				<thead><tr><th>항목</th><th class="right">예산</th><th class="right">실적</th></tr></thead>
				<tbody>
					{#each b.income as i (i.accountId)}
						<tr><td>{i.name}</td><td class="right">{#if b.canEdit && !confirmed}<input bind:value={incomeEdit[i.accountId]} inputmode="numeric" style="width:9rem;text-align:right;padding:.25rem .5rem" />{:else}{won(i.budget)}{/if}</td><td class="right money">{won(i.actual)}</td></tr>
					{/each}
				</tbody>
			</table>
			{#if b.canEdit && !confirmed}<div class="row mt" style="justify-content:flex-end"><button class="sm" type="button" disabled={busy} onclick={saveIncome}>수입 예산 저장</button></div>{/if}
		</div>
	{/if}

	{#if confirmed && b.canEdit}
		<div class="card">
			<div class="card-head"><h2>예산 변경 (추경·전용)</h2><span class="sub">확정된 예산은 이렇게만 바뀝니다</span></div>
			<form class="form" onsubmit={(e) => { e.preventDefault(); change(); }}>
				<select bind:value={ch.fromItemId} aria-label="줄이는 항목"><option value={0}>줄이는 항목 (추경이면 비움)</option>{#each allItems as i}<option value={i.id}>{i.unit} · {i.name} (남음 {won(i.remain)})</option>{/each}</select>
				<select bind:value={ch.toItemId} aria-label="늘리는 항목"><option value={0}>늘리는 항목</option>{#each allItems as i}<option value={i.id}>{i.unit} · {i.name}</option>{/each}</select>
				<input bind:value={ch.amount} inputmode="numeric" placeholder="금액 (원)" required />
				<input bind:value={ch.reason} placeholder="사유 (필수)" maxlength="255" required />
				<input type="date" bind:value={ch.decidedOn} aria-label="의결한 날" />
				<button type="submit" disabled={busy}>기록</button>
			</form>
		</div>
	{/if}
	{#if changes.length}
		<div class="card">
			<div class="card-head"><h2>변경 내역</h2></div>
			<table class="sm"><tbody>{#each changes as c}<tr><td>{c.decidedOn ?? c.at}</td><td>{c.from ?? '추경'} → {c.to ?? ''}</td><td class="right money">{won(c.amount)}</td><td>{c.reason}</td></tr>{/each}</tbody></table>
		</div>
	{/if}
{/if}

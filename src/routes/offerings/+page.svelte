<script lang="ts">
	// 헌금: 묶음(날짜+구분) 목록, 빠른 입력, 대조, 마감
	import { onMount, tick } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import DonorPicker, { type Donor } from '#lib/DonorPicker.svelte';
	import { won, localDate, lastSunday, errText } from '#lib/fmt.ts';

	type Batch = { id: number; year: number; date: string; kind: string; status: string; counters: string; counted: Record<string, number>; diffReason: string; total: number; count: number; totals: Record<string, { count: number; sum: number }>; reopened: number; closedAt: string | null };
	type Line = { id: number; accountId: number; amount: number; method: string; unitId: number | null; purpose: string | null; memo: string | null; donor: string | null };
	type Acct = { id: number; parentId: number | null; name: string; isOffering: boolean; named: boolean; needsUnit: boolean; needsPurpose: boolean; active: boolean };
	type Wallet = { id: number; name: string; kind: string; active: boolean };
	type Unit = { id: number; name: string; kind: string };

	const me = $derived(auth.me?.access);
	const isStaff = $derived(!!me?.roles.includes('재정 담당'));
	const KINDS = ['주일예배', '수요예배', '새벽기도', '절기', '계좌이체분', '기타'];

	let year = $state(0);
	let years = $state<number[]>([]);
	let batches = $state<Batch[]>([]);
	let accts = $state<Acct[]>([]);
	let wallets = $state<Wallet[]>([]);
	let units = $state<Unit[]>([]);
	let sel = $state<number | null>(null);
	let cur = $state<{ batch: Batch; entry: boolean; canClose: boolean; seeNames: boolean; lines: Line[] } | null>(null);
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);

	let nb = $state({ date: localDate(), kind: '주일예배', counters: '' });
	let f = $state({ accountId: 2, donor: null as Donor | null, amount: '', method: '현금', unitId: 0, purpose: '', memo: '' });
	let counted = $state<Record<string, string>>({});
	let countersTxt = $state('');
	let cl = $state({ cash: 0, bank: 0, reason: '' });
	let imp = $state({ text: '', unmatched: [] as { name: string; amount: unknown; why: string }[] });
	let picker: DonorPicker | undefined = $state();
	let amountEl: HTMLInputElement | undefined = $state();

	const offerAccts = $derived(accts.filter((a) => a.isOffering && a.active));
	const acct = $derived(accts.find((a) => a.id === f.accountId));
	const acctName = (id: number) => accts.find((a) => a.id === id)?.name ?? '';
	const cashW = $derived(wallets.filter((w) => w.active && w.kind === '현금'));
	const bankW = $derived(wallets.filter((w) => w.active && w.kind === '통장'));
	const needBank = $derived(!!cur?.lines.some((l) => l.method !== '현금'));
	const needCash = $derived(!!cur?.lines.some((l) => l.method === '현금'));

	async function boot() {
		try {
			const b = await auth.api<{ years: { year: number; status: string }[]; incomeAccounts: Acct[]; wallets: Wallet[] }>('GET', '/base');
			years = b.years.map((y) => y.year);
			accts = b.incomeAccounts;
			wallets = b.wallets;
			year = (b.years.find((y) => y.status === '진행') ?? b.years[0])?.year ?? 0;
			if (year) await loadList();
		} catch (e) {
			err = errText(e);
		}
	}
	async function loadList() {
		try {
			batches = (await auth.api<{ items: Batch[] }>('GET', '/offering-batches?year=' + year)).items;
			units = (await auth.api<{ items: Unit[] }>('GET', '/units?year=' + year)).items.filter((u) => u.kind === '부서');
			err = '';
		} catch (e) {
			err = errText(e);
		}
	}
	async function open(id: number) {
		sel = id;
		try {
			cur = await auth.api('GET', '/offering-batches/' + id);
			counted = Object.fromEntries(Object.entries(cur!.batch.counted).map(([k, v]) => [k, String(v)]));
			countersTxt = cur!.batch.counters;
			cl = { cash: cashW[0]?.id ?? 0, bank: bankW[0]?.id ?? 0, reason: cur!.batch.diffReason };
			err = '';
			await tick();
			picker?.focus();
		} catch (e) {
			err = errText(e);
		}
	}
	onMount(boot);

	async function run(fn: () => Promise<unknown>, ok = '', reload = true): Promise<boolean> {
		busy = true;
		err = '';
		notice = '';
		try {
			await fn();
			if (ok) notice = ok;
			if (reload) {
				await loadList();
				if (sel) await open(sel);
			}
			return true;
		} catch (e) {
			err = errText(e);
			return false;
		} finally {
			busy = false;
		}
	}
	async function newBatch() {
		busy = true;
		try {
			const r = await auth.api<{ id: number }>('POST', '/offering-batches', nb);
			await loadList();
			await open(r.id);
		} catch (e) {
			err = errText(e);
		} finally {
			busy = false;
		}
	}
	async function addLine() {
		if (!cur) return;
		const amount = Number(f.amount.replace(/,/g, ''));
		const body = { accountId: f.accountId, donor: acct?.named ? (f.donor?.key ?? '') : '', amount, method: f.method, unitId: f.unitId || undefined, purpose: f.purpose, memo: f.memo };
		if (await run(() => auth.api('POST', `/offering-batches/${cur!.batch.id}/lines`, body))) {
			f.donor = null;
			f.amount = '';
			f.memo = '';
			await tick();
			if (acct?.named) picker?.focus();
			else amountEl?.focus();
		}
	}
	/** 통장 내역 붙여넣기: 한 줄에 '이름 금액'(탭·쉼표·공백 구분). 한 명으로만 맞는 이름은 바로 넣고 나머지는 아래에 남긴다. */
	async function importBank() {
		if (!cur) return;
		const rows = imp.text
			.split(/\r?\n/)
			.map((l) => l.trim())
			.filter(Boolean)
			.map((l) => {
				const m = l.match(/^(.*?)[\t, ]+([\d,]+)\s*원?$/);
				return m ? { name: m[1].trim(), amount: Number(m[2].replace(/,/g, '')) } : { name: l, amount: 'x' };
			});
		if (!rows.length) return;
		busy = true;
		err = '';
		try {
			const r = await auth.api<{ added: number; unmatched: typeof imp.unmatched }>('POST', `/offering-batches/${cur.batch.id}/import`, { accountId: f.accountId, rows });
			notice = `${r.added}건을 계좌이체 헌금으로 넣었어요.` + (r.unmatched.length ? ` 맞지 않은 ${r.unmatched.length}줄은 아래에서 직접 입력해 주세요.` : '');
			imp = { text: '', unmatched: r.unmatched };
			await loadList();
			await open(cur.batch.id);
		} catch (e) {
			err = errText(e);
		} finally {
			busy = false;
		}
	}
	const delLine = (l: Line) => run(() => auth.api('POST', `/offering-lines/${l.id}/delete`, {}));
	const saveCounted = () => run(() => auth.api('POST', `/offering-batches/${cur!.batch.id}/counted`, { counted, counters: countersTxt }), '계수 금액을 저장했어요.');
	async function close() {
		if (!cur) return;
		await saveCountedQuiet();
		if (await run(() => auth.api('POST', `/offering-batches/${cur!.batch.id}/close`, { cashWalletId: cl.cash || undefined, bankWalletId: cl.bank || undefined, diffReason: cl.reason }), '마감했어요. 장부에 수입으로 올라갔습니다.')) { /* 목록 갱신은 run 이 함 */ }
	}
	async function saveCountedQuiet() {
		try {
			await auth.api('POST', `/offering-batches/${cur!.batch.id}/counted`, { counted, counters: countersTxt });
		} catch {
			/* 마감 때 서버가 다시 확인 */
		}
	}
	async function reopen() {
		const reason = prompt('다시 여는 사유를 적어 주세요. (기록에 남습니다)');
		if (!reason) return;
		await run(() => auth.api('POST', `/offering-batches/${cur!.batch.id}/reopen`, { reason }), '다시 열었어요. 장부의 수입은 취소 처리되었고, 다시 마감하면 새로 올라갑니다.');
	}

	// 종류별 입력 합계와 계수 금액의 차이
	const rows = $derived.by(() => {
		if (!cur) return [];
		return Object.entries(cur.batch.totals).map(([id, t]) => {
			const c = counted[id];
			const cn = c === undefined || c === '' ? null : Number(String(c).replace(/,/g, ''));
			return { id, name: acctName(Number(id)), count: t.count, sum: t.sum, counted: cn, diff: cn === null ? null : t.sum - cn };
		});
	});
	const hasDiff = $derived(rows.some((r) => r.diff !== null && r.diff !== 0));
</script>

<div class="row page-head">
	<h1>헌금</h1>
	<span class="sp"></span>
	<select bind:value={year} onchange={() => { sel = null; cur = null; loadList(); }} style="width:auto" aria-label="회계연도">{#each years as y}<option value={y}>{y}년</option>{/each}</select>
</div>
{#if err}<div class="warn">{err}</div>{/if}
{#if notice}<div class="note">{notice}</div>{/if}

<div class="two two-l">
	<div>
		<div class="card">
			<div class="card-head"><h2>새 헌금 묶음</h2></div>
			<form class="form" onsubmit={(e) => { e.preventDefault(); newBatch(); }}>
				<input type="date" bind:value={nb.date} required aria-label="날짜" />
				<select bind:value={nb.kind} aria-label="구분">{#each KINDS as k}<option>{k}</option>{/each}</select>
				<input bind:value={nb.counters} placeholder="계수 위원 (예: 김철수, 이영희)" maxlength="200" />
				<button type="submit" disabled={busy || !year}>묶음 만들기</button>
			</form>
			<button class="ghost sm mt" type="button" onclick={() => (nb.date = lastSunday())}>지난 일요일로</button>
		</div>
		<div class="card">
			<div class="card-head"><h2>헌금 묶음</h2><span class="sub">{year}년</span></div>
			{#each batches as b (b.id)}
				<button type="button" class="blist" class:on={sel === b.id} onclick={() => open(b.id)}>
					<span><b>{b.date}</b> {b.kind}</span>
					<span class="r"><span class="tag {b.status === '마감' ? 'now' : 'part'}">{b.status}</span> <b class="money">{won(b.total)}</b></span>
				</button>
			{:else}
				<p class="muted">아직 헌금 묶음이 없어요.</p>
			{/each}
		</div>
	</div>

	<div>
		{#if cur}
			{@const b = cur.batch}
			<div class="card pop">
				<div class="card-head"><h2>{b.date} {b.kind}</h2><span class="tag {b.status === '마감' ? 'now' : 'part'}">{b.status}{b.reopened ? ' · 다시 연 적 ' + b.reopened + '회' : ''}</span></div>

				{#if cur.entry}
					<form class="entry" onsubmit={(e) => { e.preventDefault(); addLine(); }}>
						<div class="type-chips">
							{#each offerAccts as a (a.id)}<button type="button" class:on={f.accountId === a.id} onclick={() => { f.accountId = a.id; if (!a.named) f.donor = null; }}>{a.name}</button>{/each}
						</div>
						{#if acct?.named}
							<div class="row" style="align-items:center;flex-wrap:wrap">
								<div style="flex:1;min-width:12rem"><DonorPicker bind:this={picker} onpick={(d) => { f.donor = d; amountEl?.focus(); }} /></div>
								{#if f.donor}<span class="sc">{f.donor.label} <button type="button" class="x" onclick={() => (f.donor = null)} aria-label="취소">×</button></span>{:else}<span class="muted fs-s">이름 없이 입력하면 무기명</span>{/if}
							</div>
						{/if}
						<div class="row" style="flex-wrap:wrap">
							<input bind:this={amountEl} class="amount-in" bind:value={f.amount} inputmode="numeric" placeholder="금액 (원)" required style="flex:1;min-width:9rem" />
							<select bind:value={f.method} style="width:auto" aria-label="받은 방법"><option>현금</option><option>계좌이체</option><option>온라인</option></select>
						</div>
						{#if acct?.needsUnit}
							<select bind:value={f.unitId} required aria-label="부서"><option value={0} disabled>부서 선택</option>{#each units as u}<option value={u.id}>{u.name}</option>{/each}</select>
						{/if}
						{#if acct?.needsPurpose}<input bind:value={f.purpose} placeholder="목적 (예: 장학, 구제)" maxlength="100" required />{/if}
						<input bind:value={f.memo} placeholder="메모 (선택)" maxlength="255" />
						<button type="submit" disabled={busy}>입력 (Enter)</button>
					</form>
				{/if}

				{#if cur.entry}
					<details class="no-print" style="margin-bottom:1rem">
						<summary class="fs-s" style="cursor:pointer">통장 내역 붙여넣기 (계좌이체 헌금 · 위에서 고른 종류로 입력)</summary>
						<textarea bind:value={imp.text} rows="4" placeholder={'홍길동 100000\n김철수, 50,000'} style="margin:.4rem 0"></textarea>
						<button class="sm" type="button" disabled={busy || !imp.text.trim()} onclick={importBank}>맞춰서 넣기</button>
						{#if imp.unmatched.length}<ul class="fs-s">{#each imp.unmatched as u}<li>{u.name} {u.amount} — {u.why}</li>{/each}</ul>{/if}
					</details>
				{/if}

				<div class="scroll-x">
					<table class="sm">
						<thead><tr><th>종류</th><th>건수</th><th class="right">입력 합계</th><th class="right">계수 금액</th><th class="right">차이</th></tr></thead>
						<tbody>
							{#each rows as r}
								<tr class:hit={r.diff}>
									<td>{r.name}</td><td>{r.count}</td><td class="right money">{won(r.sum)}</td>
									<td class="right">{#if cur.entry}<input class="cn" bind:value={counted[r.id]} inputmode="numeric" placeholder="-" aria-label="{r.name} 계수 금액" />{:else}{r.counted === null ? '-' : won(r.counted)}{/if}</td>
									<td class="right {r.diff ? 'neg' : 'pos'}">{r.diff === null ? '' : r.diff === 0 ? '일치' : won(r.diff)}</td>
								</tr>
							{:else}
								<tr><td colspan="5" class="muted">입력된 헌금이 없어요.</td></tr>
							{/each}
							<tr class="tot"><td>합계</td><td>{b.count}</td><td class="right money">{won(b.total)}</td><td></td><td></td></tr>
						</tbody>
					</table>
				</div>
				{#if cur.entry}
					<div class="row mt" style="flex-wrap:wrap">
						<input bind:value={countersTxt} placeholder="계수 위원" maxlength="200" style="max-width:14rem" />
						<button class="ghost sm" type="button" disabled={busy} onclick={saveCounted}>계수 금액 저장</button>
					</div>
				{:else if b.counters}<p class="fs-s muted">계수 위원: {b.counters}</p>{/if}
			</div>

			{#if cur.seeNames}
				<div class="card">
					<div class="card-head"><h2>입력 내역</h2><span class="sub">{cur.lines.length}건</span></div>
					<div class="scroll-x">
						<table class="sm">
							<tbody>
								{#each cur.lines as l (l.id)}
									<tr>
										<td>{acctName(l.accountId)}{l.purpose ? ' · ' + l.purpose : ''}{l.unitId ? ' · ' + (units.find((u) => u.id === l.unitId)?.name ?? '') : ''}</td>
										<td>{l.donor ?? '무기명'}</td><td class="right money">{won(l.amount)}</td><td>{l.method}</td>
										<td class="right">{#if cur.entry}<button class="ghost sm" type="button" disabled={busy} onclick={() => delLine(l)}>삭제</button>{/if}</td>
									</tr>
								{/each}
							</tbody>
						</table>
					</div>
				</div>
			{/if}

			{#if cur.canClose}
				<div class="card">
					{#if b.status === '입력 중'}
						<div class="card-head"><h2>마감</h2><span class="sub">장부에 수입으로 올라갑니다</span></div>
						{#if hasDiff}<div class="warn fs-s">입력 합계와 계수 금액이 달라요. 마감하려면 차이의 사유를 적어야 합니다.</div>{/if}
						<div class="form">
							{#if needCash}<select bind:value={cl.cash} aria-label="현금 입금 계좌"><option value={0} disabled>현금 입금 계좌</option>{#each cashW as w}<option value={w.id}>{w.name}</option>{/each}</select>{/if}
							{#if needBank}<select bind:value={cl.bank} aria-label="통장 입금 계좌"><option value={0} disabled>통장 입금 계좌</option>{#each bankW as w}<option value={w.id}>{w.name}</option>{/each}</select>{/if}
							{#if hasDiff}<input bind:value={cl.reason} placeholder="차이 사유" maxlength="255" />{/if}
							<button type="button" disabled={busy || !cur.lines.length} onclick={close}>마감하기</button>
						</div>
					{:else}
						<div class="card-head"><h2>마감됨</h2><span class="sub">{b.closedAt ?? ''}</span></div>
						{#if b.diffReason}<p class="fs-s muted">차이 사유: {b.diffReason}</p>{/if}
						<button class="ghost sm" type="button" disabled={busy} onclick={reopen}>다시 열기 (사유 기록)</button>
					{/if}
				</div>
			{/if}
		{:else}
			<div class="card"><p class="muted">왼쪽에서 헌금 묶음을 고르거나 새로 만드세요. 헌금 종류를 고르고 이름·금액을 입력한 뒤 Enter를 누르면 다음 줄로 넘어갑니다.</p></div>
		{/if}
	</div>
</div>

<style>
	.blist { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; width: 100%; text-align: left; background: #fff; color: inherit; border: 1px solid var(--line); box-shadow: none; border-radius: 12px; padding: 0.55rem 0.8rem; margin-bottom: 0.4rem; font-weight: 400; }
	.blist.on { background: var(--primary-100); border-color: var(--primary-200); }
	.blist .r { display: inline-flex; gap: 0.5rem; align-items: center; }
	.entry { display: grid; gap: 0.55rem; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px dashed var(--line-strong); }
	.cn { width: 7.5rem; text-align: right; padding: 0.3rem 0.5rem; }
	.x { background: transparent; border: 0; box-shadow: none; padding: 0 0.2rem; color: inherit; cursor: pointer; }
</style>

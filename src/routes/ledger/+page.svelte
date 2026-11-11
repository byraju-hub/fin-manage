<script lang="ts">
	// 장부: 계좌 잔액, 수입·지출·이체 목록, 기타 수입·직접 지출·이체 등록, 취소
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { won, localDate, errText } from '#lib/fmt.ts';

	type Tx = { id: number; date: string; kind: string; wallet: string; toWallet: string | null; account: string | null; unit: string | null; item: string | null; amount: number; summary: string; vendor: string | null; source: string; isDirect: boolean; void: boolean; voidReason: string | null };
	type Wallet = { id: number; name: string; balance: number; active: boolean };
	type Acct = { id: number; parentId: number | null; name: string; isOffering: boolean; active: boolean };
	type Item = { id: number; name: string; directOk: boolean; directLimit: number | null };
	type Unit = { id: number; name: string; items: Item[] };

	const roles = $derived(auth.me?.access?.roles ?? []);
	const isStaff = $derived(roles.includes('재정 담당'));
	let years = $state<number[]>([]);
	let year = $state(0);
	let month = $state('');
	let items = $state<Tx[]>([]);
	let total = $state(0);
	let limit = $state(500);
	let wallets = $state<Wallet[]>([]);
	let accts = $state<Acct[]>([]);
	let units = $state<Unit[]>([]);
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let f = $state({ kind: '수입', date: localDate(), walletId: 0, toWalletId: 0, incomeAccountId: 0, budgetItemId: 0, amount: '', summary: '', vendor: '' });

	const leafIncome = $derived(accts.filter((a) => !a.isOffering && a.active && !accts.some((x) => x.parentId === a.id) && a.parentId !== null));
	const directItems = $derived(units.flatMap((u) => u.items.filter((i) => i.directOk).map((i) => ({ ...i, unit: u.name }))));

	async function boot() {
		try {
			const r = await auth.api<{ years: { year: number; status: string }[]; incomeAccounts: Acct[] }>('GET', '/base');
			years = r.years.map((y) => y.year);
			accts = r.incomeAccounts;
			year = (r.years.find((y) => y.status === '진행') ?? r.years[0])?.year ?? 0;
			if (year) await load();
		} catch (e) {
			err = errText(e);
		}
	}
	async function load() {
		try {
			const r = await auth.api<{ items: Tx[]; total: number }>('GET', `/ledger?year=${year}${month ? '&month=' + month : ''}&limit=${limit}`);
			items = r.items;
			total = r.total;
			wallets = (await auth.api<{ items: Wallet[] }>('GET', '/wallets/balances')).items;
			if (!f.walletId) f.walletId = wallets.find((w) => w.active)?.id ?? 0;
			units = (await auth.api<{ units: Unit[] }>('GET', '/budget?year=' + year)).units;
			err = '';
		} catch (e) {
			err = errText(e);
		}
	}
	onMount(boot);

	async function save() {
		busy = true;
		err = '';
		notice = '';
		try {
			const body = { ...f, amount: Number(f.amount.replace(/,/g, '')), walletId: f.walletId, toWalletId: f.toWalletId || undefined, incomeAccountId: f.incomeAccountId || undefined, budgetItemId: f.budgetItemId || undefined };
			await auth.api('POST', '/transactions', body);
			notice = f.kind + '을(를) 등록했어요.';
			f.amount = '';
			f.summary = '';
			f.vendor = '';
			await load();
		} catch (e) {
			err = errText(e);
		} finally {
			busy = false;
		}
	}
	async function voidTx(t: Tx) {
		const reason = prompt(`'${t.summary}' 거래를 취소합니다. 사유를 적어 주세요. (기록에 남습니다)`);
		if (!reason) return;
		try {
			await auth.api('POST', `/transactions/${t.id}/void`, { reason });
			await load();
		} catch (e) {
			err = errText(e);
		}
	}
</script>

<div class="row page-head">
	<h1>장부</h1>
	<span class="sp"></span>
	<div class="row">
		<select bind:value={year} onchange={load} style="width:auto" aria-label="회계연도">{#each years as y}<option value={y}>{y}년</option>{/each}</select>
		<select bind:value={month} onchange={load} style="width:auto" aria-label="월"><option value="">전체</option>{#each Array.from({ length: 12 }, (_, i) => String(i + 1).padStart(2, '0')) as m}<option value="{year}-{m}">{Number(m)}월</option>{/each}</select>
	</div>
</div>
{#if err}<div class="warn">{err}</div>{/if}
{#if notice}<div class="note">{notice}</div>{/if}

<div class="card">
	<div class="card-head"><h2>계좌 잔액</h2><span class="sub">시작 잔액 + 수입 − 지출 ± 이체</span></div>
	<div class="kpis">
		{#each wallets.filter((w) => w.active || w.balance) as w}<div class="kpi"><div><b>{won(w.balance)}<span class="of">원</span></b><span>{w.name}</span></div></div>{/each}
		<div class="kpi"><div><b>{won(wallets.reduce((s, w) => s + w.balance, 0))}<span class="of">원</span></b><span>전체 합계</span></div></div>
	</div>
</div>

{#if isStaff}
	<div class="card">
		<div class="card-head"><h2>등록</h2><span class="sub">헌금은 헌금 화면, 결의서 지출은 지급 처리에서 자동으로 들어옵니다</span></div>
		<form class="form" onsubmit={(e) => { e.preventDefault(); save(); }}>
			<select bind:value={f.kind} aria-label="종류"><option>수입</option><option>지출</option><option>이체</option></select>
			<input type="date" bind:value={f.date} required aria-label="날짜" />
			<select bind:value={f.walletId} aria-label={f.kind === '이체' ? '보내는 계좌' : '계좌'}>{#each wallets.filter((w) => w.active) as w}<option value={w.id}>{w.name}</option>{/each}</select>
			{#if f.kind === '이체'}<select bind:value={f.toWalletId} aria-label="받는 계좌"><option value={0}>받는 계좌</option>{#each wallets.filter((w) => w.active && w.id !== f.walletId) as w}<option value={w.id}>{w.name}</option>{/each}</select>{/if}
			{#if f.kind === '수입'}<select bind:value={f.incomeAccountId} aria-label="수입 항목" required><option value={0} disabled>수입 항목</option>{#each leafIncome as a}<option value={a.id}>{a.name}</option>{/each}</select>{/if}
			{#if f.kind === '지출'}<select bind:value={f.budgetItemId} aria-label="예산 항목" required><option value={0} disabled>예산 항목 (직접 지출 허용된 것)</option>{#each directItems as i}<option value={i.id}>{i.unit} · {i.name}{i.directLimit ? ' (건당 ' + won(i.directLimit) + '원까지)' : ''}</option>{/each}</select>{/if}
			<input bind:value={f.amount} inputmode="numeric" placeholder="금액 (원)" required />
			<input bind:value={f.summary} placeholder="적요" maxlength="255" required />
			{#if f.kind !== '이체'}<input bind:value={f.vendor} placeholder="거래처 (선택)" maxlength="100" />{/if}
			<button type="submit" disabled={busy}>등록</button>
		</form>
		{#if f.kind === '지출' && !directItems.length}<p class="fs-s muted">직접 지출이 허용된 예산 항목이 없어요. 재정 관리자가 예산 화면에서 항목마다 지정합니다. 그 밖의 지출은 지출결의서를 쓰세요.</p>{/if}
	</div>
{/if}

<div class="card">
	<div class="card-head"><h2>거래 내역</h2><span class="sub">{items.length}건{total > items.length ? ' / 전체 ' + total + '건' : ''}</span></div>
	<div class="scroll-x">
		<table class="sm">
			<thead><tr><th>날짜</th><th>구분</th><th>내용</th><th>계좌</th><th class="right">금액</th><th></th></tr></thead>
			<tbody>
				{#each items as t (t.id)}
					<tr class:muted={t.void} style={t.void ? 'text-decoration:line-through' : ''}>
						<td>{t.date}</td><td><span class="tag {t.kind === '수입' ? 'now' : t.kind === '지출' ? 'part' : ''}">{t.kind}</span></td>
						<td>{t.summary}{#if t.account}<small class="muted"> {t.account}</small>{/if}{#if t.unit}<small class="muted"> {t.unit}{t.item ? ' · ' + t.item : ''}</small>{/if}{#if t.isDirect}<small class="muted"> 직접</small>{/if}{#if t.void}<small> 취소: {t.voidReason}</small>{/if}</td>
						<td>{t.wallet}{t.toWallet ? ' → ' + t.toWallet : ''}</td>
						<td class="right money">{won(t.amount)}</td>
						<td class="right">{#if isStaff && !t.void && t.source === '직접'}<button class="ghost sm" type="button" onclick={() => voidTx(t)}>취소</button>{/if}</td>
					</tr>
				{:else}
					<tr><td colspan="6" class="muted">거래가 없어요.</td></tr>
				{/each}
			</tbody>
		</table>
	</div>
	{#if total > items.length}
		<div class="warn fs-s">최근 {items.length}건만 보여 드려요 (전체 {total}건). 월을 고르거나 <button class="ghost sm" type="button" onclick={() => { limit = Math.min(5000, limit + 1000); load(); }}>더 보기</button> 를 누르세요. 전체는 보고서 · 엑셀로 받을 수 있어요.</div>
	{/if}
</div>

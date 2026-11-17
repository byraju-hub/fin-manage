<script lang="ts">
	// 작정헌금: 헌금자별·헌금 종류별 한 해 작정 금액과 이행(낸 금액·잔액·%). 낸 금액은 마감된 헌금에서 계산한다.
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { won, errText } from '#lib/fmt.ts';
	import DonorPicker, { type Donor } from '#lib/DonorPicker.svelte';

	type Item = { id: number; donor: string; accountId: number; acct: string; amount: number; paid: number; remain: number; rate: number; memo: string | null };
	type Res = { year: number; items: Item[]; summary: { count: number; amount: number; paid: number; rate: number }; accounts: { id: number; name: string }[] };

	let year = $state(new Date().getFullYear());
	let acctFilter = $state(0);
	let q = $state('');
	let res = $state<Res | null>(null);
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let f = $state({ donor: null as Donor | null, accountId: 0, amount: '', memo: '' });
	let amountEl: HTMLInputElement | undefined = $state();
	let picker: DonorPicker | undefined = $state();
	const canEdit = $derived(!!auth.me?.access?.isAdmin || !!auth.me?.access?.roles.includes('재정 담당'));

	async function load() {
		err = '';
		try {
			res = await auth.api<Res>('GET', `/pledges?year=${year}` + (acctFilter ? '&accountId=' + acctFilter : '') + (q.trim() ? '&q=' + encodeURIComponent(q.trim()) : ''));
			if (!f.accountId && res.accounts.length) f.accountId = res.accounts[0].id;
		} catch (e) {
			err = errText(e);
		}
	}
	onMount(load);

	async function save(e: SubmitEvent) {
		e.preventDefault();
		if (!f.donor) { err = '헌금자를 골라 주세요.'; return; }
		busy = true; err = ''; notice = '';
		try {
			await auth.guard(() => auth.api('POST', '/pledges', { year, accountId: f.accountId, donor: f.donor!.key, amount: Number(f.amount.replace(/,/g, '')), memo: f.memo }));
			notice = `${f.donor.name} 님의 작정을 저장했어요.`;
			f.donor = null; f.amount = ''; f.memo = '';
			await load();
			picker?.focus();
		} catch (e2) {
			err = errText(e2);
		} finally {
			busy = false;
		}
	}
	async function del(i: Item) {
		if (!confirm(`${i.donor} 님의 ${i.acct} 작정을 지울까요? (낸 헌금은 그대로 남아요)`)) return;
		try {
			await auth.guard(() => auth.api('POST', `/pledges/${i.id}/delete`, {}));
			await load();
		} catch (e) {
			err = errText(e);
		}
	}
</script>

<div class="hero"><div><small>개인별 헌금 · 열람 때마다 기록돼요</small><h1>작정헌금</h1><p class="motto">작정한 금액과 지금까지 낸 금액(마감된 헌금), 남은 금액을 봅니다.</p></div></div>

{#if err}<div class="warn">{err}</div>{/if}
{#if notice}<div class="card" style="color:var(--primary-700)">{notice}</div>{/if}

<div class="card">
	<div class="row" style="flex-wrap:wrap;align-items:center">
		<input type="number" bind:value={year} min="2000" max="2100" style="width:6rem" aria-label="연도" onchange={load} />년
		<select bind:value={acctFilter} onchange={load} style="width:auto" aria-label="헌금 종류"><option value={0}>모든 종류</option>{#each res?.accounts ?? [] as a}<option value={a.id}>{a.name}</option>{/each}</select>
		<input bind:value={q} placeholder="이름 찾기" style="width:10rem" onkeydown={(e) => e.key === 'Enter' && load()} />
		<button class="ghost sm" type="button" onclick={load}>찾기</button>
	</div>
</div>

{#if canEdit && res}
	<form class="card" onsubmit={save}>
		<div class="card-head"><h2>작정 입력</h2><span class="sub">같은 사람·같은 종류는 금액을 고칩니다</span></div>
		<div class="row" style="flex-wrap:wrap;align-items:center">
			<div style="flex:1;min-width:12rem"><DonorPicker bind:this={picker} onpick={(d) => { f.donor = d; amountEl?.focus(); }} /></div>
			{#if f.donor}<span class="sc">{f.donor.label} <button type="button" class="x" onclick={() => (f.donor = null)} aria-label="취소">×</button></span>{/if}
		</div>
		<div class="row" style="flex-wrap:wrap;margin-top:.4rem">
			<select bind:value={f.accountId} style="width:auto" aria-label="헌금 종류">{#each res.accounts as a}<option value={a.id}>{a.name}</option>{/each}</select>
			<input bind:this={amountEl} bind:value={f.amount} inputmode="numeric" placeholder="작정 금액 (원)" required style="flex:1;min-width:9rem" />
			<input bind:value={f.memo} placeholder="메모 (선택)" maxlength="255" style="flex:1;min-width:9rem" />
			<button type="submit" disabled={busy}>저장</button>
		</div>
	</form>
{/if}

{#if res}
	<div class="kpis">
		<div class="kpi"><div><b>{res.summary.count}<span class="of">건</span></b><span>작정</span></div></div>
		<div class="kpi"><div><b>{won(res.summary.amount)}<span class="of">원</span></b><span>작정 합계</span></div></div>
		<div class="kpi"><div><b>{won(res.summary.paid)}<span class="of">원</span></b><span>낸 금액</span></div></div>
		<div class="kpi"><div><b>{res.summary.rate}<span class="of">%</span></b><span>이행률</span></div></div>
	</div>
	<div class="card">
		{#if !res.items.length}<p class="muted">{year}년 작정이 없어요.</p>{:else}
			<div class="scroll-x"><table class="sm"><thead><tr><th>헌금자</th><th>종류</th><th class="right">작정</th><th class="right">낸 금액</th><th class="right">남음</th><th class="right">%</th><th>메모</th>{#if canEdit}<th></th>{/if}</tr></thead>
				<tbody>{#each res.items as i (i.id)}
					<tr><td>{i.donor}</td><td>{i.acct}</td><td class="right">{won(i.amount)}</td><td class="right money">{won(i.paid)}</td><td class="right {i.remain < 0 ? 'neg' : ''}">{won(i.remain)}</td>
						<td class="right"><b>{i.rate}%</b></td><td class="muted">{i.memo ?? ''}</td>{#if canEdit}<td><button class="ghost sm" type="button" onclick={() => del(i)}>지우기</button></td>{/if}</tr>
				{/each}</tbody></table></div>
		{/if}
		<p class="fs-s muted">낸 금액은 마감된 헌금 묶음만 셉니다. 같은 헌금 종류의 하위 항목도 함께 셉니다.</p>
	</div>
{/if}

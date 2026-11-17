<script lang="ts">
	// 교구(소그룹)별 헌금 합계: 합계·헌금한 사람 수·1인 평균만. 개인별 금액은 보이지 않고, 헌금한 사람이 적은 모임은 '기타(소규모)'로 합쳐진다.
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { won, errText } from '#lib/fmt.ts';

	type Item = { name: string; sum: number; givers: number; members: number | null; avg: number | null; share: number };
	type Res = { available: boolean; label: string; message?: string; items: Item[]; total: number; min: number; from?: string; to?: string };
	type Acct = { id: number; name: string };

	const y = new Date().getFullYear();
	let from = $state(`${y}-01`);
	let to = $state(`${y}-${String(new Date().getMonth() + 1).padStart(2, '0')}`);
	let acct = $state(0);
	let accts = $state<Acct[]>([]);
	let res = $state<Res | null>(null);
	let err = $state('');
	const max = $derived(Math.max(1, ...(res?.items.map((i) => i.sum) ?? [1])));

	async function load() {
		err = '';
		try {
			res = await auth.api<Res>('GET', `/reports/groups?from=${from}&to=${to}` + (acct ? '&accountId=' + acct : ''));
		} catch (e) {
			err = errText(e);
		}
	}
	onMount(async () => {
		try {
			accts = (await auth.api<{ accounts: Acct[] }>('GET', `/pledges?year=${y}`)).accounts;
		} catch {
			/* 종류 목록은 선택 사항 */
		}
		load();
	});
</script>

<div class="hero no-print"><div><small>개인별 헌금 · 열람할 때마다 기록돼요</small><h1>{res?.label ?? '교구'}별 헌금</h1><p class="motto">마감된 헌금의 모임별 합계예요. 개인별 금액은 보이지 않아요.</p></div></div>

{#if err}<div class="warn">{err}</div>{/if}
<div class="card">
	<form class="row no-print" style="flex-wrap:wrap" onsubmit={(e) => { e.preventDefault(); load(); }}>
		<input type="month" bind:value={from} style="width:auto" aria-label="시작 월" /> ~ <input type="month" bind:value={to} style="width:auto" aria-label="끝 월" />
		<select bind:value={acct} style="width:auto" aria-label="헌금 종류" onchange={load}><option value={0}>모든 종류</option>{#each accts as a}<option value={a.id}>{a.name}</option>{/each}</select>
		<button class="sm" type="submit">보기</button>
		<button class="ghost sm" type="button" onclick={() => window.print()} disabled={!res?.items.length}>인쇄</button>
	</form>
	{#if res && !res.available}
		<div class="warn mt">{res.message}</div>
	{:else if res}
		<p class="fs-s muted">{res.from} ~ {res.to} · 합계 <b>{won(res.total)}원</b></p>
		<div class="scroll-x"><table class="sm"><thead><tr><th>{res.label}</th><th class="right">합계</th><th class="right">비율</th><th class="right">헌금한 사람</th><th class="right">소속 인원</th><th class="right">1인 평균</th></tr></thead>
			<tbody>
				{#each res.items as i (i.name)}
					<tr><td><b>{i.name}</b><div class="meter" style="margin-top:.2rem"><div style="width:{Math.round((i.sum / max) * 100)}%"></div></div></td><td class="right money">{won(i.sum)}</td><td class="right">{i.share}%</td><td class="right">{i.givers || ''}</td><td class="right">{i.members ?? ''}</td><td class="right">{i.avg !== null ? won(i.avg) : ''}</td></tr>
				{:else}
					<tr><td colspan="6" class="muted">이 기간에 마감된 헌금이 없어요.</td></tr>
				{/each}
			</tbody></table></div>
		<p class="fs-s muted">헌금한 사람이 {res.min}명 미만인 모임은 합계로 개인의 헌금을 알 수 있어서 '기타(소규모)'로 합쳐 보여 줘요. 소속은 교적 관리의 {res.label} 편성을 따르며, 소속이 없거나 교인이 아닌 분은 '{res.label} 소속 없음'으로 모아요. 인쇄한 종이는 쓴 뒤 파기해 주세요.</p>
	{/if}
</div>

<style>
	.meter { height: 8px; border-radius: 999px; background: var(--primary-100); overflow: hidden; }
	.meter div { height: 100%; background: var(--primary); border-radius: 999px; }
</style>

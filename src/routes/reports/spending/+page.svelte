<script lang="ts">
	// 부서별 지출 정리: 위원회 → 부서 × 카테고리(관·항·목 중 고른 단계). 장부의 지출(취소 제외) 기준.
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { won, errText } from '#lib/fmt.ts';
	import { hrefTo } from '#lib/paths.ts';
	import { dlOpen } from '#lib/download.svelte.ts';

	type Row = { id: number; name: string; kind: string; depth: number; budget: number; paid: number; cells: Record<string, number>; group?: boolean };
	type Res = { year: number; level: number; columns: { id: number; name: string }[]; rows: Row[]; total: { budget: number; paid: number; cells: Record<string, number> }; tree: { id: number; parentId: number | null; name: string; level: number; paid: number }[] };

	let years = $state<number[]>([]);
	let year = $state(0);
	let level = $state(1);
	let from = $state('');
	let to = $state('');
	let res = $state<Res | null>(null);
	let err = $state('');
	let view = $state<'matrix' | 'tree'>('matrix');

	async function load() {
		if (!year) return;
		err = '';
		try {
			res = await auth.api<Res>('GET', `/reports/spending?year=${year}&level=${level}` + (from ? '&from=' + from : '') + (to ? '&to=' + to : ''));
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
	const csv = $derived(hrefTo('/api/v1/export/spending') + `?year=${year}&level=${level}` + (from ? '&from=' + from : '') + (to ? '&to=' + to : ''));
	const getCsv = () => dlOpen('spending', `${year}년 부서별 지출 정리`, csv).catch((e) => (err = errText(e)));
	const pct = (p: number, b: number) => (b > 0 ? Math.round((p * 100) / b) + '%' : '');
	const cell = (r: { cells: Record<string, number> }, id: number) => r.cells[String(id)] ?? 0;
</script>

<div class="row page-head no-print">
	<h1>부서별 지출</h1>
	<span class="sp"></span>
	<div class="row">
		<select bind:value={year} onchange={load} style="width:auto" aria-label="회계연도">{#each years as y}<option value={y}>{y}년</option>{/each}</select>
		<select bind:value={level} onchange={load} style="width:auto" aria-label="카테고리 단계"><option value={1}>관별</option><option value={2}>항별</option><option value={3}>목별</option></select>
		<input type="month" bind:value={from} style="width:auto" aria-label="시작 월" onchange={load} /> ~ <input type="month" bind:value={to} style="width:auto" aria-label="끝 월" onchange={load} />
		<button class="ghost sm" class:on={view === 'matrix'} type="button" onclick={() => (view = 'matrix')}>부서 × 카테고리</button>
		<button class="ghost sm" class:on={view === 'tree'} type="button" onclick={() => (view = 'tree')}>카테고리 합계</button>
		<button class="ghost sm" type="button" onclick={getCsv}>엑셀로 받기</button>
		<button class="ghost sm" type="button" onclick={() => window.print()}>인쇄</button>
	</div>
</div>
{#if err}<div class="warn no-print">{err}</div>{/if}

{#if res}
	<div class="card">
		<div class="card-head"><h2>{res.year}년 부서별 지출 {from ? `(${from}${to ? ' ~ ' + to : ''})` : ''}</h2><span class="sub">지급된 지출 · 취소 제외 · 예산은 연간 {res.year}년 기준</span></div>
		{#if view === 'matrix'}
			<div class="scroll-x"><table class="sm sp-t">
				<thead><tr><th>위원회 · 부서</th><th class="right">예산</th>{#each res.columns as c (c.id)}<th class="right">{c.name}</th>{/each}<th class="right">지출 합계</th><th class="right">집행률</th></tr></thead>
				<tbody>
					{#each res.rows as r, i (r.name + i)}
						<tr class:grp={r.group}><td style="padding-left:{0.5 + r.depth * 1.1}rem">{#if r.group}<b>■ {r.name}</b>{:else}{r.name}{/if}</td>
							<td class="right">{won(r.budget)}</td>
							{#each res.columns as c (c.id)}<td class="right">{cell(r, c.id) ? won(cell(r, c.id)) : ''}</td>{/each}
							<td class="right money">{won(r.paid)}</td><td class="right">{pct(r.paid, r.budget)}</td></tr>
					{:else}
						<tr><td colspan={res.columns.length + 4} class="muted">이 기간의 지출이 없어요.</td></tr>
					{/each}
					<tr class="tot"><td>교회 전체</td><td class="right">{won(res.total.budget)}</td>{#each res.columns as c (c.id)}<td class="right">{won(cell(res.total, c.id))}</td>{/each}<td class="right money">{won(res.total.paid)}</td><td class="right">{pct(res.total.paid, res.total.budget)}</td></tr>
				</tbody>
			</table></div>
			<p class="fs-s muted">위원회 행(■)은 소속 부서의 합계예요. '미분류'는 카테고리가 연결되지 않은 예산 항목의 지출입니다. 예산 화면에서 항목에 카테고리를 연결하면 분류돼요.</p>
		{:else}
			<table class="sm"><thead><tr><th>카테고리</th><th class="right">지출</th><th class="right">비율</th></tr></thead>
				<tbody>
					{#each res.tree as t (t.id + '-' + t.parentId)}
						<tr><td style="padding-left:{0.5 + (t.level - 1) * 1.2}rem">{t.level === 1 ? '■ ' : ''}{t.name}</td><td class="right money">{won(t.paid)}</td><td class="right">{pct(t.paid, res.total.paid)}</td></tr>
					{:else}
						<tr><td colspan="3" class="muted">이 기간의 지출이 없어요.</td></tr>
					{/each}
				</tbody></table>
		{/if}
	</div>
{/if}

<style>
	.sp-t th, .sp-t td { white-space: nowrap; }
	.grp { background: var(--primary-50); }
	.tot { font-weight: 700; border-top: 2px solid var(--line-strong); }
</style>

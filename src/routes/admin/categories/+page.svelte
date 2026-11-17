<script lang="ts">
	// 지출 카테고리(교회 공통 관·항·목)와 부서별 사용 카테고리. 재정 관리자 전용.
	// 카테고리는 교회에 하나만 두고, 부서는 그중 쓸 것만 고릅니다. 예산 항목이 카테고리에 연결되어 부서별 지출이 한 기준으로 정리됩니다.
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { errText } from '#lib/fmt.ts';

	type Cat = { id: number; parentId: number | null; name: string; level: number; active: boolean; sort: number; used: number };
	type UnitC = { id: number; parentId: number | null; kind: string; name: string; categoryIds: number[] };

	let tab = $state<'cats' | 'units'>('cats');
	let cats = $state<Cat[]>([]);
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let adding = $state<{ parent: number | null; name: string } | null>(null);
	let renaming = $state<{ id: number; name: string } | null>(null);

	// 부서별 사용
	let years = $state<{ year: number; status: string }[]>([]);
	let year = $state(0);
	let units = $state<UnitC[]>([]);
	let sel = $state<number | null>(null);
	let picked = $state<Record<number, boolean>>({});
	let toChildren = $state(true);

	const kids = (id: number | null) => cats.filter((c) => c.parentId === id);
	const byId = $derived(Object.fromEntries(cats.map((c) => [c.id, c])) as Record<number, Cat>);
	/** 어떤 카테고리와 그 아래 전체 */
	const family = (id: number): number[] => [id, ...kids(id).flatMap((k) => family(k.id))];

	async function loadCats() {
		try {
			cats = (await auth.api<{ items: Cat[] }>('GET', '/categories')).items;
		} catch (e) {
			err = errText(e);
		}
	}
	async function loadUnits() {
		if (!year) return;
		try {
			units = (await auth.api<{ items: UnitC[] }>('GET', '/unit-categories?year=' + year)).items;
			if (sel !== null && !units.some((u) => u.id === sel)) sel = null;
		} catch (e) {
			err = errText(e);
		}
	}
	onMount(async () => {
		await loadCats();
		try {
			years = (await auth.api<{ years: { year: number; status: string }[] }>('GET', '/base')).years;
			year = (years.find((y) => y.status === '진행') ?? years[0])?.year ?? 0;
			await loadUnits();
		} catch (e) {
			err = errText(e);
		}
	});

	async function run(fn: () => Promise<unknown>, ok = ''): Promise<boolean> {
		busy = true;
		err = '';
		notice = '';
		try {
			await auth.guard(fn);
			if (ok) notice = ok;
			await loadCats();
			await loadUnits();
			return true;
		} catch (e) {
			if (!(e instanceof Error && e.message.includes('취소'))) err = errText(e);
			return false;
		} finally {
			busy = false;
		}
	}
	const seed = () => run(() => auth.api('POST', '/categories/seed', {}), '예시 카테고리를 넣었어요. 교회에 맞게 고쳐 쓰세요.');
	async function addCat() {
		if (!adding || !adding.name.trim()) return;
		if (await run(() => auth.api('POST', '/categories', { parentId: adding!.parent, name: adding!.name.trim() }), '추가했어요.')) adding = null;
	}
	async function renameCat() {
		if (!renaming || !renaming.name.trim()) return;
		if (await run(() => auth.api('POST', '/categories/' + renaming!.id, { name: renaming!.name.trim() }), '이름을 바꿨어요.')) renaming = null;
	}
	const toggle = (c: Cat) => run(() => auth.api('POST', '/categories/' + c.id, { active: !c.active }), c.active ? `'${c.name}'을(를) 사용 안 함으로 바꿨어요. 이미 쓴 기록은 그대로예요.` : `'${c.name}'을(를) 다시 쓰게 했어요.`);
	const move = (c: Cat, dir: 'up' | 'down') => run(() => auth.api('POST', `/categories/${c.id}/move`, { dir }));
	const del = (c: Cat) => confirm(`'${c.name}'을(를) 지울까요?`) && run(() => auth.api('POST', `/categories/${c.id}/delete`, {}), '지웠어요.');

	function pickUnit(id: number) {
		sel = id;
		const u = units.find((x) => x.id === id);
		picked = Object.fromEntries((u?.categoryIds ?? []).map((i) => [i, true]));
	}
	/** 관·항을 체크하면 그 아래도 함께 */
	function toggleCat(c: Cat, on: boolean) {
		for (const i of family(c.id)) picked[i] = on;
	}
	/** 저장할 때는 맨 위 단계만 남긴다(관 하나를 고르면 아래는 모두 포함) */
	function saveIds(): number[] {
		const ids = Object.entries(picked).filter(([, v]) => v).map(([k]) => Number(k));
		return ids.filter((i) => {
			let p = byId[i]?.parentId ?? null;
			while (p !== null) {
				if (picked[p]) return false;
				p = byId[p]?.parentId ?? null;
			}
			return true;
		});
	}
	const saveUnit = () => sel !== null && run(() => auth.api('POST', `/units/${sel}/categories`, { categoryIds: saveIds(), applyToChildren: toChildren && units.find((u) => u.id === sel)?.kind === '위원회' }), '저장했어요.');
	const selUnit = $derived(units.find((u) => u.id === sel) ?? null);
	const kindOrder = (u: UnitC) => u.parentId === null;
	const label = (ids: number[]) => (ids.length ? ids.map((i) => byId[i]?.name).filter(Boolean).join(', ') : '제한 없음');
</script>

<div class="row page-head">
	<h1>지출 카테고리</h1>
	<span class="sp"></span>
	<div class="row">
		<button class="ghost sm" class:on={tab === 'cats'} type="button" onclick={() => (tab = 'cats')}>카테고리</button>
		<button class="ghost sm" class:on={tab === 'units'} type="button" onclick={() => { tab = 'units'; loadUnits(); }}>부서별 사용</button>
	</div>
</div>
{#if err}<div class="warn">{err}</div>{/if}
{#if notice}<div class="note">{notice}</div>{/if}

{#if tab === 'cats'}
	<div class="card">
		<div class="card-head"><h2>교회 공통 카테고리</h2><span class="sub">관 → 항 → 목 (3단계)</span></div>
		<p class="fs-s muted">부서마다 따로 만들면 합산·비교가 안 되므로 교회 전체에 하나만 둡니다. 부서는 '부서별 사용'에서 그중 쓸 것만 고르고, 예산 항목에 카테고리를 연결하면 지출이 이 기준으로 모여요. 항목은 처음엔 최소한으로 두세요. 너무 잘게 나누면 고르기 어렵습니다.</p>
		{#if !cats.length}
			<div class="warn">아직 카테고리가 없어요.</div>
			<div class="row mt"><button type="button" disabled={busy} onclick={seed}>예시로 채우기 (관·항·목 약 60개)</button><button class="ghost" type="button" onclick={() => (adding = { parent: null, name: '' })}>직접 만들기</button></div>
		{/if}
		{#snippet node(c: Cat)}
			<li class:off={!c.active}>
				<div class="crow" style="padding-left:{(c.level - 1) * 1.2}rem">
					{#if renaming?.id === c.id}
						<input bind:value={renaming.name} maxlength="40" style="max-width:14rem" onkeydown={(e) => e.key === 'Enter' && renameCat()} />
						<button class="sm" type="button" disabled={busy} onclick={renameCat}>저장</button><button class="ghost sm" type="button" onclick={() => (renaming = null)}>취소</button>
					{:else}
						<span class="lv">{['관', '항', '목'][c.level - 1]}</span><b>{c.name}</b>{#if !c.active}<span class="tag part">사용 안 함</span>{/if}{#if c.used}<small class="muted">쓰는 곳 {c.used}</small>{/if}
						<span class="sp"></span>
						<button class="ghost sm" type="button" title="위로" onclick={() => move(c, 'up')}>↑</button><button class="ghost sm" type="button" title="아래로" onclick={() => move(c, 'down')}>↓</button>
						{#if c.level < 3}<button class="ghost sm" type="button" onclick={() => (adding = { parent: c.id, name: '' })}>+ {['항', '목'][c.level - 1]}</button>{/if}
						<button class="ghost sm" type="button" onclick={() => (renaming = { id: c.id, name: c.name })}>이름</button>
						<button class="ghost sm" type="button" onclick={() => toggle(c)}>{c.active ? '사용 안 함' : '다시 사용'}</button>
						<button class="ghost sm" type="button" onclick={() => del(c)}>지우기</button>
					{/if}
				</div>
				{#if adding && adding.parent === c.id}
					<div class="crow" style="padding-left:{c.level * 1.2}rem"><input bind:value={adding.name} placeholder="{['항', '목'][c.level - 1]} 이름" maxlength="40" style="max-width:14rem" onkeydown={(e) => e.key === 'Enter' && addCat()} /><button class="sm" type="button" disabled={busy} onclick={addCat}>추가</button><button class="ghost sm" type="button" onclick={() => (adding = null)}>취소</button></div>
				{/if}
				{#if kids(c.id).length}<ul>{#each kids(c.id) as k (k.id)}{@render node(k)}{/each}</ul>{/if}
			</li>
		{/snippet}
		{#if cats.length}<ul class="tree">{#each kids(null) as c (c.id)}{@render node(c)}{/each}</ul>{/if}
		{#if adding && adding.parent === null}
			<div class="row mt"><input bind:value={adding.name} placeholder="관 이름 (예: 예배비)" maxlength="40" style="max-width:14rem" onkeydown={(e) => e.key === 'Enter' && addCat()} /><button class="sm" type="button" disabled={busy} onclick={addCat}>추가</button><button class="ghost sm" type="button" onclick={() => (adding = null)}>취소</button></div>
		{:else if cats.length}
			<div class="row mt"><button class="ghost sm" type="button" onclick={() => (adding = { parent: null, name: '' })}>+ 관 추가</button></div>
		{/if}
	</div>
{:else}
	<div class="card">
		<div class="card-head"><h2>부서별 사용 카테고리</h2>
			<select bind:value={year} onchange={() => { sel = null; loadUnits(); }} style="width:auto" aria-label="회계연도">{#each years as y}<option value={y.year}>{y.year} 회계연도</option>{/each}</select></div>
		<p class="fs-s muted">각 부서가 예산 항목에 고를 수 있는 카테고리를 정합니다. 정하지 않은 부서는 모든 카테고리를 쓸 수 있어요. 보통 5~8개면 충분합니다. 조직(위원회·부서)은 '재정 단위 · 담당자'에서 추가해요.</p>
		{#if !cats.length}<div class="warn">먼저 '카테고리' 탭에서 카테고리를 만들어 주세요.</div>{/if}
		<div class="two two-l">
			<div>
				{#each units.filter(kindOrder) as top (top.id)}
					<div class="urow" class:on={sel === top.id}><button type="button" class="link" onclick={() => pickUnit(top.id)}><b>{top.name}</b></button><small class="muted"> {label(top.categoryIds)}</small></div>
					{#each units.filter((u) => u.parentId === top.id) as u (u.id)}
						<div class="urow sub" class:on={sel === u.id}><button type="button" class="link" onclick={() => pickUnit(u.id)}>{u.name}</button><small class="muted"> {label(u.categoryIds)}</small></div>
					{/each}
				{:else}
					<p class="muted">{year}년 재정 단위가 없어요. '재정 단위 · 담당자'에서 조직도를 먼저 만들어 주세요.</p>
				{/each}
			</div>
			<div>
				{#if selUnit}
					<h3 style="margin:.2rem 0">{selUnit.name}</h3>
					{#snippet pick(c: Cat)}
						<li><label class="row" style="padding-left:{(c.level - 1) * 1.1}rem"><input type="checkbox" checked={!!picked[c.id]} onchange={(e) => toggleCat(c, e.currentTarget.checked)} /> <span class:muted={!c.active}>{c.name}{c.active ? '' : ' (사용 안 함)'}</span></label>
							{#if kids(c.id).length}<ul>{#each kids(c.id) as k (k.id)}{@render pick(k)}{/each}</ul>{/if}</li>
					{/snippet}
					<ul class="tree">{#each kids(null) as c (c.id)}{@render pick(c)}{/each}</ul>
					{#if selUnit.kind === '위원회'}<label class="row fs-s"><span class="switch"><input type="checkbox" bind:checked={toChildren} /><i></i></span> 소속 부서에도 같은 목록 적용</label>{/if}
					<div class="row mt" style="justify-content:flex-end"><button class="ghost" type="button" onclick={() => { picked = {}; }}>모두 해제(제한 없음)</button><button type="button" disabled={busy} onclick={saveUnit}>저장</button></div>
					<p class="fs-s muted">관·항을 체크하면 그 아래 항목이 모두 열려요. 이미 만든 예산 항목은 그대로 두고, 새로 고르거나 바꿀 때부터 적용됩니다.</p>
				{:else}
					<p class="muted">왼쪽에서 위원회나 부서를 고르세요.</p>
				{/if}
			</div>
		</div>
	</div>
{/if}

<style>
	.tree, .tree ul { list-style: none; margin: 0; padding: 0; }
	.tree li.off { opacity: 0.6; }
	.crow { display: flex; align-items: center; gap: 0.4rem; padding: 0.22rem 0; flex-wrap: wrap; border-bottom: 1px solid var(--line); }
	.lv { display: inline-block; min-width: 1.5rem; text-align: center; font-size: 0.7rem; border-radius: 6px; background: var(--primary-100); color: var(--primary-700, #2b3e95); padding: 0.05rem 0.3rem; }
	.urow { padding: 0.35rem 0.5rem; border-bottom: 1px solid var(--line); }
	.urow.sub { padding-left: 1.6rem; }
	.urow.on { background: var(--primary-50); }
	.link { background: none; border: 0; padding: 0; color: inherit; cursor: pointer; text-align: left; font: inherit; }
</style>

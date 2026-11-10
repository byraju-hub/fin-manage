<script lang="ts">
	// 재정 단위(위원회·부서·예비비·기타)와 담당자(회계·부서장·위원장). 담당자는 교인 목록에서 찾아 지정한다.
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import PersonPicker, { type Person } from '#lib/PersonPicker.svelte';

	type Role = { id: number; role: string; personId: string; name: string };
	type Unit = { id: number; parentId: number | null; kind: string; name: string; fromCm: boolean; roles: Role[] };
	type Year = { year: number; status: string };

	let years = $state<Year[]>([]);
	let year = $state(0);
	let units = $state<Unit[]>([]);
	let sel = $state<number | null>(null);
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let newName = $state('');
	let newKind = $state('부서');
	const canEdit = $derived(!!auth.me?.access?.isAdmin || !!auth.me?.access?.roles.includes('재정 담당'));

	const cur = $derived(years.find((y) => y.year === year));
	const closed = $derived(cur?.status === '마감');
	const unit = $derived(units.find((u) => u.id === sel) ?? null);
	const committees = $derived(units.filter((u) => u.kind === '위원회'));
	const childrenOf = (id: number) => units.filter((u) => u.parentId === id);
	const topOthers = $derived(units.filter((u) => u.parentId === null && u.kind !== '위원회'));
	/** 담당자가 비어 있는 곳 (회계가 없으면 결의서를 쓸 수 없고, 위원회는 위원장, 부서는 부서장이 없으면 결재선이 비어 상신할 수 없음) */
	const missing = (u: Unit) => {
		if (u.kind === '위원회') return u.roles.some((r) => r.role === '위원장') ? '' : '위원장 미지정';
		if (u.kind === '부서') {
			const m = [!u.roles.some((r) => r.role === '회계') && '회계', !u.roles.some((r) => r.role === '부서장') && '부서장'].filter(Boolean);
			return m.length ? m.join('·') + ' 미지정' : '';
		}
		return '';
	};
	const missCount = $derived(units.filter((u) => missing(u)).length);
	const roleSlots = $derived(unit ? (unit.kind === '위원회' ? ['위원장', '회계'] : unit.kind === '부서' ? ['부서장', '회계'] : ['회계']) : []);
	const hints: Record<string, string> = { 회계: '지출결의서 기안', 부서장: '1차 결재', 위원장: '위원회 결재 · 소속 부서에 모두 적용' };

	async function loadYears() {
		try {
			const b = await auth.api<{ years: Year[] }>('GET', '/base');
			years = b.years;
			if (!year) year = (years.find((y) => y.status === '진행') ?? years[0])?.year ?? 0;
			if (year) await load();
		} catch (e) {
			err = e instanceof Error ? e.message : '불러오지 못했습니다.';
		}
	}
	async function load() {
		if (!year) return;
		try {
			units = (await auth.api<{ items: Unit[] }>('GET', '/units?year=' + year)).items;
			if (sel !== null && !units.some((u) => u.id === sel)) sel = null;
			err = '';
		} catch (e) {
			err = e instanceof Error ? e.message : '불러오지 못했습니다.';
		}
	}
	onMount(loadYears);

	async function run(fn: () => Promise<unknown>, ok: string): Promise<boolean> {
		busy = true;
		err = '';
		notice = '';
		try {
			await auth.guard(fn);
			if (ok) notice = ok;
			await load();
			return true;
		} catch (e) {
			if (!(e instanceof Error && e.message.includes('취소'))) err = e instanceof Error ? e.message : '저장하지 못했습니다.';
			return false;
		} finally {
			busy = false;
		}
	}

	async function importCm() {
		busy = true;
		err = '';
		try {
			const r = await auth.api<{ added: number }>('POST', '/units/import-cm', { year });
			notice = r.added ? `교적 조직표에서 ${r.added}곳을 가져왔어요.` : '새로 가져올 곳이 없어요. (이미 모두 가져왔습니다)';
			await load();
		} catch (e) {
			err = e instanceof Error ? e.message : '가져오지 못했습니다.';
		} finally {
			busy = false;
		}
	}
	let copyFrom = $state(0);
	async function copyYear() {
		if (!copyFrom) return;
		if (await run(() => auth.api('POST', '/units/copy-year', { from: copyFrom, to: year }), `${copyFrom}년의 재정 단위와 담당자를 복사했어요.`)) copyFrom = 0;
	}
	async function addUnit() {
		const parentId = newKind === '부서' ? (unit?.kind === '위원회' ? unit.id : (unit?.parentId ?? null)) : null;
		if (await run(() => auth.api('POST', '/units', { year, kind: newKind, name: newName, parentId }), '추가했어요.')) newName = '';
	}
	const delUnit = (u: Unit) => confirm(`'${u.name}'을(를) 지울까요?`) && run(() => auth.api('POST', `/units/${u.id}/delete`, {}), '지웠어요.');
	const pick = (u: Unit, role: string, p: Person) => run(() => auth.api('POST', `/units/${u.id}/roles`, { role, personId: p.id }), `${p.name} 님을 ${u.name} ${role}(으)로 지정했어요.`);
	const unpick = (r: Role) => run(() => auth.api('POST', `/unit-roles/${r.id}/delete`, {}), `${r.name} 님을 뺐어요.`);
	const label = (u: Unit) => u.name + (u.kind === '예비비' || u.kind === '기타' ? '' : '');
</script>

<div class="row page-head">
	<h1>재정 단위 · 담당자</h1>
	<span class="sp"></span>
	<div class="row">
		<select bind:value={year} onchange={() => { sel = null; load(); }} style="width:auto" aria-label="회계연도">
			{#each years as y}<option value={y.year}>{y.year} 회계연도 ({y.status})</option>{/each}
		</select>
		{#if canEdit && !closed}<button class="ghost sm" type="button" disabled={busy || !year} onclick={importCm}>교적 조직표에서 가져오기</button>{/if}
	</div>
</div>

{#if err}<div class="warn">{err}</div>{/if}
{#if notice}<div class="note">{notice}</div>{/if}
{#if !years.length}
	<div class="card"><p class="muted">회계연도가 아직 없어요. <b>기준 자료</b>에서 먼저 회계연도를 만들어 주세요.</p></div>
{:else}
	{#if missCount}<div class="warn fs-s"><b>확인이 필요한 곳 {missCount}곳</b> · 담당자가 비어 있는 부서는 지출결의서를 상신할 수 없어요.</div>{/if}
	{#if canEdit && !closed && !units.some((u) => u.kind === '위원회' || u.kind === '부서')}
		<div class="card">
			<p style="margin:0 0 .6rem"><b>{year}년 재정 단위가 아직 없어요.</b> 교적 조직표를 가져오거나, 다른 해의 것을 복사해서 시작합니다.</p>
			<div class="row" style="flex-wrap:wrap">
				<button type="button" disabled={busy} onclick={importCm}>교적 조직표에서 가져오기</button>
				{#if years.some((y) => y.year !== year)}
					<select bind:value={copyFrom} style="width:auto" aria-label="복사할 해"><option value={0}>복사할 해 선택</option>{#each years.filter((y) => y.year !== year) as y}<option value={y.year}>{y.year}년 것</option>{/each}</select>
					<button class="ghost" type="button" disabled={busy || !copyFrom} onclick={copyYear}>복사</button>
				{/if}
			</div>
		</div>
	{/if}

	<div class="two" style="grid-template-columns:minmax(260px,.6fr) minmax(0,1.4fr)">
		<div class="card">
			<div class="card-head"><h2>조직표</h2><span class="sub">{year}</span></div>
			<ul class="tree-l">
				{#each committees as c (c.id)}
					<li class="t0"><button type="button" class="lnk" onclick={() => (sel = c.id)} class:on={sel === c.id}>{c.name}</button>{#if missing(c)}<span class="miss">{missing(c)}</span>{/if}</li>
					{#each childrenOf(c.id) as d (d.id)}
						<li><a href="#u{d.id}" class:on={sel === d.id} onclick={(e) => { e.preventDefault(); sel = d.id; }}>{d.name}{#if missing(d)}<span class="miss">{missing(d)}</span>{/if}</a></li>
					{/each}
				{/each}
				{#each topOthers as u (u.id)}
					<li class="t0"><button type="button" class="lnk" onclick={() => (sel = u.id)} class:on={sel === u.id}>{label(u)}</button></li>
				{/each}
			</ul>
			{#if canEdit && !closed}
				<form class="row mt" style="flex-wrap:wrap" onsubmit={(e) => { e.preventDefault(); addUnit(); }}>
					<select bind:value={newKind} style="width:auto" aria-label="종류"><option>부서</option><option>위원회</option><option>기타</option></select>
					<input bind:value={newName} placeholder="새 단위 이름" maxlength="60" style="max-width:12rem" required />
					<button class="ghost sm" type="submit" disabled={busy}>추가</button>
				</form>
				<p class="fs-s muted" style="margin:.4rem 0 0">부서는 선택한 위원회 아래에 추가돼요.</p>
			{/if}
		</div>

		<div>
			{#if unit}
				<div class="card pop">
					<div class="card-head">
						<h2>{unit.parentId ? (units.find((x) => x.id === unit.parentId)?.name ?? '') + ' › ' : ''}{unit.name}</h2>
						<span class="tag {unit.fromCm ? 'now' : ''}">{unit.fromCm ? '교적 조직표와 연결됨' : unit.kind}</span>
					</div>
					{#if roleSlots.length === 0}<p class="muted">이 단위는 담당자를 따로 지정하지 않습니다.</p>{/if}
					{#each roleSlots as role}
						<div class="role-row">
							<label for="rp-{role}">{role}<span class="hint">{hints[role]}</span></label>
							<div>
								{#each unit.roles.filter((r) => r.role === role) as r (r.id)}
									<div class="pcard" style="margin-bottom:.4rem"><span class="avatar">{r.name.slice(0, 1)}</span><div class="grow"><b>{r.name}</b></div>{#if canEdit && !closed}<button class="ghost sm" type="button" disabled={busy} onclick={() => unpick(r)}>빼기</button>{/if}</div>
								{/each}
								{#if canEdit && !closed && (role === '회계' || !unit.roles.some((r) => r.role === role))}
									<div id="rp-{role}"><PersonPicker placeholder="{role} 추가: 교인 이름 · 초성 · 전화 끝자리" onpick={(p) => pick(unit, role, p)} /></div>
								{/if}
							</div>
						</div>
					{/each}
					{#if canEdit && !closed && unit.kind !== '예비비'}
						<div class="row" style="justify-content:flex-end;margin-top:.6rem"><button class="ghost sm" type="button" disabled={busy} onclick={() => delUnit(unit)}>이 단위 지우기</button></div>
					{/if}
				</div>
				<p class="fs-s muted">교적에 등록된 교인만 지정할 수 있어요. 재정 계정이 아직 없는 교인도 지정할 수 있고, 그 분이 재정 사용을 신청해 승인되면 그때부터 권한이 생깁니다. 예산이나 지출이 있는 단위는 지울 수 없어요.</p>
			{:else}
				<div class="card"><p class="muted">왼쪽에서 위원회나 부서를 고르면 담당자를 지정할 수 있어요.</p></div>
			{/if}
		</div>
	</div>
{/if}

<style>
	.lnk {
		background: transparent;
		border: 0;
		box-shadow: none;
		padding: 0;
		font: inherit;
		font-weight: 800;
		color: inherit;
		text-align: left;
		cursor: pointer;
	}
	.lnk.on {
		text-decoration: underline;
	}
	.miss {
		color: var(--orange-600);
		font-weight: 700;
		font-size: 0.72rem;
	}
</style>

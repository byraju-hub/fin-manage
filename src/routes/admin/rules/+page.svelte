<script lang="ts">
	// 결재 규칙(금액 구간별 결재선), 직접 지출 공통 설정, 대리 결재 지정 — 재정 관리자 전용
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import PersonPicker, { type Person } from '#lib/PersonPicker.svelte';
	import { won, localDate, errText } from '#lib/fmt.ts';

	type Step = { role: string; mode: string };
	type Tier = { name: string; minAmount: number; maxAmount: number | null; steps: Step[]; addOn: { over_budget?: string; reserve?: string } };
	const ROLES = ['부서장', '위원장', '재정위원장', '담임목사'];

	let tiers = $state<Tier[]>([]);
	let direct = $state({ roles: ['재정 담당'] as string[], monthCap: '', proofRequired: true, closeConfirm: true });
	let dels = $state<{ id: number; from: string; to: string; startsOn: string; endsOn: string }[]>([]);
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let dl = $state<{ from: Person | null; to: Person | null; startsOn: string; endsOn: string }>({ from: null, to: null, startsOn: localDate(), endsOn: localDate() });

	async function load() {
		try {
			const r = await auth.api<{ items: Tier[] }>('GET', '/approval-rules');
			tiers = r.items.map((t) => ({ ...t, addOn: { ...(t.addOn ?? {}) } }));
			const s = await auth.api<{ directRules: { roles: string[]; month_cap: number | null; proof_required: boolean; close_confirm: boolean } }>('GET', '/settings');
			direct = { roles: s.directRules.roles, monthCap: s.directRules.month_cap ? String(s.directRules.month_cap) : '', proofRequired: s.directRules.proof_required, closeConfirm: s.directRules.close_confirm };
			dels = (await auth.api<{ items: typeof dels }>('GET', '/delegations')).items;
		} catch (e) {
			err = errText(e);
		}
	}
	onMount(load);

	async function run(fn: () => Promise<unknown>, ok: string) {
		busy = true;
		err = '';
		notice = '';
		try {
			await auth.guard(fn);
			notice = ok;
			await load();
		} catch (e) {
			if (!(e instanceof Error && e.message.includes('취소'))) err = errText(e);
		} finally {
			busy = false;
		}
	}
	const num = (s: string) => Number(String(s).replace(/,/g, '') || 0);
	/** 구간의 시작 금액은 앞 구간의 끝 + 1 로 자동 맞춘다 */
	function relink() {
		tiers.forEach((t, i) => {
			t.minAmount = i === 0 ? 0 : (tiers[i - 1].maxAmount ?? 0) + 1;
			if (i === tiers.length - 1) t.maxAmount = null;
		});
	}
	function addTier() {
		const last = tiers[tiers.length - 1];
		if (last) last.maxAmount = last.minAmount + 1_000_000;
		tiers.push({ name: '새 구간', minAmount: 0, maxAmount: null, steps: [{ role: '부서장', mode: '결재' }], addOn: {} });
		relink();
	}
	const saveRules = () => run(() => auth.api('POST', '/approval-rules', { items: tiers }), '결재 규칙을 저장했어요. 이미 상신된 문서의 결재선은 바뀌지 않습니다.');
	const saveDirect = () => run(() => auth.api('POST', '/settings/direct-rules', { roles: direct.roles, monthCap: direct.monthCap ? num(direct.monthCap) : null, proofRequired: direct.proofRequired, closeConfirm: direct.closeConfirm }), '직접 지출 설정을 저장했어요.');
	async function addDel() {
		if (!dl.from || !dl.to) return;
		await run(() => auth.api('POST', '/delegations', { fromPersonId: dl.from!.id, toPersonId: dl.to!.id, startsOn: dl.startsOn, endsOn: dl.endsOn }), '대리 결재를 지정했어요.');
		dl.from = null;
		dl.to = null;
	}
	const delDel = (id: number) => run(() => auth.api('POST', `/delegations/${id}/delete`, {}), '대리 결재를 지웠어요.');
	const toggleDirectRole = (r: string) => (direct.roles = direct.roles.includes(r) ? direct.roles.filter((x) => x !== r) : [...direct.roles, r]);
</script>

<div class="row page-head"><h1>결재 규칙 · 설정</h1></div>
{#if err}<div class="warn">{err}</div>{/if}
{#if notice}<div class="note">{notice}</div>{/if}

<div class="card">
	<div class="card-head"><h2>금액 구간별 결재선</h2><span class="sub">금액 기준은 교회가 정합니다 · 상신할 때 합계가 속한 구간이 적용돼요</span></div>
	{#each tiers as t, n}
		<div class="card" style="background:var(--primary-50)">
			<div class="row" style="flex-wrap:wrap;align-items:center">
				<input bind:value={t.name} maxlength="30" style="max-width:9rem" aria-label="구간 이름" />
				<span class="fs-s">{won(t.minAmount)}원 ~</span>
				{#if n < tiers.length - 1}<input value={t.maxAmount ?? ''} oninput={(e) => { t.maxAmount = num((e.target as HTMLInputElement).value); relink(); }} inputmode="numeric" style="max-width:9rem;text-align:right" aria-label="끝 금액" /><span class="fs-s">원</span>{:else}<span class="fs-s">끝없이</span>{/if}
				<span class="sp"></span>
				{#if tiers.length > 1}<button class="ghost sm" type="button" onclick={() => { tiers.splice(n, 1); relink(); }}>구간 삭제</button>{/if}
			</div>
			<div class="steps mt">
				{#each t.steps as s, i}
					{#if i}<span class="arr">→</span>{/if}
					<span class="sc"><select bind:value={s.role} style="width:auto;padding:.1rem .3rem">{#each ROLES as r}<option>{r}</option>{/each}</select><select bind:value={s.mode} style="width:auto;padding:.1rem .3rem"><option>결재</option><option>확인</option></select>
						<button type="button" class="x" onclick={() => t.steps.splice(i, 1)} aria-label="단계 삭제">×</button></span>
				{/each}
				<button type="button" class="ghost sm" onclick={() => t.steps.push({ role: '재정위원장', mode: '결재' })}>+ 단계</button>
			</div>
			<p class="fs-s muted" style="margin:.5rem 0 0">'확인'은 지급을 막지 않는 사후 확인(전결)입니다. 예산을 넘으면 결재선 끝에
				<select bind:value={t.addOn.over_budget} style="width:auto;padding:.1rem .3rem"><option value="">추가 없음</option>{#each ROLES as r}<option>{r}</option>{/each}</select> 단계를 더하고, 예비비 사용이면
				<select bind:value={t.addOn.reserve} style="width:auto;padding:.1rem .3rem"><option value="">추가 없음</option>{#each ROLES as r}<option>{r}</option>{/each}</select> 단계를 더합니다.</p>
		</div>
	{/each}
	<div class="row" style="justify-content:space-between"><button class="ghost sm" type="button" onclick={addTier}>+ 구간 추가</button><button type="button" disabled={busy} onclick={saveRules}>규칙 저장</button></div>
</div>

<div class="card">
	<div class="card-head"><h2>직접 지출 공통 설정</h2><span class="sub">어느 예산 항목이 직접 지출 가능한지는 예산 화면에서 항목마다 지정합니다</span></div>
	<div class="form">
		<div class="row" style="flex-wrap:wrap">등록할 수 있는 역할:
			{#each ['재정 담당', '재정위원장'] as r}<button type="button" class="sc {direct.roles.includes(r) ? '' : 'c'}" onclick={() => toggleDirectRole(r)}>{direct.roles.includes(r) ? '✓ ' : ''}{r}</button>{/each}</div>
		<input bind:value={direct.monthCap} inputmode="numeric" placeholder="한 달 직접 지출 총 한도 (원, 비우면 없음)" />
		<label class="row"><span class="switch"><input type="checkbox" bind:checked={direct.proofRequired} /><i></i></span> 증빙 필수</label>
		<label class="row"><span class="switch"><input type="checkbox" bind:checked={direct.closeConfirm} /><i></i></span> 월 마감 때 재정위원장 확인 필수</label>
		<button type="button" disabled={busy} onclick={saveDirect}>저장</button>
	</div>
</div>

<div class="card">
	<div class="card-head"><h2>대리 결재(대결)</h2><span class="sub">부재 중인 결재자 대신 기간 동안 결재할 사람</span></div>
	<table class="sm"><tbody>{#each dels as d}<tr><td>{d.from} → {d.to}</td><td>{d.startsOn} ~ {d.endsOn}</td><td class="right"><button class="ghost sm" type="button" onclick={() => delDel(d.id)}>지우기</button></td></tr>{:else}<tr><td class="muted">지정된 대리 결재가 없어요.</td></tr>{/each}</tbody></table>
	<div class="form mt">
		<div>{#if dl.from}<span class="sc">맡기는 분: {dl.from.name} <button type="button" class="x" onclick={() => (dl.from = null)}>×</button></span>{:else}<PersonPicker placeholder="맡기는 분(부재 중인 결재자)" onpick={(p) => (dl.from = p)} />{/if}</div>
		<div>{#if dl.to}<span class="sc">대신 결재: {dl.to.name} <button type="button" class="x" onclick={() => (dl.to = null)}>×</button></span>{:else}<PersonPicker placeholder="대신 결재할 분" onpick={(p) => (dl.to = p)} />{/if}</div>
		<div class="row"><input type="date" bind:value={dl.startsOn} style="width:auto" /> ~ <input type="date" bind:value={dl.endsOn} style="width:auto" /></div>
		<button type="button" disabled={busy || !dl.from || !dl.to} onclick={addDel}>대리 결재 지정</button>
	</div>
</div>

<style>
	.x { background: transparent; border: 0; box-shadow: none; padding: 0 0.2rem; color: inherit; cursor: pointer; }
</style>

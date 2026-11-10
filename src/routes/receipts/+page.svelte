<script lang="ts">
	// 기부금 영수증: 대상 미리보기 → 발급(한 명 또는 전체) → 발급 대장 → 인쇄. 주민번호는 보관 방식일 때만 입력·인쇄에 쓰인다.
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { hrefTo } from '#lib/paths.ts';
	import { won, errText } from '#lib/fmt.ts';

	type P = { donorId: number; name: string; total: number; members: string[]; address: string; missingAddress: boolean; missingRrn: boolean; issued: { id: number; docNo: string; total: number; changed: boolean } | null };
	type L = { id: number; docNo: string; name: string; total: number; issuedAt: string; void: boolean; reissued: number; correction: boolean };
	type Donor = { id: number; name: string; member: boolean; donorNo: string | null; address: string | null; cmAddress: string; receiptTo: number | null; merged: number; hasRrn: boolean };
	type Rc = { id: number; docNo: string; issuedAt: string; void: boolean; snapshot: { name: string; address: string; rrnMasked: string; rrnMode: string; church: { name: string; bizNo: string; address: string; rep: string }; lines: { label: string; amount: number }[]; total: number; taxYear: number; included: string[] }; rrnFull: string | null };

	let year = $state(new Date().getFullYear() - 1);
	let rrnMode = $state('none');
	let prev = $state<P[]>([]);
	let ledger = $state<L[]>([]);
	let donors = $state<Donor[]>([]);
	let q = $state('');
	let picked = $state<Record<number, boolean>>({});
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let tab = $state<'issue' | 'ledger' | 'donors'>('issue');
	let printing = $state<Rc[]>([]);
	let edit = $state<{ d: Donor; address: string; rrn: string; donorNo: string; receiptTo: number | null; fam: { id: number; name: string }[] } | null>(null);

	async function load() {
		err = '';
		try {
			const r = await auth.api<{ rrnMode: string; items: P[] }>('GET', '/receipts/preview?year=' + year);
			rrnMode = r.rrnMode;
			prev = r.items;
			ledger = (await auth.api<{ items: L[] }>('GET', '/receipts?year=' + year)).items;
		} catch (e) {
			err = errText(e);
		}
	}
	async function loadDonors() {
		try {
			donors = (await auth.api<{ items: Donor[] }>('GET', '/donors?q=' + encodeURIComponent(q))).items;
		} catch (e) {
			err = errText(e);
		}
	}
	onMount(load);

	async function issue(ids: number[]) {
		if (!ids.length) return;
		busy = true;
		err = '';
		notice = '';
		try {
			const r = await auth.api<{ issued: number; reissued: number; corrected: number; skipped: { name?: string; why: string }[] }>('POST', '/receipts/issue', { year, donorIds: ids });
			notice = `새로 발급 ${r.issued}건, 재발급 ${r.reissued}건, 정정 발급 ${r.corrected}건.` + (r.skipped.length ? ` 건너뜀 ${r.skipped.length}건: ` + r.skipped.map((s) => `${s.name ?? ''} (${s.why})`).join(', ') : '');
			picked = {};
			await load();
		} catch (e) {
			err = errText(e);
		} finally {
			busy = false;
		}
	}
	const all = $derived(prev.filter((p) => !p.missingAddress && !p.missingRrn && (!p.issued || p.issued.changed)).map((p) => p.donorId));
	const sel = $derived(Object.entries(picked).filter(([, v]) => v).map(([k]) => Number(k)));

	async function printIds(ids: number[]) {
		busy = true;
		try {
			printing = await Promise.all(ids.map((id) => auth.api<Rc>('GET', `/receipts/${id}${rrnMode === 'keep' ? '?rrn=1' : ''}`)));
			setTimeout(() => window.print(), 300);
		} catch (e) {
			err = errText(e);
		} finally {
			busy = false;
		}
	}

	async function openEdit(d: Donor) {
		const fam = (await auth.api<{ items: { id: number; name: string }[] }>('GET', `/donors/${d.id}/household`)).items;
		edit = { d, address: d.address ?? '', rrn: '', donorNo: d.donorNo ?? '', receiptTo: d.receiptTo, fam };
	}
	async function saveEdit() {
		if (!edit) return;
		busy = true;
		err = '';
		try {
			const body: Record<string, unknown> = { address: edit.address, donorNo: edit.donorNo, receiptTo: edit.receiptTo };
			if (rrnMode === 'keep' && edit.rrn.trim()) body.rrn = edit.rrn;
			await auth.api('POST', '/donors/' + edit.d.id, body);
			edit = null;
			notice = '저장했어요.';
			await loadDonors();
			await load();
		} catch (e) {
			err = errText(e);
		} finally {
			busy = false;
		}
	}
	const csv = $derived(hrefTo('/api/v1/receipts-export') + '?year=' + year);
</script>

<div class="row page-head no-print">
	<h1>기부금 영수증</h1>
	<span class="sp"></span>
	<div class="row">
		<span class="fs-s muted">과세 연도</span><input type="number" bind:value={year} style="width:6.5rem" onchange={load} aria-label="과세 연도" />
		<button class="ghost sm" class:on={tab === 'issue'} type="button" onclick={() => (tab = 'issue')}>발급</button>
		<button class="ghost sm" class:on={tab === 'ledger'} type="button" onclick={() => (tab = 'ledger')}>발급 대장</button>
		<button class="ghost sm" class:on={tab === 'donors'} type="button" onclick={() => { tab = 'donors'; loadDonors(); }}>헌금자 정보</button>
	</div>
</div>
{#if err}<div class="warn no-print">{err}</div>{/if}
{#if notice}<div class="note no-print">{notice}</div>{/if}

{#if tab === 'issue'}
	<div class="card no-print">
		<div class="card-head"><h2>{year}년 영수증 대상</h2><span class="sub">1.1~12.31 · 영수증 대상 항목 · 마감된 헌금 · 주민번호 {rrnMode === 'keep' ? '암호화 보관' : '보관 안 함(손으로 기재)'}</span></div>
		<div class="row" style="flex-wrap:wrap">
			<button type="button" disabled={busy || !all.length} onclick={() => issue(all)}>발급 가능한 {all.length}명 한꺼번에 발급</button>
			<button class="ghost" type="button" disabled={busy || !sel.length} onclick={() => issue(sel)}>선택한 {sel.length}명 발급</button>
		</div>
		<div class="scroll-x mt"><table class="sm">
			<thead><tr><th></th><th>대표 헌금자</th><th class="right">금액</th><th>상태</th></tr></thead>
			<tbody>
				{#each prev as p (p.donorId)}
					<tr>
						<td><input type="checkbox" bind:checked={picked[p.donorId]} aria-label="{p.name} 선택" /></td>
						<td><b>{p.name}</b>{#if p.members.length > 1}<small class="muted"> 합산: {p.members.join(', ')}</small>{/if}</td>
						<td class="right money">{won(p.total)}</td>
						<td>{#if p.issued}<span class="tag {p.issued.changed ? 'part' : 'now'}">{p.issued.docNo}{p.issued.changed ? ' · 금액 변동' : ''}</span>{/if}{#if p.missingAddress}<span class="tag part">주소 없음</span>{/if}{#if p.missingRrn}<span class="tag part">주민번호 없음</span>{/if}</td>
					</tr>
				{:else}
					<tr><td colspan="4" class="muted">이 해에 영수증 대상 헌금이 없어요.</td></tr>
				{/each}
			</tbody>
		</table></div>
		<p class="fs-s muted">주소가 없거나(교적 주소도 비어 있음) 주민번호가 없는 분은 '헌금자 정보'에서 채운 뒤 발급할 수 있어요. 금액이 변동된 분은 정정 발급되고 이전 영수증은 무효 처리됩니다.</p>
	</div>
{:else if tab === 'ledger'}
	<div class="card no-print">
		<div class="card-head"><h2>발급 대장</h2><div class="row"><a class="ghost sm" style="padding:.35rem .7rem;border:1px solid var(--line-strong);border-radius:10px" href={csv}>발급명세 내려받기(엑셀)</a><button class="sm" type="button" disabled={busy || !ledger.length} onclick={() => printIds(ledger.filter((l) => !l.void).map((l) => l.id))}>유효한 영수증 모두 인쇄</button></div></div>
		<div class="scroll-x"><table class="sm">
			<thead><tr><th>번호</th><th>성명</th><th class="right">금액</th><th>발급일</th><th></th></tr></thead>
			<tbody>
				{#each ledger as l (l.id)}
					<tr class:muted={l.void}><td>{l.docNo}{l.correction ? ' (정정)' : ''}{l.void ? ' 무효' : ''}</td><td>{l.name}</td><td class="right money">{won(l.total)}</td><td>{l.issuedAt}{l.reissued ? ' · 재발급 ' + l.reissued : ''}</td><td class="right">{#if !l.void}<button class="ghost sm" type="button" disabled={busy} onclick={() => printIds([l.id])}>인쇄</button>{/if}</td></tr>
				{:else}
					<tr><td colspan="5" class="muted">발급한 영수증이 없어요.</td></tr>
				{/each}
			</tbody>
		</table></div>
	</div>
{:else}
	<div class="card no-print">
		<div class="card-head"><h2>헌금자 정보</h2><span class="sub">영수증에 쓰는 주소 · 가족 합산{rrnMode === 'keep' ? ' · 주민번호' : ''}</span></div>
		<form class="row" onsubmit={(e) => { e.preventDefault(); loadDonors(); }}><input bind:value={q} placeholder="이름 또는 헌금자 번호" style="max-width:16rem" /><button class="sm" type="submit">찾기</button></form>
		<div class="scroll-x mt"><table class="sm"><tbody>
			{#each donors as d (d.id)}
				<tr><td><b>{d.name}</b>{#if !d.member}<small class="muted"> 교인 아님</small>{/if}{#if d.receiptTo}<small class="muted"> → 합산됨</small>{/if}{#if d.merged}<small class="muted"> 합산 {d.merged}명</small>{/if}</td>
					<td>{d.address || d.cmAddress || '주소 없음'}</td><td>{#if rrnMode === 'keep'}{d.hasRrn ? '번호 있음' : '번호 없음'}{/if}</td><td class="right"><button class="ghost sm" type="button" onclick={() => openEdit(d)}>수정</button></td></tr>
			{/each}
		</tbody></table></div>
	</div>
	{#if edit}
		<form class="card no-print" onsubmit={(e) => { e.preventDefault(); saveEdit(); }}>
			<div class="card-head"><h2>{edit.d.name}</h2></div>
			<div class="form">
				<input bind:value={edit.address} placeholder="영수증 주소 (비우면 교적 주소: {edit.d.cmAddress || '없음'})" maxlength="255" />
				<input bind:value={edit.donorNo} placeholder="헌금자 번호(봉투 번호, 선택)" maxlength="20" />
				{#if rrnMode === 'keep'}<input bind:value={edit.rrn} placeholder={edit.d.hasRrn ? '주민번호 (입력하면 바꿈, 비워 두면 그대로)' : '주민등록번호 13자리'} inputmode="numeric" autocomplete="off" />{/if}
				<select bind:value={edit.receiptTo} aria-label="합산 대상"><option value={null}>본인 명의로 발급</option>{#each edit.fam as f}<option value={f.id}>{f.name} 영수증에 합산 (같은 세대)</option>{/each}</select>
			</div>
			<div class="row mt" style="justify-content:flex-end"><button type="button" class="ghost" onclick={() => (edit = null)}>닫기</button><button type="submit" disabled={busy}>저장</button></div>
		</form>
	{/if}
{/if}

{#each printing as r (r.id)}
	<section class="rc-page">
		<h2 style="text-align:center;letter-spacing:.4em">기 부 금 영 수 증</h2>
		<p style="text-align:right;margin:0">발급번호 {r.docNo}</p>
		<table class="sm"><tbody>
			<tr><th>기부자</th><th>성명</th><td>{r.snapshot.name}</td><th>주민등록번호</th><td>{r.rrnFull ?? r.snapshot.rrnMasked}{r.snapshot.rrnMode === 'none' ? ' (뒷자리는 직접 기재)' : ''}</td></tr>
			<tr><th></th><th>주소</th><td colspan="3">{r.snapshot.address}</td></tr>
			<tr><th>기부금 단체</th><th>단체명</th><td>{r.snapshot.church.name}</td><th>고유번호</th><td>{r.snapshot.church.bizNo}</td></tr>
			<tr><th></th><th>소재지</th><td colspan="3">{r.snapshot.church.address}</td></tr>
		</tbody></table>
		<table class="sm mt"><thead><tr><th>유형</th><th>구분</th><th>기부 기간</th><th class="right">기부 금액</th></tr></thead>
			<tbody>{#each r.snapshot.lines as l}<tr><td>종교단체</td><td>지정기부금</td><td>{l.label}</td><td class="right">{won(l.amount)}</td></tr>{/each}<tr class="tot"><td colspan="3">합계</td><td class="right">{won(r.snapshot.total)}</td></tr></tbody></table>
		<p>위와 같이 기부금을 기부받았음을 증명합니다.</p>
		<p style="text-align:center">{r.issuedAt.slice(0, 4)}년 {Number(r.issuedAt.slice(5, 7))}월 {Number(r.issuedAt.slice(8, 10))}일</p>
		<p style="text-align:right"><b>{r.snapshot.church.name}</b> {r.snapshot.church.rep ? '대표 ' + r.snapshot.church.rep : ''} (인)</p>
		{#if r.snapshot.included.length > 1}<p class="fs-s">합산 대상: {r.snapshot.included.join(', ')}</p>{/if}
	</section>
{/each}

<style>
	.rc-page { display: none; }
	@media print {
		.rc-page { display: block; page-break-after: always; padding: 1.2cm; }
	}
</style>

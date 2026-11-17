<script lang="ts">
	// 기부금 영수증: 대상 미리보기 → 발급(한 명 또는 전체) → 발급 대장 → 인쇄. 주민번호는 보관 방식일 때만 입력·인쇄에 쓰인다.
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { hrefTo } from '#lib/paths.ts';
	import { won, errText } from '#lib/fmt.ts';
	import { dlOpen } from '#lib/download.svelte.ts';

	type P = { donorId: number; name: string; total: number; members: string[]; address: string; missingAddress: boolean; missingRrn: boolean; issued: { id: number; docNo: string; total: number; changed: boolean } | null };
	type L = { id: number; docNo: string; name: string; total: number; issuedAt: string; void: boolean; reissued: number; correction: boolean };
	type Donor = { id: number; name: string; member: boolean; donorNo: string | null; address: string | null; cmAddress: string; receiptTo: number | null; merged: number; hasRrn: boolean };
	type Rc = { id: number; docNo: string; issuedAt: string; void: boolean; hasSeal?: boolean; snapshot: { name: string; address: string; rrnMasked: string; rrnMode: string; church: { name: string; bizNo: string; address: string; rep: string }; lines: { label: string; amount: number }[]; total: number; taxYear: number; included: string[]; code?: string }; rrnFull: string | null };

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
	let tab = $state<'issue' | 'ledger' | 'donors' | 'period' | 'house'>('issue');
	type Cs = { state: string; label: string; version: string | null; date: string | null };
	let cons = $state<Record<number, Cs>>({});
	let policyVersion = $state('');
	const needCheck = (c?: Cs) => !!c && c.state !== 'ok';
	async function loadConsents(ids: number[]) {
		if (!ids.length) return;
		try {
			const r = await auth.api<{ policyVersion: string; states: Record<string, Cs> }>('GET', '/donors/consent-status?ids=' + ids.slice(0, 300).join(','));
			policyVersion = r.policyVersion;
			for (const [k, v] of Object.entries(r.states)) cons[Number(k)] = v;
		} catch {
			/* 동의 표시는 보조 정보라 실패해도 화면을 막지 않는다 */
		}
	}
	// 기간 조회(참고용)
	type Per = { from: string; to: string; months: string[]; items: { donorId: number; name: string; members: string[]; total: number; months: Record<string, number> }[]; total: number };
	let per = $state<Per | null>(null);
	let pf = $state({ from: new Date().getFullYear() + '-01', to: new Date().getFullYear() + '-03' });
	async function loadPeriod() {
		err = '';
		try {
			per = await auth.api<Per>('GET', `/receipts/period?from=${pf.from}&to=${pf.to}`);
		} catch (e) {
			err = errText(e);
		}
	}
	function quarter(q: number) {
		const y = pf.from.slice(0, 4) || String(new Date().getFullYear());
		pf = { from: `${y}-${String((q - 1) * 3 + 1).padStart(2, '0')}`, to: `${y}-${String(q * 3).padStart(2, '0')}` };
		loadPeriod();
	}
	// 세대주 맞춤
	type Hh = { key: string; type: string; text: string; action: 'unmerge' | 'repoint' | 'merge'; donorId: number; toId: number | null; ids?: number[] };
	let hh = $state<Hh[]>([]);
	async function loadHouse() {
		try {
			hh = (await auth.api<{ items: Hh[] }>('GET', '/donors/household-check')).items;
		} catch (e) {
			err = errText(e);
		}
	}
	async function applyHouse(h: Hh) {
		busy = true;
		err = '';
		try {
			const r = await auth.api<{ correctYears: number[] }>('POST', '/donors/household-apply', { action: h.action, donorId: h.donorId, toId: h.toId, ids: h.ids });
			notice = '맞췄어요.' + (r.correctYears.length ? ` ${r.correctYears.join('·')}년에 이미 발급한 영수증이 있으면 '발급' 탭에서 정정 발급해 주세요.` : '');
			await loadHouse();
			await load();
		} catch (e) {
			err = errText(e);
		} finally {
			busy = false;
		}
	}
	async function dismissHouse(h: Hh) {
		try {
			await auth.api('POST', '/donors/household-dismiss', { key: h.key });
			await loadHouse();
		} catch (e) {
			err = errText(e);
		}
	}
	// 교인이 아닌 헌금자의 동의 기록
	type Cl = { member: boolean; items: { id: number; version: string; date: string; method: string; memo: string | null; revoked: string | null }[] };
	let cl = $state<Cl | null>(null);
	let cf = $state({ date: new Date().toISOString().slice(0, 10), method: '서면', memo: '' });
	async function loadCl(id: number) {
		cl = await auth.api<Cl>('GET', `/donors/${id}/consents`);
	}
	async function addConsent() {
		if (!edit) return;
		try {
			await auth.api('POST', `/donors/${edit.d.id}/consent`, cf);
			await loadCl(edit.d.id);
			await loadConsents([edit.d.id]);
			notice = '동의를 기록했어요.';
		} catch (e) {
			err = errText(e);
		}
	}
	async function revokeConsent() {
		if (!edit || !confirm('동의를 철회로 기록할까요? 주민등록번호를 보관 중이면 영수증 정보에서 지워야 해요.')) return;
		try {
			await auth.api('POST', `/donors/${edit.d.id}/consent/revoke`, { date: cf.date });
			await loadCl(edit.d.id);
			await loadConsents([edit.d.id]);
		} catch (e) {
			err = errText(e);
		}
	}
	let printing = $state<Rc[]>([]);
	let edit = $state<{ d: Donor; address: string; rrn: string; donorNo: string; receiptTo: number | null; fam: { id: number; name: string }[] } | null>(null);

	async function load() {
		err = '';
		try {
			const r = await auth.api<{ rrnMode: string; items: P[] }>('GET', '/receipts/preview?year=' + year);
			rrnMode = r.rrnMode;
			prev = r.items;
			ledger = (await auth.api<{ items: L[] }>('GET', '/receipts?year=' + year)).items;
			loadConsents(prev.map((p) => p.donorId));
		} catch (e) {
			err = errText(e);
		}
	}
	async function loadDonors() {
		try {
			donors = (await auth.api<{ items: Donor[] }>('GET', '/donors?q=' + encodeURIComponent(q))).items;
			loadConsents(donors.map((d) => d.id));
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
		cl = null;
		loadCl(d.id).catch(() => {});
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
	const getCsv = () => dlOpen('receipts', `${year}년 영수증 발급명세`, csv).catch((e) => (err = errText(e)));
</script>

<div class="row page-head no-print">
	<h1>기부금 영수증</h1>
	<span class="sp"></span>
	<div class="row">
		<span class="fs-s muted">과세 연도</span><input type="number" bind:value={year} style="width:6.5rem" onchange={load} aria-label="과세 연도" />
		<button class="ghost sm" class:on={tab === 'issue'} type="button" onclick={() => (tab = 'issue')}>발급</button>
		<button class="ghost sm" class:on={tab === 'ledger'} type="button" onclick={() => (tab = 'ledger')}>발급 대장</button>
		<button class="ghost sm" class:on={tab === 'donors'} type="button" onclick={() => { tab = 'donors'; loadDonors(); }}>헌금자 정보</button>
		<button class="ghost sm" class:on={tab === 'period'} type="button" onclick={() => { tab = 'period'; if (!per) loadPeriod(); }}>기간 조회</button>
		<button class="ghost sm" class:on={tab === 'house'} type="button" onclick={() => { tab = 'house'; loadHouse(); }}>세대 확인{hh.length ? ' (' + hh.length + ')' : ''}</button>
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
		{#if prev.some((p) => needCheck(cons[p.donorId]))}<div class="warn fs-s mt">개인정보 동의를 확인해야 하는 분이 {prev.filter((p) => needCheck(cons[p.donorId])).length}명 있어요. 발급은 막지 않지만, 교인은 교적 담당자에게, 교인이 아닌 분은 '헌금자 정보'에서 동의를 기록해 주세요.</div>{/if}
		<div class="scroll-x mt"><table class="sm">
			<thead><tr><th></th><th>대표 헌금자</th><th class="right">금액</th><th>상태</th></tr></thead>
			<tbody>
				{#each prev as p (p.donorId)}
					<tr>
						<td><input type="checkbox" bind:checked={picked[p.donorId]} aria-label="{p.name} 선택" /></td>
						<td><b>{p.name}</b>{#if p.members.length > 1}<small class="muted"> 합산: {p.members.join(', ')}</small>{/if}</td>
						<td class="right money">{won(p.total)}</td>
						<td>{#if p.issued}<span class="tag {p.issued.changed ? 'part' : 'now'}">{p.issued.docNo}{p.issued.changed ? ' · 금액 변동' : ''}</span>{/if}{#if p.missingAddress}<span class="tag part">주소 없음</span>{/if}{#if needCheck(cons[p.donorId])}<span class="tag part" title="개인정보 동의 확인">{cons[p.donorId].label}</span>{/if}{#if p.missingRrn}<span class="tag part">주민번호 없음</span>{/if}</td>
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
		<div class="card-head"><h2>발급 대장</h2><div class="row"><button class="ghost sm" type="button" onclick={getCsv}>발급명세 내려받기(엑셀)</button><button class="sm" type="button" disabled={busy || !ledger.length} onclick={() => printIds(ledger.filter((l) => !l.void).map((l) => l.id))}>유효한 영수증 모두 인쇄</button></div></div>
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
{:else if tab === 'period'}
	<div class="card">
		<div class="card-head"><h2>기간 조회 <span class="tag part">참고용 · 영수증 아님</span></h2><span class="sub">마감된 영수증 대상 헌금 · 가족 합산 포함</span></div>
		<form class="row no-print" style="flex-wrap:wrap" onsubmit={(e) => { e.preventDefault(); loadPeriod(); }}>
			<input type="month" bind:value={pf.from} style="width:auto" aria-label="시작 월" /> ~ <input type="month" bind:value={pf.to} style="width:auto" aria-label="끝 월" />
			<button class="sm" type="submit">보기</button>
			{#each [1, 2, 3, 4] as qn}<button class="ghost sm" type="button" onclick={() => quarter(qn)}>{qn}분기</button>{/each}
			<button class="ghost sm" type="button" onclick={() => window.print()} disabled={!per?.items.length}>인쇄</button>
		</form>
		{#if per}
			<p class="fs-s muted">{per.from} ~ {per.to} · 헌금자 {per.items.length}명 · 합계 <b>{won(per.total)}원</b></p>
			<div class="scroll-x"><table class="sm"><thead><tr><th>대표 헌금자</th>{#each per.months as m}<th class="right">{Number(m.slice(5))}월</th>{/each}<th class="right">합계</th></tr></thead>
				<tbody>{#each per.items as i (i.donorId)}<tr><td><b>{i.name}</b>{#if i.members.length > 1}<small class="muted"> 합산: {i.members.join(', ')}</small>{/if}</td>{#each per.months as m}<td class="right">{i.months[String(Number(m.slice(5)))] ? won(i.months[String(Number(m.slice(5)))]) : ''}</td>{/each}<td class="right money">{won(i.total)}</td></tr>{:else}<tr><td colspan={per.months.length + 2} class="muted">이 기간에 영수증 대상 헌금이 없어요.</td></tr>{/each}</tbody></table></div>
			<p class="fs-s muted">참고용 합계예요. 영수증은 과세 연도(1.1~12.31) 단위로만 발급합니다. 개인별 헌금이므로 인쇄한 종이는 쓴 뒤 파기해 주세요.</p>
		{/if}
	</div>
{:else if tab === 'house'}
	<div class="card no-print">
		<div class="card-head"><h2>세대 확인</h2><span class="sub">교적의 세대와 영수증 합산이 어긋난 곳</span></div>
		{#if !hh.length}<p class="muted">어긋난 곳이 없어요.</p>{/if}
		{#each hh as h (h.key)}
			<div class="pcard" style="margin-bottom:.4rem"><div class="grow">{h.text}</div>
				<button class="sm" type="button" disabled={busy} onclick={() => applyHouse(h)}>맞추기</button>
				<button class="ghost sm" type="button" onclick={() => dismissHouse(h)}>올해는 그대로</button></div>
		{/each}
		<p class="fs-s muted">자동으로 바꾸지 않아요. 사람이 다르게 정한 합산은 그대로 두고 '올해는 그대로'를 누르면 이 해 동안 알리지 않습니다. 이미 영수증을 낸 대표를 바꾸면 정정 발급 안내가 나와요.</p>
	</div>
{:else}
	<div class="card no-print">
		<div class="card-head"><h2>헌금자 정보</h2><span class="sub">영수증에 쓰는 주소 · 가족 합산{rrnMode === 'keep' ? ' · 주민번호' : ''}</span></div>
		<form class="row" onsubmit={(e) => { e.preventDefault(); loadDonors(); }}><input bind:value={q} placeholder="이름 또는 헌금자 번호" style="max-width:16rem" /><button class="sm" type="submit">찾기</button></form>
		<div class="scroll-x mt"><table class="sm"><tbody>
			{#each donors as d (d.id)}
				<tr><td><b>{d.name}</b>{#if !d.member}<small class="muted"> 교인 아님</small>{/if}{#if d.receiptTo}<small class="muted"> → 합산됨</small>{/if}{#if d.merged}<small class="muted"> 합산 {d.merged}명</small>{/if}{#if cons[d.id]}<span class="tag {cons[d.id].state === 'ok' ? 'now' : 'part'}" style="margin-left:.3rem">{cons[d.id].label}</span>{/if}</td>
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
			<div class="mt" style="border-top:1px solid var(--line);padding-top:.7rem">
				<b class="fs-s">개인정보 동의</b>
				{#if cons[edit.d.id]}<span class="tag {cons[edit.d.id].state === 'ok' ? 'now' : 'part'}" style="margin-left:.4rem">{cons[edit.d.id].label}</span>{#if cons[edit.d.id].date}<small class="muted"> {cons[edit.d.id].date} · 방침 {cons[edit.d.id].version}</small>{/if}{/if}
				{#if edit.d.member}
					<p class="fs-s muted">교인의 동의는 교적 관리에서 기록합니다. 교적 담당자에게 확인을 요청해 주세요.</p>
				{:else}
					<p class="fs-s muted">교인이 아닌 헌금자는 영수증 발급(주민등록번호 사용 포함)에 대한 동의를 받은 날과 방법을 기록해 두세요. (현재 방침 판 {policyVersion})</p>
					<div class="row" style="flex-wrap:wrap">
						<input type="date" bind:value={cf.date} style="width:auto" aria-label="동의일" />
						<select bind:value={cf.method} style="width:auto" aria-label="방법"><option>서면</option><option>구두</option><option>앱</option></select>
						<input bind:value={cf.memo} placeholder="메모 (선택)" maxlength="255" style="flex:1;min-width:8rem" />
						<button type="button" class="sm" onclick={addConsent}>동의 기록</button>
						{#if cl?.items.some((c) => !c.revoked)}<button type="button" class="ghost sm" onclick={revokeConsent}>철회 기록</button>{/if}
					</div>
					{#if cl?.items.length}<ul class="fs-s">{#each cl.items as c (c.id)}<li>{c.date} · {c.method}{c.memo ? ' · ' + c.memo : ''} · 방침 {c.version}{c.revoked ? ' · 철회 ' + c.revoked : ''}</li>{/each}</ul>{/if}
				{/if}
			</div>
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
			<tbody>{#each r.snapshot.lines as l}<tr><td>종교단체{r.snapshot.code ? ' (' + r.snapshot.code + ')' : ''}</td><td>지정기부금</td><td>{l.label}</td><td class="right">{won(l.amount)}</td></tr>{/each}<tr class="tot"><td colspan="3">합계</td><td class="right">{won(r.snapshot.total)}</td></tr></tbody></table>
		<p>위와 같이 기부금을 기부받았음을 증명합니다.</p>
		<p style="text-align:center">{r.issuedAt.slice(0, 4)}년 {Number(r.issuedAt.slice(5, 7))}월 {Number(r.issuedAt.slice(8, 10))}일</p>
		<p style="text-align:right"><b>{r.snapshot.church.name}</b> {r.snapshot.church.rep ? '대표 ' + r.snapshot.church.rep : ''} (인){#if r.hasSeal}<img src={hrefTo('/api/v1/settings/seal')} alt="직인" style="height:60px;vertical-align:middle;margin-left:-2.2rem;opacity:.9" />{/if}</p>
		{#if r.snapshot.included.length > 1}<p class="fs-s">합산 대상: {r.snapshot.included.join(', ')}</p>{/if}
	</section>
{/each}

<style>
	.rc-page { display: none; }
	@media print {
		.rc-page { display: block; page-break-after: always; padding: 1.2cm; }
	}
</style>

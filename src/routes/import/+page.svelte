<script lang="ts">
	// 기존 자료 가져오기: 엑셀(.xlsx)·CSV 파일이나 엑셀에서 복사한 표를 붙여넣어 헌금 내역·예산을 옮긴다.
	// 순서: 불러오기 → 칸 맞추기 → 서버 검사(미리보기, 저장 안 함) → 가져오기 → (헌금) 마감
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { errText } from '#lib/fmt.ts';

	type Kind = 'offerings' | 'expense' | 'income';
	type Field = { key: string; label: string; required?: boolean; words: string[] };
	const FIELDS: Record<Kind, Field[]> = {
		offerings: [
			{ key: 'date', label: '날짜', required: true, words: ['날짜', '일자', '일시', '헌금일', 'date'] },
			{ key: 'account', label: '헌금 종류', required: true, words: ['종류', '헌금종류', '구분', '항목', '헌금명'] },
			{ key: 'name', label: '이름', words: ['이름', '성명', '헌금자', '성함'] },
			{ key: 'amount', label: '금액', required: true, words: ['금액', '헌금액', '액수', '원'] },
			{ key: 'birth', label: '출생연도(동명이인)', words: ['출생', '생년', '생일', '출생연도'] },
			{ key: 'method', label: '받은 방법', words: ['방법', '방식', '수단'] },
			{ key: 'unit', label: '부서(부서헌금)', words: ['부서'] },
			{ key: 'purpose', label: '목적(목적헌금)', words: ['목적'] },
			{ key: 'memo', label: '메모', words: ['메모', '비고', '적요'] }
		],
		expense: [
			{ key: 'unit', label: '부서(재정 단위)', required: true, words: ['부서', '재정단위', '단위', '위원회'] },
			{ key: 'item', label: '예산 항목', required: true, words: ['항목', '예산항목', '과목', '세목'] },
			{ key: 'amount', label: '예산 금액', required: true, words: ['금액', '예산', '예산액'] },
			{ key: 'basis', label: '산출 근거', words: ['근거', '산출'] },
			{ key: 'nature', label: '성격', words: ['성격', '구분'] }
		],
		income: [
			{ key: 'account', label: '수입 항목', required: true, words: ['항목', '수입', '과목', '종류'] },
			{ key: 'amount', label: '예산 금액', required: true, words: ['금액', '예산', '예산액'] }
		]
	};
	const SAMPLE: Record<Kind, string> = {
		offerings: '날짜,헌금 종류,이름,금액,받은 방법\n2025-01-05,십일조,홍길동,100000,현금\n2025-01-05,주일헌금,,350000,현금\n2025-01-12,감사헌금,김은혜,50000,계좌이체\n',
		expense: '부서,예산 항목,예산 금액,산출 근거\n유치부,행사비,500000,연 2회\n유치부,교육비,300000,\n청년부,행사비,800000,\n',
		income: '수입 항목,예산 금액\n십일조,90000000\n감사헌금,12000000\n'
	};
	const CHUNK = 300;

	let kind = $state<Kind>('offerings');
	let year = $state(new Date().getFullYear());
	let paste = $state('');
	let grid = $state<string[][]>([]);
	let fileName = $state('');
	let hasHeader = $state(true);
	let map = $state<Record<string, number>>({});
	let createOutsiders = $state(false);
	let createUnits = $state(false);
	let skipDup = $state(true);
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let step = $state<'load' | 'map' | 'check' | 'done'>('load');
	type Res = { ok: boolean; error: string | null; dup?: boolean; newDonor?: boolean; action?: string; added?: boolean };
	let res = $state<Res[]>([]);
	let pending = $state<{ id: number; date: string; count: number; total: number }[]>([]);
	let wallets = $state<{ id: number; name: string; kind: string; active: boolean }[]>([]);
	let cl = $state({ cash: 0, bank: 0 });
	let doneInfo = $state('');

	const fields = $derived(FIELDS[kind]);
	const header = $derived(hasHeader ? (grid[0] ?? []) : []);
	const body = $derived(hasHeader ? grid.slice(1) : grid);
	const cols = $derived(Math.max(0, ...grid.map((r) => r.length)));
	const missing = $derived(fields.filter((f) => f.required && (map[f.key] ?? -1) < 0).map((f) => f.label));

	onMount(async () => {
		try {
			wallets = (await auth.api<{ wallets: typeof wallets }>('GET', '/base')).wallets;
			cl = { cash: wallets.find((w) => w.active && w.kind === '현금')?.id ?? 0, bank: wallets.find((w) => w.active && w.kind === '통장')?.id ?? 0 };
		} catch {
			/* 기준 자료를 못 읽어도 가져오기 자체는 가능 */
		}
	});

	// ---------- 읽기 ----------
	function parseText(t: string): string[][] {
		t = t.replace(/^﻿/, '');
		const first = t.split(/\r?\n/, 1)[0] ?? '';
		const d = first.includes('\t') ? '\t' : ',';
		const out: string[][] = [];
		let row: string[] = [];
		let cur = '';
		let q = false;
		for (let i = 0; i < t.length; i++) {
			const c = t[i];
			if (q) {
				if (c === '"' && t[i + 1] === '"') { cur += '"'; i++; }
				else if (c === '"') q = false;
				else cur += c;
			} else if (c === '"') q = true;
			else if (c === d) { row.push(cur); cur = ''; }
			else if (c === '\n' || c === '\r') {
				if (c === '\r' && t[i + 1] === '\n') i++;
				row.push(cur); cur = '';
				if (row.some((x) => x.trim() !== '')) out.push(row);
				row = [];
			} else cur += c;
		}
		row.push(cur);
		if (row.some((x) => x.trim() !== '')) out.push(row);
		return out;
	}
	const cell = (v: unknown): string => {
		if (v === null || v === undefined) return '';
		if (v instanceof Date) return `${v.getFullYear()}-${String(v.getMonth() + 1).padStart(2, '0')}-${String(v.getDate()).padStart(2, '0')}`;
		return String(v).trim();
	};
	async function onFile(e: Event) {
		const f = (e.currentTarget as HTMLInputElement).files?.[0];
		if (!f) return;
		fileName = f.name;
		busy = true;
		err = '';
		try {
			if (/\.xlsx$/i.test(f.name)) {
				const readExcelFile = (await import('read-excel-file/browser')).default;
				const sheets = (await readExcelFile(f)) as { sheet: string; data: unknown[][] }[];
				const best = sheets.reduce((a, b) => (b.data.length > a.data.length ? b : a), sheets[0]);
				grid = best.data.map((r) => r.map(cell));
			} else if (/\.(csv|txt|tsv)$/i.test(f.name)) {
				grid = parseText(await f.text());
			} else throw new Error('엑셀(.xlsx) 또는 CSV 파일만 읽을 수 있어요. 엑셀에서 "다른 이름으로 저장 › Excel 통합 문서(.xlsx)"로 저장해 주세요. (.xls 는 읽지 못합니다)');
			start();
		} catch (x) {
			err = x instanceof Error && x.message.includes('엑셀(.xlsx)') ? x.message : '파일을 읽지 못했습니다. ' + errText(x, '');
		} finally {
			busy = false;
		}
	}
	function onPaste() {
		grid = parseText(paste);
		fileName = '붙여넣은 표';
		start();
	}
	function start() {
		if (!grid.length) {
			err = '읽은 내용이 없어요.';
			return;
		}
		err = '';
		// 첫 줄에 숫자 금액이 없고 글자가 많으면 머리글로 본다
		hasHeader = !(grid[0] ?? []).some((c) => /^[\d,]{4,}$/.test(c.trim()));
		guess();
		step = 'map';
	}
	function guess() {
		const h = (hasHeader ? grid[0] : []) ?? [];
		const m: Record<string, number> = {};
		const used = new Set<number>();
		for (const f of fields) {
			const i = h.findIndex((c, idx) => !used.has(idx) && f.words.some((w) => c.replace(/\s/g, '').includes(w)));
			m[f.key] = i;
			if (i >= 0) used.add(i);
		}
		// 머리글이 없으면 칸 순서대로
		if (!hasHeader) fields.forEach((f, i) => (m[f.key] = i < cols ? i : -1));
		map = m;
	}
	function changeKind(k: Kind) {
		kind = k;
		reset();
		if (grid.length) guess();
	}
	function reset() {
		res = [];
		step = grid.length ? 'map' : 'load';
		notice = '';
		err = '';
		doneInfo = '';
	}

	// ---------- 정리 ----------
	function normDate(v: string): string {
		v = v.trim();
		if (/^\d{5}$/.test(v)) {
			const d = new Date(Date.UTC(1899, 11, 30) + Number(v) * 86400000);
			return d.toISOString().slice(0, 10);
		}
		let m = v.match(/^(\d{4})\s*[-./년]\s*(\d{1,2})\s*[-./월]\s*(\d{1,2})/);
		if (m) return `${m[1]}-${m[2].padStart(2, '0')}-${m[3].padStart(2, '0')}`;
		m = v.match(/^(\d{2})[-./](\d{1,2})[-./](\d{1,2})$/);
		if (m) return `20${m[1]}-${m[2].padStart(2, '0')}-${m[3].padStart(2, '0')}`;
		return v;
	}
	const rowsOut = $derived(
		body.map((r) => {
			const o: Record<string, string | number> = {};
			for (const f of fields) {
				const i = map[f.key] ?? -1;
				let v = i >= 0 ? (r[i] ?? '').toString().trim() : '';
				if (f.key === 'date') v = normDate(v);
				if (f.key === 'amount') v = v.replace(/[,\s원]/g, '');
				o[f.key] = v;
			}
			return o;
		})
	);

	// ---------- 서버 ----------
	async function runChunks(path: string, extra: Record<string, unknown>): Promise<Res[]> {
		const all: Res[] = [];
		for (let i = 0; i < rowsOut.length; i += CHUNK) {
			const r = await auth.api<{ rows: Res[] }>('POST', path, { ...extra, rows: rowsOut.slice(i, i + CHUNK) });
			all.push(...r.rows);
		}
		return all;
	}
	const opts = $derived(kind === 'offerings' ? { createOutsiders, skipDup } : { year, type: kind, createUnits });
	const base = $derived(kind === 'offerings' ? '/import/offerings' : '/import/budget');

	async function check() {
		busy = true;
		err = '';
		notice = '';
		try {
			res = await runChunks(base + '/preview', opts);
			step = 'check';
		} catch (e) {
			err = errText(e);
		} finally {
			busy = false;
		}
	}
	const good = $derived(res.filter((r) => r.ok && !(r.dup && skipDup)).length);
	const dups = $derived(res.filter((r) => r.ok && r.dup).length);
	const bad = $derived(res.map((r, i) => ({ r, i })).filter((x) => !x.r.ok));

	async function commit() {
		if (!confirm(`${good}줄을 가져옵니다. 진행할까요?`)) return;
		busy = true;
		err = '';
		try {
			const out = await runChunks(base + '/commit', opts);
			const n = out.filter((r) => (kind === 'offerings' ? r.added : r.ok)).length;
			doneInfo = `${n}줄을 가져왔어요.` + (out.some((r) => !r.ok) ? ` 맞지 않은 ${out.filter((r) => !r.ok).length}줄은 가져오지 않았습니다.` : '');
			if (kind === 'offerings') pending = (await auth.api<{ items: typeof pending }>('GET', '/import/offerings/pending')).items;
			step = 'done';
		} catch (e) {
			err = errText(e);
		} finally {
			busy = false;
		}
	}
	async function closeAll() {
		busy = true;
		err = '';
		try {
			const r = await auth.api<{ closed: number }>('POST', '/import/offerings/close', { cashWalletId: cl.cash || undefined, bankWalletId: cl.bank || undefined });
			notice = `${r.closed}개 묶음을 마감했어요. 장부에 수입으로 올라갔습니다.`;
			pending = [];
		} catch (e) {
			err = errText(e);
		} finally {
			busy = false;
		}
	}
	function template() {
		const a = document.createElement('a');
		a.href = URL.createObjectURL(new Blob(['﻿' + SAMPLE[kind]], { type: 'text/csv' }));
		a.download = { offerings: '헌금내역-양식.csv', expense: '지출예산-양식.csv', income: '수입예산-양식.csv' }[kind];
		a.click();
		URL.revokeObjectURL(a.href);
	}
</script>

<div class="row page-head"><h1>기존 자료 가져오기</h1></div>
{#if err}<div class="warn">{err}</div>{/if}
{#if notice}<div class="note">{notice}</div>{/if}

<div class="card">
	<div class="card-head"><h2>1. 무엇을 가져올까요?</h2></div>
	<div class="type-chips">
		<button type="button" class:on={kind === 'offerings'} onclick={() => changeKind('offerings')}>헌금 내역</button>
		<button type="button" class:on={kind === 'expense'} onclick={() => changeKind('expense')}>지출 예산</button>
		<button type="button" class:on={kind === 'income'} onclick={() => changeKind('income')}>수입 예산</button>
	</div>
	<p class="fs-s muted">
		{#if kind === 'offerings'}날짜·헌금 종류·이름·금액이 있는 표. 날짜가 속한 회계연도가 <b>기준 자료</b>에 먼저 있어야 하고, 이름은 교적의 교인과 맞춥니다. 한 명으로만 맞는 이름만 넣고 동명이인은 출생연도 칸으로 구분합니다.
		{:else if kind === 'expense'}부서(재정 단위 이름)·예산 항목·금액이 있는 표. 예산이 확정되기 전에만 가져올 수 있어요. 이미 있는 항목은 금액만 바뀝니다.
		{:else}수입 항목(세부 이름)·예산 금액이 있는 표. 예산이 확정되기 전에만 가져올 수 있어요.{/if}
	</p>
	{#if kind !== 'offerings'}<div class="row"><span class="fs-s muted">회계연도</span><input type="number" bind:value={year} style="width:6.5rem" /></div>{/if}
	<button class="ghost sm mt" type="button" onclick={template}>양식 내려받기 (CSV)</button>
</div>

<div class="card">
	<div class="card-head"><h2>2. 파일 불러오기</h2>{#if fileName}<span class="sub">{fileName} · {grid.length}줄</span>{/if}</div>
	<input type="file" accept=".xlsx,.csv,.tsv,.txt" onchange={onFile} disabled={busy} />
	<p class="fs-s muted">엑셀(.xlsx) 또는 CSV. 또는 엑셀에서 표를 복사해서 아래에 붙여넣으세요.</p>
	<textarea bind:value={paste} rows="4" placeholder="여기에 엑셀에서 복사한 표를 붙여넣기 (첫 줄은 머리글)"></textarea>
	<button class="sm mt" type="button" disabled={busy || !paste.trim()} onclick={onPaste}>붙여넣은 표 읽기</button>
</div>

{#if grid.length && step !== 'load'}
	<div class="card">
		<div class="card-head"><h2>3. 칸 맞추기</h2><label class="row fs-s"><span class="switch"><input type="checkbox" bind:checked={hasHeader} onchange={guess} /><i></i></span> 첫 줄은 머리글</label></div>
		<div class="form">
			{#each fields as f}
				<label class="fs-s" style="display:flex;flex-direction:column;gap:.25rem">{f.label}{f.required ? ' *' : ''}
					<select bind:value={map[f.key]} onchange={reset}><option value={-1}>(없음)</option>{#each Array.from({ length: cols }, (_, i) => i) as i}<option value={i}>{i + 1}번 칸{header[i] ? ' · ' + header[i] : ''}</option>{/each}</select>
				</label>
			{/each}
		</div>
		<div class="scroll-x mt"><table class="sm">
			<thead><tr>{#each fields.filter((f) => (map[f.key] ?? -1) >= 0) as f}<th>{f.label}</th>{/each}</tr></thead>
			<tbody>{#each rowsOut.slice(0, 5) as r}<tr>{#each fields.filter((f) => (map[f.key] ?? -1) >= 0) as f}<td>{r[f.key]}</td>{/each}</tr>{/each}</tbody>
		</table></div>
		<p class="fs-s muted">앞 5줄 미리 보기 · 전체 {rowsOut.length}줄</p>
		{#if kind === 'offerings'}
			<label class="row fs-s"><span class="switch"><input type="checkbox" bind:checked={createOutsiders} onchange={reset} /><i></i></span> 교적에 없는 이름은 '교인이 아닌 헌금자'로 새로 만들기 (오타도 새 헌금자가 되니 먼저 검사로 확인하세요)</label>
			<label class="row fs-s"><span class="switch"><input type="checkbox" bind:checked={skipDup} onchange={reset} /><i></i></span> 이미 있는 같은 줄(날짜·종류·이름·금액)은 건너뛰기</label>
		{:else if kind === 'expense'}
			<label class="row fs-s"><span class="switch"><input type="checkbox" bind:checked={createUnits} onchange={reset} /><i></i></span> 없는 부서는 새로 만들기</label>
		{/if}
		{#if missing.length}<div class="warn fs-s">맞추지 않은 필수 칸: {missing.join(', ')}</div>{/if}
		<div class="row mt" style="justify-content:flex-end"><button type="button" disabled={busy || missing.length > 0 || !rowsOut.length} onclick={check}>검사하기 (아직 저장하지 않아요)</button></div>
	</div>
{/if}

{#if step === 'check'}
	<div class="card">
		<div class="card-head"><h2>4. 검사 결과</h2></div>
		<div class="kpis">
			<div class="kpi"><div><b>{good}</b><span>가져올 줄</span></div></div>
			<div class="kpi"><div><b>{bad.length}</b><span>맞지 않는 줄</span></div></div>
			{#if kind === 'offerings'}<div class="kpi"><div><b>{dups}</b><span>이미 있는 줄{skipDup ? ' (건너뜀)' : ''}</span></div></div>{/if}
		</div>
		{#if bad.length}
			<table class="sm"><thead><tr><th>줄</th><th>이유</th></tr></thead><tbody>{#each bad.slice(0, 100) as x}<tr><td>{x.i + 1 + (hasHeader ? 1 : 0)}번째 줄</td><td>{x.r.error}</td></tr>{/each}</tbody></table>
			{#if bad.length > 100}<p class="fs-s muted">앞 100개만 보여 줍니다.</p>{/if}
			<p class="fs-s muted">맞지 않는 줄은 가져오지 않습니다. 파일을 고쳐 다시 불러오거나, 이대로 맞는 줄만 가져올 수 있어요.</p>
		{/if}
		<div class="row mt" style="justify-content:flex-end"><button type="button" disabled={busy || !good} onclick={commit}>{good}줄 가져오기</button></div>
	</div>
{/if}

{#if step === 'done'}
	<div class="card">
		<div class="card-head"><h2>5. 가져왔어요</h2></div>
		<p>{doneInfo}</p>
		{#if kind === 'offerings' && pending.length}
			<p class="fs-s muted">날짜별 '가져온 자료' 묶음 {pending.length}개가 입력 중 상태예요. 헌금 화면에서 내용을 확인한 뒤 아래에서 한꺼번에 마감하면 장부에 수입으로 올라갑니다. (마감한 달은 고칠 수 없어요.)</p>
			<table class="sm"><tbody>{#each pending.slice(0, 12) as p}<tr><td>{p.date}</td><td>{p.count}건</td><td class="right money">{p.total.toLocaleString('ko-KR')}</td></tr>{/each}</tbody></table>
			{#if pending.length > 12}<p class="fs-s muted">외 {pending.length - 12}개 묶음</p>{/if}
			<div class="form mt">
				<select bind:value={cl.cash} aria-label="현금 입금 계좌"><option value={0}>현금 입금 계좌</option>{#each wallets.filter((w) => w.active && w.kind === '현금') as w}<option value={w.id}>{w.name}</option>{/each}</select>
				<select bind:value={cl.bank} aria-label="통장 입금 계좌"><option value={0}>통장 입금 계좌</option>{#each wallets.filter((w) => w.active && w.kind === '통장') as w}<option value={w.id}>{w.name}</option>{/each}</select>
				<button type="button" disabled={busy} onclick={closeAll}>가져온 묶음 모두 마감</button>
			</div>
		{/if}
		<button class="ghost sm mt" type="button" onclick={() => { grid = []; paste = ''; fileName = ''; step = 'load'; res = []; }}>다른 자료 가져오기</button>
	</div>
{/if}

<script lang="ts">
	// 지출결의서: 목록, 기안(증빙 사진 올리기·결재선 미리보기), 상신·회수, 결재·반려, 지급 처리, 출력
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { won, localDate, errText } from '#lib/fmt.ts';
	import { hrefTo } from '#lib/paths.ts';

	type Item = { id: number; name: string; remain: number; directOk: boolean };
	type BU = { id: number; kind: string; name: string; items: Item[] };
	type Step = { round: number; no: number; role: string; name: string; mode: string; status: string; actedAt: string | null; delegate: boolean; comment: string | null; hash: string | null };
	type V = {
		id: number; docNo: string | null; year: number; unitId: number; unit: string; drafter: string; title: string; reason: string | null; total: number; payMethod: string; payBank: string | null; payAccount: string | null; payHolder: string | null;
		desiredDate: string | null; noProofReason: string | null; status: string; rev: number; round: number; submittedAt: string | null; approvedAt: string | null; paidAt: string | null;
		items: { id: number; budgetItemId: number; item: string; summary: string; amount: number }[]; files: { id: number; mime: string; size: number; name: string | null }[]; steps: Step[];
		can: { edit: boolean; submit: boolean; recall: boolean; cancel: boolean; approve: boolean; confirm: boolean; pay: boolean };
		preview?: { error: string | null; rule: string | null; steps: { role: string; name: string; mode: string; skip: boolean }[] }; overBudget?: boolean;
	};
	type Row = { id: number; docNo: string | null; title: string; total: number; status: string; drafter: string; unit: string; at: string };

	const roles = $derived(auth.me?.access?.roles ?? []);
	const isStaff = $derived(roles.includes('재정 담당'));
	let years = $state<number[]>([]);
	let year = $state(0);
	let status = $state('');
	let list = $state<Row[]>([]);
	let units = $state<BU[]>([]);
	let sel = $state<number | null>(null);
	let v = $state<V | null>(null);
	let wallets = $state<{ id: number; name: string; active: boolean }[]>([]);
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let churchName = $derived(auth.church);

	type Form = { unitId: number; title: string; reason: string; payMethod: string; payBank: string; payAccount: string; payHolder: string; desiredDate: string; noProofReason: string; items: { budgetItemId: number; summary: string; amount: string }[] };
	let form = $state<Form | null>(null);
	let pw = $state('');
	let comment = $state('');
	let pay = $state({ walletId: 0, paidOn: localDate() });

	const unitItems = (id: number) => units.find((u) => u.id === id)?.items ?? [];
	const formTotal = $derived((form?.items ?? []).reduce((s, i) => s + (Number(String(i.amount).replace(/,/g, '')) || 0), 0));

	async function boot() {
		try {
			const r = await auth.api<{ years: { year: number; status: string }[]; wallets: { id: number; name: string; active: boolean }[] }>('GET', '/base');
			years = r.years.map((y) => y.year);
			wallets = r.wallets;
			pay.walletId = r.wallets.find((w) => w.active)?.id ?? 0;
			year = (r.years.find((y) => y.status === '진행') ?? r.years[0])?.year ?? 0;
			if (year) await loadList();
		} catch (e) {
			err = errText(e);
		}
	}
	async function loadList() {
		try {
			list = (await auth.api<{ items: Row[] }>('GET', `/vouchers?year=${year}${status ? '&status=' + encodeURIComponent(status) : ''}`)).items;
			units = (await auth.api<{ units: BU[] }>('GET', '/budget?year=' + year)).units.filter((u) => u.kind !== '위원회' || u.items.length);
			err = '';
		} catch (e) {
			err = errText(e);
		}
	}
	async function open(id: number) {
		sel = id;
		form = null;
		try {
			v = await auth.api<V>('GET', '/vouchers/' + id);
			pw = '';
			comment = '';
			err = '';
		} catch (e) {
			err = errText(e);
		}
	}
	onMount(() => {
		boot().then(() => {
			const id = Number(new URLSearchParams(location.search).get('v'));
			if (id) open(id);
		});
	});

	function startNew() {
		sel = null;
		v = null;
		form = { unitId: units.find((u) => u.kind !== '기타' || true)?.id ?? 0, title: '', reason: '', payMethod: '계좌이체', payBank: '', payAccount: '', payHolder: '', desiredDate: '', noProofReason: '', items: [{ budgetItemId: 0, summary: '', amount: '' }] };
	}
	function startEdit() {
		if (!v) return;
		form = { unitId: v.unitId, title: v.title, reason: v.reason ?? '', payMethod: v.payMethod, payBank: v.payBank ?? '', payAccount: v.payAccount ?? '', payHolder: v.payHolder ?? '', desiredDate: v.desiredDate ?? '', noProofReason: v.noProofReason ?? '', items: v.items.map((i) => ({ budgetItemId: i.budgetItemId, summary: i.summary, amount: String(i.amount) })) };
	}
	const payload = () => ({ ...form, items: form!.items.map((i) => ({ budgetItemId: i.budgetItemId, summary: i.summary, amount: Number(String(i.amount).replace(/,/g, '')) })) });

	async function act(fn: () => Promise<unknown>, ok = '', reopen: number | null = null) {
		busy = true;
		err = '';
		notice = '';
		try {
			await fn();
			if (ok) notice = ok;
			await loadList();
			const id = reopen ?? sel;
			if (id) await open(id);
			return true;
		} catch (e) {
			err = errText(e);
			return false;
		} finally {
			busy = false;
		}
	}
	async function saveForm() {
		if (!form) return;
		busy = true;
		err = '';
		try {
			if (v && sel) {
				await auth.api('POST', '/vouchers/' + sel, { ...payload(), rev: v.rev });
				form = null;
				await loadList();
				await open(sel);
				notice = '저장했어요.';
			} else {
				const r = await auth.api<{ id: number }>('POST', '/vouchers', payload());
				form = null;
				await loadList();
				await open(r.id);
				notice = '임시 저장했어요. 증빙을 올리고 상신하세요.';
			}
		} catch (e) {
			err = errText(e);
		} finally {
			busy = false;
		}
	}

	/** 사진을 줄여서 올린다 (호스팅의 요청 크기 제한 때문에 약 90KB 이하로) */
	async function shrink(file: File): Promise<{ data: string; name: string }> {
		if (file.type === 'application/pdf') {
			if (file.size > 78000) throw new Error('PDF는 78KB 이하만 올릴 수 있어요. 사진으로 찍어 올리면 자동으로 줄여 줍니다.');
			return { data: await b64(file), name: file.name };
		}
		const bmp = await createImageBitmap(file);
		let scale = Math.min(1, 1400 / Math.max(bmp.width, bmp.height));
		for (let q = 0.8, n = 0; n < 8; n++) {
			const c = document.createElement('canvas');
			c.width = Math.round(bmp.width * scale);
			c.height = Math.round(bmp.height * scale);
			c.getContext('2d')!.drawImage(bmp, 0, 0, c.width, c.height);
			const blob: Blob = await new Promise((res) => c.toBlob((b) => res(b!), 'image/jpeg', q));
			if (blob.size <= 75000) return { data: await b64(blob), name: file.name.replace(/\.\w+$/, '') + '.jpg' };
			if (q > 0.5) q -= 0.1;
			else scale *= 0.8;
		}
		throw new Error('사진을 충분히 줄이지 못했어요. 다른 사진으로 시도해 주세요.');
	}
	const b64 = (b: Blob): Promise<string> =>
		new Promise((res, rej) => {
			const r = new FileReader();
			r.onload = () => res(String(r.result).split(',')[1]);
			r.onerror = () => rej(new Error('파일을 읽지 못했어요.'));
			r.readAsDataURL(b);
		});
	async function upload(e: Event) {
		const input = e.target as HTMLInputElement;
		const files = Array.from(input.files ?? []);
		input.value = '';
		if (!v || !files.length) return;
		busy = true;
		err = '';
		try {
			for (const f of files) {
				const s = await shrink(f);
				await auth.api('POST', `/vouchers/${v.id}/files`, s);
			}
			await open(v.id);
		} catch (e) {
			err = errText(e);
		} finally {
			busy = false;
		}
	}
	const delFile = (id: number) => act(() => auth.api('POST', `/voucher-files/${id}/delete`, {}));
	const fileUrl = (id: number) => hrefTo('/api/v1/voucher-files/' + id);

	const submit = () => act(() => auth.api('POST', `/vouchers/${v!.id}/submit`, { rev: v!.rev }), '상신했어요. 결재선이 고정되었습니다.');
	const recall = () => act(() => auth.api('POST', `/vouchers/${v!.id}/recall`, { rev: v!.rev }), '회수했어요. 고쳐서 다시 상신할 수 있어요.');
	const cancel = () => confirm('이 결의서를 취소할까요?') && act(() => auth.api('POST', `/vouchers/${v!.id}/cancel`, { rev: v!.rev }), '취소했어요.');
	const approve = () => act(() => auth.api('POST', `/vouchers/${v!.id}/approve`, { rev: v!.rev, password: pw, comment }), '결재했어요.');
	const reject = () => act(() => auth.api('POST', `/vouchers/${v!.id}/reject`, { rev: v!.rev, password: pw, comment }), '반려했어요.');
	const doPay = () => act(() => auth.api('POST', `/vouchers/${v!.id}/pay`, { rev: v!.rev, walletId: pay.walletId, paidOn: pay.paidOn }), '지급 처리했어요. 장부에 지출로 올라갔습니다.');

	const stTag = (s: string) => (s === '지급 완료' || s === '결재 완료' ? 'now' : s === '반려' || s === '취소' ? '' : 'part');
	const curRound = (s: Step) => v && s.round === v.round;
</script>

<div class="row page-head no-print">
	<h1>지출결의서</h1>
	<span class="sp"></span>
	<div class="row">
		<select bind:value={year} onchange={() => { sel = null; v = null; loadList(); }} style="width:auto" aria-label="회계연도">{#each years as y}<option value={y}>{y}년</option>{/each}</select>
		<button type="button" onclick={startNew}>+ 새 결의서</button>
	</div>
</div>
{#if err}<div class="warn no-print">{err}</div>{/if}
{#if notice}<div class="note no-print">{notice}</div>{/if}

<div class="two two-l">
	<div class="card no-print">
		<div class="card-head"><h2>목록</h2>
			<select bind:value={status} onchange={loadList} style="width:auto" aria-label="상태"><option value="">전체</option>{#each ['작성 중', '결재 중', '결재 완료', '지급 완료', '반려', '회수', '취소'] as s}<option>{s}</option>{/each}</select>
		</div>
		{#each list as r (r.id)}
			<button type="button" class="vlist" class:on={sel === r.id} onclick={() => open(r.id)}>
				<span><b>{r.title}</b><small>{r.docNo ?? '번호 전'} · {r.unit} · {r.drafter}</small></span>
				<span class="r"><span class="tag {stTag(r.status)}">{r.status}</span><b class="money">{won(r.total)}</b></span>
			</button>
		{:else}
			<p class="muted">결의서가 없어요.</p>
		{/each}
	</div>

	<div>
		{#if form}
			<form class="card no-print" onsubmit={(e) => { e.preventDefault(); saveForm(); }}>
				<div class="card-head"><h2>{v ? '결의서 수정' : '새 결의서'}</h2></div>
				<div class="form">
					<select bind:value={form.unitId} disabled={!!v} aria-label="재정 단위" required>{#each units as u}<option value={u.id}>{u.name}</option>{/each}</select>
					<input bind:value={form.title} placeholder="제목 (예: 11월 교사 수련회 다과)" maxlength="150" required />
					<textarea bind:value={form.reason} rows="2" placeholder="지출 사유" maxlength="2000"></textarea>
					<select bind:value={form.payMethod} aria-label="지급 방법">{#each ['계좌이체', '먼저 쓰고 청구', '현금', '법인카드'] as m}<option>{m}</option>{/each}</select>
					{#if form.payMethod === '계좌이체' || form.payMethod === '먼저 쓰고 청구'}
						<div class="form bank-row" style="padding:0"><input bind:value={form.payBank} placeholder="은행" maxlength="30" /><input bind:value={form.payAccount} placeholder="계좌번호" inputmode="numeric" maxlength="40" /><input bind:value={form.payHolder} placeholder="예금주" maxlength="50" /></div>
					{/if}
					<input type="date" bind:value={form.desiredDate} aria-label="지급 희망일" />
				</div>
				<h3>지출 내역</h3>
				{#each form.items as it, n}
					<div class="item-row">
						<label>예산 항목<select bind:value={it.budgetItemId} required><option value={0} disabled>선택</option>{#each unitItems(form.unitId) as b}<option value={b.id}>{b.name} (남음 {won(b.remain)})</option>{/each}</select></label>
						<label>적요<input bind:value={it.summary} maxlength="255" required /></label>
						<label>금액<input bind:value={it.amount} inputmode="numeric" class="amount-in" required /></label>
						<button type="button" class="ghost sm" disabled={form.items.length < 2} onclick={() => form!.items.splice(n, 1)}>삭제</button>
					</div>
				{/each}
				<div class="row" style="justify-content:space-between;flex-wrap:wrap">
					<button type="button" class="ghost sm" onclick={() => form!.items.push({ budgetItemId: 0, summary: '', amount: '' })}>+ 줄 추가</button>
					<b>합계 {won(formTotal)}원</b>
				</div>
				<div class="row mt" style="justify-content:flex-end"><button type="button" class="ghost" onclick={() => (form = null)}>닫기</button><button type="submit" disabled={busy}>{v ? '저장' : '임시 저장'}</button></div>
			</form>
		{:else if v}
			{#if v.can.submit && v.preview}
				<div class="card no-print">
					<div class="card-head"><h2>상신 전 확인</h2><span class="sub">{v.preview.rule ? v.preview.rule + ' 구간' : ''}</span></div>
					{#if v.preview.error}<div class="warn fs-s">{v.preview.error}</div>{:else}
						<div class="steps">{#each v.preview.steps as s, n}{#if n}<span class="arr">→</span>{/if}<span class="sc" class:c={s.mode === '확인' || s.skip}>{s.role} {s.name}{s.mode === '확인' ? ' (확인)' : ''}{s.skip ? ' (기안자 본인)' : ''}</span>{/each}</div>
					{/if}
					{#if v.overBudget}<div class="warn fs-s">예산 항목의 남은 금액보다 많아요. 상신하면 결재선에 추가 단계가 더해질 수 있어요.</div>{/if}
					<div class="row mt" style="flex-wrap:wrap">
						<label class="ghost sm" style="cursor:pointer;display:inline-block;padding:.45rem .8rem;border:1px solid var(--line-strong);border-radius:10px">+ 증빙 사진·PDF<input type="file" accept="image/*,application/pdf" multiple capture={undefined} onchange={upload} style="display:none" /></label>
						{#each v.files as f}<span class="sc">{f.name ?? '증빙'} <button type="button" class="x" onclick={() => delFile(f.id)} aria-label="삭제">×</button></span>{/each}
					</div>
					{#if !v.files.length}<p class="fs-s muted">증빙이 없으면 사유를 적어야 상신할 수 있어요: <input bind:value={v.noProofReason} placeholder="증빙 없는 사유" style="max-width:18rem" onchange={() => act(() => auth.api('POST', '/vouchers/' + v!.id, { rev: v!.rev, unitId: v!.unitId, title: v!.title, reason: v!.reason ?? '', payMethod: v!.payMethod, payBank: v!.payBank ?? '', payAccount: v!.payAccount ?? '', payHolder: v!.payHolder ?? '', desiredDate: v!.desiredDate ?? '', noProofReason: v!.noProofReason ?? '', items: v!.items.map((i) => ({ budgetItemId: i.budgetItemId, summary: i.summary, amount: i.amount })) }))} /></p>{/if}
					<div class="row mt" style="justify-content:flex-end;flex-wrap:wrap">
						<button class="ghost sm" type="button" onclick={startEdit}>내용 수정</button>
						<button class="ghost sm" type="button" disabled={busy} onclick={cancel}>취소</button>
						<button type="button" disabled={busy || !!v.preview.error} onclick={submit}>상신</button>
					</div>
				</div>
			{/if}

			<div class="card paper-card">
				<div class="card-head no-print"><h2>{v.title}</h2><span class="tag {stTag(v.status)}">{v.status}</span></div>
				<div class="paper">
					<h2 style="text-align:center;margin:0 0 .6rem">지 출 결 의 서</h2>
					<p style="text-align:center;margin:0 0 .8rem"><small>{churchName} · 문서번호 {v.docNo ?? '(상신 시 부여)'}</small></p>
					<div class="scroll-x" style="margin-bottom:.6rem">
						<table class="sm" style="text-align:center"><thead><tr><th>기안</th>{#each v.steps.filter((s) => s.round === v!.round && s.status !== '건너뜀') as s}<th>{s.role}{s.mode === '확인' ? '(확인)' : ''}</th>{/each}</tr></thead>
							<tbody><tr><td>{v.drafter}<br /><small>{(v.submittedAt ?? '').slice(0, 10)}</small></td>{#each v.steps.filter((s) => s.round === v!.round && s.status !== '건너뜀') as s}<td>{s.name}<br /><small>{s.status === '완료' ? (s.actedAt ?? '').slice(0, 16) + (s.delegate ? ' 대결' : '') + ' 전자결재' : s.status === '반려' ? '반려' : '대기'}</small></td>{/each}</tr></tbody></table>
					</div>
					<table class="sm"><tbody>
						<tr><th>재정 단위</th><td>{v.unit}</td><th>지급 방법</th><td>{v.payMethod}</td></tr>
						<tr><th>받을 분</th><td colspan="3">{[v.payBank, v.payAccount, v.payHolder].filter(Boolean).join(' ') || '-'}</td></tr>
						<tr><th>지출 사유</th><td colspan="3">{v.reason ?? ''}</td></tr>
					</tbody></table>
					<table class="sm mt"><thead><tr><th>예산 항목</th><th>적요</th><th class="right">금액</th></tr></thead>
						<tbody>{#each v.items as i}<tr><td>{i.item}</td><td>{i.summary}</td><td class="right money">{won(i.amount)}</td></tr>{/each}<tr class="tot"><td colspan="2">합계</td><td class="right money">{won(v.total)}</td></tr></tbody></table>
					{#if v.noProofReason}<p class="fs-s">증빙 없는 사유: {v.noProofReason}</p>{/if}
					{#if v.files.length}<div class="proofs" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.6rem">{#each v.files as f}{#if f.mime.startsWith('image/')}<a href={fileUrl(f.id)} target="_blank" rel="noopener"><img src={fileUrl(f.id)} alt={f.name ?? '증빙'} style="max-height:150px;border:1px solid var(--line);border-radius:8px" /></a>{:else}<a href={fileUrl(f.id)} target="_blank" rel="noopener" class="sc">{f.name ?? 'PDF'}</a>{/if}{/each}</div>{/if}
				</div>
				{#if v.steps.some((s) => s.comment)}
					<div class="fs-s muted no-print" style="margin-top:.6rem">{#each v.steps.filter((s) => s.comment) as s}<div>{curRound(s) ? '' : s.round + '회차 '}{s.role} {s.name}: {s.comment}{s.hash ? ' (내용 확인값 ' + s.hash + ')' : ''}</div>{/each}</div>
				{/if}
				<div class="row mt no-print" style="justify-content:flex-end;flex-wrap:wrap">
					<button class="ghost sm" type="button" onclick={() => window.print()}>인쇄</button>
					{#if v.can.recall}<button class="ghost sm" type="button" disabled={busy} onclick={recall}>회수</button>{/if}
					{#if v.can.edit && !v.can.submit}<button class="ghost sm" type="button" onclick={startEdit}>수정</button>{/if}
				</div>
			</div>

			{#if v.can.approve || v.can.confirm}
				<div class="card no-print">
					<div class="card-head"><h2>{v.can.approve ? '결재' : '확인'}</h2><span class="sub">비밀번호를 다시 확인합니다</span></div>
					<div class="form">
						<input bind:value={comment} placeholder="의견 (반려는 사유 필수)" maxlength="500" />
						<input type="password" bind:value={pw} placeholder="비밀번호" autocomplete="current-password" />
					</div>
					<div class="row mt" style="justify-content:flex-end">
						{#if v.can.approve}<button class="ghost" type="button" disabled={busy || !pw || !comment} onclick={reject}>반려</button>{/if}
						<button type="button" disabled={busy || !pw} onclick={approve}>{v.can.approve ? '결재' : '확인'}</button>
					</div>
				</div>
			{/if}
			{#if v.can.pay}
				<div class="card no-print">
					<div class="card-head"><h2>지급 처리</h2><span class="sub">장부에 지출로 올라갑니다</span></div>
					<div class="form"><select bind:value={pay.walletId} aria-label="출금 계좌">{#each wallets.filter((w) => w.active) as w}<option value={w.id}>{w.name}</option>{/each}</select><input type="date" bind:value={pay.paidOn} /></div>
					<div class="row mt" style="justify-content:flex-end"><button type="button" disabled={busy} onclick={doPay}>지급 완료</button></div>
				</div>
			{/if}
		{:else}
			<div class="card no-print"><p class="muted">왼쪽에서 결의서를 고르거나 새로 쓰세요. 결의서는 그 단위의 회계·부서장·위원장과 재정 담당이 쓸 수 있어요.</p></div>
		{/if}
	</div>
</div>

<style>
	.vlist { display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; width: 100%; text-align: left; background: #fff; color: inherit; border: 1px solid var(--line); box-shadow: none; border-radius: 12px; padding: 0.55rem 0.8rem; margin-bottom: 0.4rem; font-weight: 400; }
	.vlist.on { background: var(--primary-100); border-color: var(--primary-200); }
	.vlist small { display: block; color: var(--muted); font-size: 0.74rem; }
	.vlist .r { display: inline-flex; flex-direction: column; align-items: flex-end; gap: 0.2rem; }
	.x { background: transparent; border: 0; box-shadow: none; padding: 0 0.2rem; color: inherit; cursor: pointer; }
</style>

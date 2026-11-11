<script lang="ts">
	// 교회 정보 · 직인 · 영수증 서식 · 예산 초과 처리 · 주민번호 방식 (재정 관리자)
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { hrefTo } from '#lib/paths.ts';
	import { errText } from '#lib/fmt.ts';

	type S = { links?: { cm: string; dm: string }; church: { name?: string; bizNo?: string; address?: string; pastorName?: string; phone?: string }; rrnMode: string; overBudget: string; hasSeal: boolean; receiptForm: { line_mode: string; code: string } };
	let st = $state<S | null>(null);
	let church = $state({ name: '', bizNo: '', address: '', pastorName: '', phone: '' });
	let form = $state({ lineMode: 'year', code: '41' });
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let links = $state({ cm: 'cm', dm: '' });
	let purgeYears = $state(5);
	let purgeCount = $state<number | null>(null);
	let newKey = $state('');
	let sealTick = $state(0);

	async function load() {
		try {
			st = await auth.api<S>('GET', '/settings');
			church = { name: st.church.name ?? '', bizNo: st.church.bizNo ?? '', address: st.church.address ?? '', pastorName: st.church.pastorName ?? '', phone: st.church.phone ?? '' };
			links = { cm: st.links?.cm ?? 'cm', dm: st.links?.dm ?? '' };
			form = { lineMode: st.receiptForm.line_mode, code: st.receiptForm.code };
			err = '';
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
	const saveChurch = () => run(() => auth.api('POST', '/settings/church', church), '교회 정보를 저장했어요. 이미 발급한 영수증은 발급 당시 내용 그대로 남습니다.');
	const saveLinks = () => run(() => auth.api('POST', '/settings/links', links), '바로가기를 저장했어요.');
	const saveForm = () => run(() => auth.api('POST', '/settings/receipt-form', form), '영수증 서식을 저장했어요.');
	const setOver = (mode: string) => run(() => auth.api('POST', '/settings/over-budget', { mode }), '예산 초과 처리 방식을 바꿨어요.');

	async function sealFile(e: Event) {
		const input = e.target as HTMLInputElement;
		const f = input.files?.[0];
		input.value = '';
		if (!f) return;
		busy = true;
		err = '';
		try {
			const bmp = await createImageBitmap(f);
			let scale = Math.min(1, 500 / Math.max(bmp.width, bmp.height));
			let blob: Blob | null = null;
			for (let n = 0; n < 6 && !blob; n++) {
				const c = document.createElement('canvas');
				c.width = Math.round(bmp.width * scale);
				c.height = Math.round(bmp.height * scale);
				c.getContext('2d')!.drawImage(bmp, 0, 0, c.width, c.height);
				const b: Blob = await new Promise((res) => c.toBlob((x) => res(x!), 'image/png'));
				if (b.size <= 75000) blob = b;
				else scale *= 0.75;
			}
			if (!blob) throw new Error('직인 이미지를 충분히 줄이지 못했어요. 더 단순한 이미지를 써 주세요.');
			const data: string = await new Promise((res, rej) => {
				const r = new FileReader();
				r.onload = () => res(String(r.result).split(',')[1]);
				r.onerror = () => rej(new Error('파일을 읽지 못했습니다.'));
				r.readAsDataURL(blob!);
			});
			await auth.api('POST', '/settings/seal', { data });
			sealTick++;
			notice = '직인을 올렸어요. 영수증 인쇄에 쓰입니다. (배경이 투명한 PNG를 권합니다)';
			await load();
		} catch (x) {
			err = errText(x);
		} finally {
			busy = false;
		}
	}
	const delSeal = () => run(() => auth.api('POST', '/settings/seal/delete', {}), '직인을 지웠어요.');

	async function setRrn(mode: 'keep' | 'none') {
		let confirmWord = '';
		if (mode === 'none') {
			confirmWord = prompt("저장된 주민등록번호가 모두 지워지고 되돌릴 수 없습니다. 계속하려면 '번호 삭제'를 입력하세요.") ?? '';
			if (!confirmWord) return;
		} else if (!confirm('주민등록번호를 암호화해 보관하는 방식으로 바꿉니다. 암호화 열쇠가 새로 만들어지면 화면에 한 번만 보여 드려요. 진행할까요?')) return;
		busy = true;
		err = '';
		notice = '';
		newKey = '';
		try {
			const r = await auth.guard(() => auth.api<{ newKey: string | null }>('POST', '/settings/rrn-mode', { mode, confirm: confirmWord }));
			if (r.newKey) newKey = r.newKey;
			notice = '주민번호 방식을 바꿨어요.';
			await load();
		} catch (e) {
			if (!(e instanceof Error && e.message.includes('취소'))) err = errText(e);
		} finally {
			busy = false;
		}
	}
	async function purge(apply: boolean) {
		busy = true;
		err = '';
		notice = '';
		try {
			const r = await auth.guard(() => auth.api<{ count: number; applied: boolean }>('POST', '/settings/rrn-purge', { years: purgeYears, apply }));
			purgeCount = r.count;
			if (r.applied) notice = `${r.count}명의 주민번호를 지웠어요.`;
		} catch (e) {
			if (!(e instanceof Error && e.message.includes('취소'))) err = errText(e);
		} finally {
			busy = false;
		}
	}
</script>

<div class="row page-head"><h1>교회 정보 · 영수증 · 보안 설정</h1></div>
{#if err}<div class="warn">{err}</div>{/if}
{#if notice}<div class="note">{notice}</div>{/if}

{#if st}
	<form class="card" onsubmit={(e) => { e.preventDefault(); saveChurch(); }}>
		<div class="card-head"><h2>교회 정보</h2><span class="sub">영수증·결의서 인쇄에 쓰입니다</span></div>
		<div class="form">
			<input bind:value={church.name} placeholder="교회 이름" maxlength="60" required />
			<input bind:value={church.bizNo} placeholder="고유번호 (예: 123-82-12345)" maxlength="20" />
			<input bind:value={church.address} placeholder="소재지" maxlength="200" />
			<input bind:value={church.pastorName} placeholder="대표자 (영수증에 찍힘)" maxlength="50" />
			<input bind:value={church.phone} placeholder="전화" maxlength="30" />
		</div>
		{#if !st.church.bizNo || !st.church.address}<div class="warn fs-s">고유번호와 소재지가 비어 있으면 기부금 영수증에 빈칸으로 나가요. 영수증을 발급하기 전에 꼭 채워 주세요.</div>{/if}
		<div class="row mt" style="justify-content:flex-end"><button type="submit" disabled={busy}>저장</button></div>
	</form>

	<div class="card">
		<div class="card-head"><h2>다른 프로그램 바로가기</h2><span class="sub">상단 '바로가기' 메뉴</span></div>
		<div class="form">
			<label class="fs-s" style="display:flex;flex-direction:column;gap:.25rem">교적 관리 폴더 이름 (비우면 숨김)<input bind:value={links.cm} placeholder="cm" maxlength="40" /></label>
			<label class="fs-s" style="display:flex;flex-direction:column;gap:.25rem">부서 관리 폴더 이름 (쓰지 않으면 비움)<input bind:value={links.dm} placeholder="dm" maxlength="40" /></label>
		</div>
		<p class="fs-s muted">재정 폴더와 같은 곳(예: 같은 도메인의 <code>/cm/</code>, <code>/dm/</code>)에 설치된 프로그램의 폴더 이름을 적으면 상단에서 한 번에 이동할 수 있어요. 교적에서 재정으로 오는 버튼은 교적 쪽 작업입니다(docs/ch-manage-changes.md).</p>
		<div class="row mt" style="justify-content:flex-end"><button type="button" disabled={busy} onclick={saveLinks}>저장</button></div>
	</div>

	<div class="card">
		<div class="card-head"><h2>직인</h2><span class="sub">영수증 '(인)' 자리에 인쇄됩니다</span></div>
		{#if st.hasSeal}{#key sealTick}<img src={hrefTo('/api/v1/settings/seal') + '?t=' + sealTick} alt="직인" style="max-height:90px;border:1px solid var(--line);border-radius:8px;padding:.3rem;background:#fff" />{/key}{:else}<p class="fs-s muted">직인이 올라와 있지 않아요.</p>{/if}
		<div class="row mt" style="flex-wrap:wrap">
			<label class="ghost sm" style="cursor:pointer;display:inline-block;padding:.45rem .8rem;border:1px solid var(--line-strong);border-radius:10px">직인 이미지 올리기<input type="file" accept="image/png,image/jpeg" onchange={sealFile} style="display:none" /></label>
			{#if st.hasSeal}<button class="ghost sm" type="button" disabled={busy} onclick={delSeal}>지우기</button>{/if}
		</div>
		<p class="fs-s muted">큰 이미지는 자동으로 줄여서 올라갑니다. 직인 파일은 서버의 data 폴더에 두며 웹에서 직접 열리지 않아요.</p>
	</div>

	<div class="card">
		<div class="card-head"><h2>기부금 영수증 서식</h2></div>
		<div class="form">
			<select bind:value={form.lineMode} aria-label="내용 줄 방식"><option value="year">연간 합계 한 줄</option><option value="month">월별 합계 여러 줄</option></select>
			<input bind:value={form.code} placeholder="기부금 구분 코드 (종교단체 41)" maxlength="4" inputmode="numeric" />
		</div>
		<p class="fs-s muted">서식과 코드는 해마다 세법이 바뀔 수 있으니 세무 담당자와 확인하세요. 이미 발급한 영수증은 바뀌지 않습니다.</p>
		<div class="row mt" style="justify-content:flex-end"><button type="button" disabled={busy} onclick={saveForm}>저장</button></div>
	</div>

	<div class="card">
		<div class="card-head"><h2>예산을 넘는 결의서</h2></div>
		<div class="row" style="flex-wrap:wrap">
			<button type="button" class="sc {st.overBudget === 'warn' ? '' : 'c'}" disabled={busy} onclick={() => setOver('warn')}>{st.overBudget === 'warn' ? '✓ ' : ''}경고하고 담임목사 단계 추가</button>
			<button type="button" class="sc {st.overBudget === 'block' ? '' : 'c'}" disabled={busy} onclick={() => setOver('block')}>{st.overBudget === 'block' ? '✓ ' : ''}상신 차단 (예산 변경 먼저)</button>
		</div>
	</div>

	<div class="card">
		<div class="card-head"><h2>주민등록번호</h2><span class="sub">지금: {st.rrnMode === 'keep' ? '암호화 보관' : '보관 안 함'}</span></div>
		{#if st.rrnMode === 'keep'}
			<p class="fs-s muted">번호는 AES-256으로 암호화되어 영수증을 인쇄할 때만 풀립니다. 마지막 헌금 뒤 일정 기간이 지난 분의 번호는 아래에서 지울 수 있어요.</p>
			<div class="row" style="flex-wrap:wrap"><input type="number" bind:value={purgeYears} min="1" max="30" style="width:5rem" aria-label="기간(년)" />년 지난 헌금자
				<button class="ghost sm" type="button" disabled={busy} onclick={() => purge(false)}>대상 확인</button>
				{#if purgeCount !== null}<span class="fs-s">대상 {purgeCount}명</span>{#if purgeCount > 0}<button class="sm" type="button" disabled={busy} onclick={() => purge(true)}>번호 지우기</button>{/if}{/if}</div>
			<div class="row mt"><button class="ghost sm" type="button" disabled={busy} onclick={() => setRrn('none')}>보관하지 않는 방식으로 바꾸기 (저장된 번호 모두 삭제)</button></div>
		{:else}
			<p class="fs-s muted">번호를 저장하지 않고, 영수증에 앞 6자리만 찍어 기부자가 직접 적게 합니다.</p>
			<button class="ghost sm" type="button" disabled={busy} onclick={() => setRrn('keep')}>암호화 보관 방식으로 바꾸기</button>
		{/if}
		{#if newKey}
			<div class="warn fs-s"><b>암호화 열쇠가 새로 만들어졌어요. 지금 한 번만 보여 드립니다.</b> 서버의 설정 파일(<code>data/config.php</code>)에 들어 있으니, 서버를 잃어버려도 번호를 풀 수 있게 이 값을 안전한 곳(금고·비밀번호 관리자)에도 적어 두세요. 백업 파일에는 들어가지 않습니다.<br /><code style="word-break:break-all">{newKey}</code></div>
		{/if}
	</div>
{/if}

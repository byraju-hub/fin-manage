<script lang="ts">
	// 백업·되살리기 (재정 관리자). 재정 자료(fm_ 표)만. 증빙 사진·교적·암호화 열쇠는 백업에 들어가지 않는다.
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { hrefTo } from '#lib/paths.ts';
	import { errText } from '#lib/fmt.ts';

	let info = $state<{ tables: Record<string, number>; voucherFiles: number } | null>(null);
	let err = $state('');
	let notice = $state('');
	let busy = $state(false);
	let word = $state('');
	let file = $state<File | null>(null);
	let sample = $state<{ loaded: boolean; busy: string | null; info: { counts: Record<string, number>; at: string } | null; cmPeople: number } | null>(null);
	const loadSample = async () => (sample = await auth.api('GET', '/admin/sample'));
	async function sampleAct(kind: 'load' | 'clear') {
		const msg = kind === 'load' ? '교적의 교인으로 연습용 재정 자료(예산·헌금·장부·결의서·영수증 대상)를 올립니다. 진행할까요?' : '샘플로 올린 재정 자료를 모두 지웁니다. 샘플 이후에 직접 입력한 자료도 함께 지워지니 실제 자료를 넣기 전에만 쓰세요. 진행할까요?';
		if (!confirm(msg)) return;
		busy = true;
		err = '';
		notice = '';
		try {
			await auth.guard(async () => {
				try {
					await auth.api('POST', '/admin/sample/' + kind, {});
				} catch (e) {
					// 샘플 뒤에 직접 넣은 자료가 있으면, 한 번 더 분명히 물은 뒤에만 지운다
					if (e instanceof Error && (e as { code?: string }).code === 'changed' && confirm(e.message + '

그래도 모두 지울까요?')) await auth.api('POST', '/admin/sample/' + kind, { force: true });
					else throw e;
				}
			});
			notice = kind === 'load' ? '샘플 자료를 올렸어요. 헌금·장부·예산·결의서·영수증(작년) 화면에서 확인해 보세요.' : '샘플 자료를 지웠어요.';
			await loadSample();
		} catch (e) {
			if (!(e instanceof Error && e.message.includes('취소'))) err = errText(e);
		} finally {
			busy = false;
		}
	}

	onMount(async () => {
		try {
			info = await auth.api('GET', '/backup/info');
			await loadSample();
		} catch (e) {
			err = errText(e);
		}
	});

	async function download() {
		busy = true;
		err = '';
		try {
			const res = await auth.guard(async () => {
				const r = await fetch(hrefTo('/api/v1/backup/download'), { credentials: 'same-origin' });
				if (!r.ok) {
					const j = await r.json().catch(() => null);
					const { ApiError } = await import('#lib/auth.svelte.ts');
					throw new ApiError(j?.error?.code ?? 'error', j?.error?.message ?? '내려받지 못했습니다.', r.status);
				}
				return r.blob();
			});
			const a = document.createElement('a');
			a.href = URL.createObjectURL(res);
			a.download = 'fin-manage-backup-' + new Date().toISOString().slice(0, 10) + '.json';
			a.click();
			URL.revokeObjectURL(a.href);
			notice = '백업 파일을 내려받았어요. 안전한 곳에 보관하세요. 이 파일에는 헌금자 이름과 금액이 들어 있습니다.';
		} catch (e) {
			if (!(e instanceof Error && e.message.includes('취소'))) err = errText(e);
		} finally {
			busy = false;
		}
	}

	async function restore() {
		if (!file) return;
		busy = true;
		err = '';
		notice = '';
		try {
			const text = await file.text();
			const id = Array.from(crypto.getRandomValues(new Uint8Array(8)), (b) => b.toString(16).padStart(2, '0')).join('');
			const size = 28000; // 한글은 글자당 3바이트라 요청 크기 제한(128KB) 안에 들도록
			const n = Math.ceil(text.length / size);
			await auth.guard(async () => {
				for (let i = 0; i < n; i++) await auth.api('POST', '/backup/restore/chunk', { id, i, data: text.slice(i * size, (i + 1) * size) });
				await auth.api('POST', '/backup/restore/apply', { id, n, confirm: word });
			});
			notice = '되살렸어요. 화면을 새로 고쳐 주세요. (되살리기 전 상태는 서버의 data/backups 폴더에 파일로 남았습니다.)';
			file = null;
			word = '';
		} catch (e) {
			if (!(e instanceof Error && e.message.includes('취소'))) err = errText(e);
		} finally {
			busy = false;
		}
	}
</script>

<div class="row page-head"><h1>백업 · 되살리기</h1></div>
{#if err}<div class="warn">{err}</div>{/if}
{#if notice}<div class="note">{notice}</div>{/if}

<div class="card">
	<div class="card-head"><h2>백업 내려받기</h2><span class="sub">재정 자료 전체 (JSON)</span></div>
	{#if info}<p class="fs-s muted">{Object.entries(info.tables).filter(([, n]) => n).map(([k, n]) => `${k} ${n}`).join(' · ')}</p>
		<p class="fs-s">증빙 사진·PDF {info.voucherFiles}개는 백업 파일에 들어가지 않습니다. 서버의 data/vouchers 폴더를 FTP로 따로 받아 두세요. 교적 자료와 주민번호 암호화 열쇠(설정 파일)도 포함되지 않습니다.</p>{/if}
	<button type="button" disabled={busy} onclick={download}>백업 내려받기</button>
</div>

<div class="card">
	<div class="card-head"><h2>되살리기</h2><span class="sub">지금 재정 자료를 모두 백업 내용으로 바꿉니다</span></div>
	<div class="warn fs-s">되돌릴 수 없는 작업입니다. 같은 버전에서 만든 백업만 되살릴 수 있고, 지금 상태는 서버 data/backups 에 파일로 먼저 남습니다. 재정 사용 승인·역할, 설정, 접속·변경 기록은 지금 것을 그대로 둡니다. (그래서 되살린 뒤에도 관리자가 잠기지 않아요.)</div>
	<div class="form">
		<input type="file" accept="application/json,.json" onchange={(e) => (file = (e.target as HTMLInputElement).files?.[0] ?? null)} />
		<input bind:value={word} placeholder="확인 문구: 되살리기" />
		<button type="button" disabled={busy || !file || word !== '되살리기'} onclick={restore}>되살리기</button>
	</div>
</div>

<div class="card">
	<div class="card-head"><h2>샘플 자료</h2><span class="sub">연습용 · 교적의 교인·조직표를 바탕으로</span></div>
	{#if sample?.loaded}
		<p class="fs-s">샘플이 올라와 있어요 ({sample.info?.at}). {sample.info ? Object.entries(sample.info.counts).map(([k, n]) => `${k} ${n}`).join(' · ') : ''}</p>
		<div class="warn fs-s">샘플을 지우면 재정의 헌금·장부·예산·결의서·헌금자 자료가 모두 지워집니다. 실제 자료를 입력하기 전에 지우세요.</div>
		<button type="button" class="ghost" disabled={busy} onclick={() => sampleAct('clear')}>샘플 지우기</button>
	{:else if sample?.busy}
		<p class="fs-s muted">이미 {sample.busy} 자료가 있어 샘플을 올릴 수 없어요. 샘플은 비어 있는 재정에만 올립니다.</p>
	{:else}
		<p class="fs-s muted">교적의 교인 {sample?.cmPeople ?? 0}명 중에서 헌금자·부서 담당자를 뽑아 올해 예산(확정), 최근 8주 헌금, 작년 12개월 헌금(영수증 연습용), 장부, 지출결의서 3건(지급 완료·결재 중·작성 중)을 만듭니다.</p>
		<button type="button" disabled={busy} onclick={() => sampleAct('load')}>샘플 자료 올리기</button>
	{/if}
</div>

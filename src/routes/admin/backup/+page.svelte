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

	onMount(async () => {
		try {
			info = await auth.api('GET', '/backup/info');
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
			const size = 60000;
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
	<div class="warn fs-s">되돌릴 수 없는 작업입니다. 같은 버전에서 만든 백업만 되살릴 수 있고, 지금 상태는 서버 data/backups 에 파일로 먼저 남습니다.</div>
	<div class="form">
		<input type="file" accept="application/json,.json" onchange={(e) => (file = (e.target as HTMLInputElement).files?.[0] ?? null)} />
		<input bind:value={word} placeholder="확인 문구: 되살리기" />
		<button type="button" disabled={busy || !file || word !== '되살리기'} onclick={restore}>되살리기</button>
	</div>
</div>

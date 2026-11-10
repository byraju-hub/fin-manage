<script lang="ts">
	// 첫 화면 (M0): 교적 연결 상태와 교인 수. 헌금·결재·예산 카드는 이후 단계에서 채운다.
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { hrefTo } from '#lib/paths.ts';

	type Dash = {
		cm: { ok: boolean; reason: string; message: string; schema: number; people: number; churchName: string };
		fm: { version: string; schema: number };
		rrnMode: string;
		isAdmin: boolean;
		waiting: number;
		toPay: number;
		openBatches: number;
	};
	let d = $state<Dash | null>(null);
	let err = $state('');

	onMount(async () => {
		try {
			d = await auth.api<Dash>('GET', '/dashboard');
		} catch (e) {
			err = e instanceof Error ? e.message : '불러오지 못했습니다.';
		}
	});
</script>

<div class="hero">
	<div>
		<small>재정 관리 · 준비 중</small>
		<h1>{auth.church || '교회'} 재정 현황</h1>
		<p class="motto">설치와 교적 연결이 끝났어요. 헌금·예산·결재 화면은 단계마다 이어서 채워집니다.</p>
	</div>
</div>

{#if err}
	<div class="warn">{err}</div>
{:else if d}
	{#if !d.cm.ok}
		<div class="warn"><b>교적 관리와 연결되지 않았어요.</b> {d.cm.message}</div>
	{/if}
	<div class="kpis">
		<div class="kpi">
			<div class="ico"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="4" /><path d="M2 21v-1a7 7 0 0114 0v1" /><path d="M17 4a4 4 0 010 8M22 21v-1a6 6 0 00-4-5.6" /></svg></div>
			<div><b>{d.cm.people.toLocaleString('ko-KR')}<span class="of">명</span></b><span>교적에 있는 교인</span></div>
		</div>
		<div class="kpi">
			<div class="ico green"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6L9 17l-5-5" /></svg></div>
			<div><b>{d.cm.ok ? '정상' : '확인 필요'}</b><span>교적 연결 (구조 번호 {d.cm.schema})</span></div>
		</div>
		<div class="kpi">
			<div class="ico violet"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V8a4 4 0 018 0v3" /></svg></div>
			<div><b>{d.rrnMode === 'keep' ? '암호화 보관' : '보관 안 함'}</b><span>주민등록번호 방식</span></div>
		</div>
		<div class="kpi">
			<div class="ico amber"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z" /><path d="M14 3v5h5" /></svg></div>
			<div><b>v{d.fm.version}</b><span>재정 구조 번호 {d.fm.schema}</span></div>
		</div>
	</div>
{:else}
	<div class="card"><p class="muted">불러오는 중…</p></div>
{/if}

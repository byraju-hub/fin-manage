<script lang="ts">
	// 교인 찾기: 이름 일부 · 초성(ㄱㅅㅇ) · 전화 끝자리. 동명이인을 구분할 수 있게 출생연도·세대주·전화 끝 4자리를 보여 준다.
	import { auth } from '#lib/auth.svelte.ts';

	export type Person = { id: string; name: string; birthYear: number | null; gender: string | null; phoneTail: string; head: string | null };
	let { placeholder = '교인 이름 · 초성 · 전화 끝자리', onpick }: { placeholder?: string; onpick: (p: Person) => void } = $props();

	let q = $state('');
	let items = $state<Person[]>([]);
	let open = $state(false);
	let err = $state('');
	let seq = 0;
	let timer: ReturnType<typeof setTimeout> | undefined;

	function input() {
		clearTimeout(timer);
		err = '';
		if (!q.trim()) {
			items = [];
			open = false;
			return;
		}
		timer = setTimeout(async () => {
			const my = ++seq;
			try {
				const r = await auth.api<{ items: Person[] }>('GET', '/people/search?q=' + encodeURIComponent(q.trim()));
				if (my !== seq) return; // 더 늦게 보낸 검색이 있으면 이 결과는 버림
				items = r.items;
				open = true;
			} catch (e) {
				err = e instanceof Error ? e.message : '찾지 못했습니다.';
			}
		}, 250);
	}

	function pick(p: Person) {
		onpick(p);
		q = '';
		items = [];
		open = false;
	}
	const sub = (p: Person) =>
		[p.birthYear ? p.birthYear + '년생' : null, p.gender === 'M' ? '남' : p.gender === 'F' ? '여' : null, p.head ? '세대주 ' + p.head : null, p.phoneTail ? '전화 ···' + p.phoneTail : null].filter(Boolean).join(' · ');
</script>

<div class="picker">
	<input bind:value={q} oninput={input} onfocus={() => (open = items.length > 0)} {placeholder} autocomplete="off" aria-label="교인 찾기" />
	{#if err}<p class="fs-s" style="color:#a83c10;margin:.3rem 0 0">{err}</p>{/if}
	{#if open}
		<div class="pick-pop" role="listbox">
			<div class="hint2">교적 교인 목록에서 찾습니다</div>
			{#each items as p (p.id)}
				<button type="button" class="gs-row pick-btn" onclick={() => pick(p)}>
					<span class="avatar">{p.name.slice(0, 1)}</span>
					<span class="grow"><b>{p.name}</b><small>{sub(p)}</small></span>
				</button>
			{:else}
				<div class="hint2">찾는 교인이 없어요. 교적에 등록되어 있는지 확인해 주세요.</div>
			{/each}
		</div>
	{/if}
</div>

<style>
	.pick-btn {
		display: flex;
		align-items: center;
		gap: 0.6rem;
		width: 100%;
		text-align: left;
		background: transparent;
		color: inherit;
		border: 0;
		box-shadow: none;
		border-radius: 10px;
		font-weight: 400;
	}
	.pick-btn:hover {
		background: var(--primary-50);
	}
	.pick-btn .grow {
		flex: 1;
		min-width: 0;
	}
	.pick-btn small {
		display: block;
		color: var(--muted);
		font-size: 0.78rem;
	}
</style>

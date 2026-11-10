<script lang="ts">
	// 헌금자 찾기: 교인(이름·초성·전화 끝자리·봉투 번호) 또는 교인이 아닌 헌금자. 고르면 key('p:교인id' / 'd:헌금자id')와 이름을 돌려준다.
	import { auth } from '#lib/auth.svelte.ts';
	export type Donor = { key: string; name: string; label: string };
	type Hit = { key: string; name: string; birthYear: number | null; gender: string | null; phoneTail: string; head: string | null; donorNo: string | null; nonMember: boolean };
	let { onpick, placeholder = '이름 · 초성 · 번호 (Enter로 첫 결과)' }: { onpick: (d: Donor) => void; placeholder?: string } = $props();
	let q = $state('');
	let items = $state<Hit[]>([]);
	let open = $state(false);
	let el: HTMLInputElement;
	let seq = 0;
	let timer: ReturnType<typeof setTimeout> | undefined;

	export function focus() {
		el?.focus();
	}
	function input() {
		clearTimeout(timer);
		if (!q.trim()) {
			items = [];
			open = false;
			return;
		}
		timer = setTimeout(async () => {
			const my = ++seq;
			try {
				const r = await auth.api<{ items: Hit[] }>('GET', '/donors/search?q=' + encodeURIComponent(q.trim()));
				if (my !== seq) return;
				items = r.items;
				open = true;
			} catch {
				items = [];
			}
		}, 200);
	}
	const sub = (h: Hit) => (h.nonMember ? '교인 아님' + (h.head ? ' · ' + h.head : '') : [h.birthYear ? h.birthYear + '년생' : null, h.head ? '세대주 ' + h.head : null, h.phoneTail ? '···' + h.phoneTail : null, h.donorNo ? '번호 ' + h.donorNo : null].filter(Boolean).join(' · '));
	function pick(h: Hit) {
		onpick({ key: h.key, name: h.name, label: h.name + (h.birthYear ? ' (' + h.birthYear + ')' : '') });
		q = '';
		items = [];
		open = false;
	}
	function key(e: KeyboardEvent) {
		if (e.key === 'Enter' && open && items.length) {
			e.preventDefault();
			pick(items[0]);
		}
	}
	async function addOutsider() {
		const name = q.trim();
		if (!name) return;
		const r = await auth.api<{ key: string }>('POST', '/donors', { name });
		onpick({ key: r.key, name, label: name + ' (교인 아님)' });
		q = '';
		items = [];
		open = false;
	}
</script>

<div class="picker">
	<input bind:this={el} bind:value={q} oninput={input} onkeydown={key} {placeholder} autocomplete="off" aria-label="헌금자 찾기" />
	{#if open}
		<div class="pick-pop" role="listbox">
			{#each items as h (h.key)}
				<button type="button" class="gs-row pk" onclick={() => pick(h)}><span class="avatar">{h.name.slice(0, 1)}</span><span class="grow"><b>{h.name}</b><small>{sub(h)}</small></span></button>
			{/each}
			{#if q.trim() && !/^[ㄱ-ㅎ0-9]+$/.test(q.trim())}
				<button type="button" class="gs-row pk" onclick={addOutsider}><span class="avatar warm">+</span><span class="grow"><b>'{q.trim()}' 교인이 아닌 헌금자로 추가</b></span></button>
			{/if}
		</div>
	{/if}
</div>

<style>
	.pk { display: flex; align-items: center; gap: 0.6rem; width: 100%; text-align: left; background: transparent; color: inherit; border: 0; box-shadow: none; border-radius: 10px; font-weight: 400; }
	.pk:hover { background: var(--primary-50); }
	.pk .grow { flex: 1; min-width: 0; }
	.pk small { display: block; color: var(--muted); font-size: 0.78rem; }
</style>

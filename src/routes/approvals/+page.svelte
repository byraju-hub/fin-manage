<script lang="ts">
	// 내 결재함: 내가 결재할 차례인 것(대결 포함), 사후 확인할 것, (재정 담당) 지급 대기
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { hrefTo } from '#lib/paths.ts';
	import { won, errText } from '#lib/fmt.ts';

	type Card = { id: number; docNo: string | null; title: string; total: number; drafter: string; unit: string; at: string; role?: string; delegate?: boolean };
	type Pay = { id: number; docNo: string; title: string; total: number; payMethod: string; bank: string | null; account: string | null; holder: string | null; desiredDate: string | null; unit: string };
	let waiting = $state<Card[]>([]);
	let confirm = $state<Card[]>([]);
	let toPay = $state<Pay[]>([]);
	let err = $state('');
	const isStaff = $derived(!!auth.me?.access?.roles.includes('재정 담당'));

	onMount(async () => {
		try {
			const r = await auth.api<{ waiting: Card[]; confirm: Card[] }>('GET', '/approvals/inbox');
			waiting = r.waiting;
			confirm = r.confirm;
			if (isStaff) toPay = (await auth.api<{ items: Pay[] }>('GET', '/vouchers-to-pay')).items;
		} catch (e) {
			err = errText(e);
		}
	});
	const link = (id: number) => hrefTo('/vouchers') + '?v=' + id;
</script>

<div class="row page-head"><h1>내 결재함</h1></div>
{#if err}<div class="warn">{err}</div>{/if}

<div class="card">
	<div class="card-head"><h2>결재할 문서 <span class="tag part">{waiting.length}</span></h2></div>
	{#each waiting as c (c.id)}
		<a class="pcard" style="margin-bottom:.5rem;text-decoration:none;color:inherit" href={link(c.id)}>
			<div class="grow"><b>{c.title}</b><small>{c.docNo} · {c.unit} · 기안 {c.drafter} · {c.at}</small></div>
			<span class="sc">{c.role}{c.delegate ? ' 대결' : ''}</span><b class="money">{won(c.total)}</b>
		</a>
	{:else}
		<p class="muted">결재할 문서가 없어요.</p>
	{/each}
</div>

{#if confirm.length}
	<div class="card">
		<div class="card-head"><h2>사후 확인 <span class="tag part">{confirm.length}</span></h2><span class="sub">지급을 막지 않는 확인 단계</span></div>
		{#each confirm as c (c.id)}
			<a class="pcard" style="margin-bottom:.5rem;text-decoration:none;color:inherit" href={link(c.id)}><div class="grow"><b>{c.title}</b><small>{c.docNo} · {c.unit} · {c.drafter}</small></div><b class="money">{won(c.total)}</b></a>
		{/each}
	</div>
{/if}

{#if isStaff}
	<div class="card">
		<div class="card-head"><h2>지급 대기 <span class="tag part">{toPay.length}</span></h2><span class="sub">결재가 끝난 결의서</span></div>
		{#each toPay as p (p.id)}
			<a class="pcard" style="margin-bottom:.5rem;text-decoration:none;color:inherit" href={link(p.id)}>
				<div class="grow"><b>{p.title}</b><small>{p.docNo} · {p.unit} · {p.payMethod} {[p.bank, p.account, p.holder].filter(Boolean).join(' ')}</small></div><b class="money">{won(p.total)}</b>
			</a>
		{:else}
			<p class="muted">지급할 문서가 없어요.</p>
		{/each}
	</div>
{/if}

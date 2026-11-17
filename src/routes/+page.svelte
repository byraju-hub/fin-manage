<script lang="ts">
	// 첫 화면: 할 일, 올해 재정 현황, 최근 8주 헌금, 예산 집행, 내 부서, 최근 결의서
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { hrefTo } from '#lib/paths.ts';
	import { won } from '#lib/fmt.ts';

	type Fin = {
		year: number | null;
		totals: { income: number; expense: number; incomeMonth: number; expenseMonth: number; balance: number } | null;
		budget?: { budget: number; paid: number; pending: number; rate: number; scope: string };
		weekly: { label: string; sum: number }[];
		units: { name: string; budget: number; paid: number; rate: number }[];
		recent: { id: number; title: string; total: number; status: string; unit: string }[];
		mine: { id: number; name: string; role: string; budget: number; paid: number; remain: number }[];
		alerts: { text: string; to: string }[];
	};
	type Dash = {
		cm: { ok: boolean; reason: string; message: string; schema: number; people: number; churchName: string };
		fm: { version: string; schema: number };
		rrnMode: string;
		isAdmin: boolean;
		waiting: number;
		toPay: number;
		openBatches: number;
		pendingAccess: number;
		cmSso: boolean;
		fin: Fin;
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
	const max = $derived(Math.max(1, ...(d?.fin.weekly.map((w) => w.sum) ?? [1])));
	const todo = $derived(
		d
			? [
					d.waiting && { t: `결재할 문서 ${d.waiting}건`, to: '/approvals' },
					d.toPay && { t: `지급 대기 결의서 ${d.toPay}건`, to: '/approvals' },
					d.openBatches && { t: `마감하지 않은 헌금 묶음 ${d.openBatches}개`, to: '/offerings' },
					d.isAdmin && !d.cmSso && { t: `교적(ch-manage)을 구조 번호 7 이상으로 올리면 교적에 '재정 열기' 버튼이 생겨요. (지금 ${d.cm.schema})`, to: '/' },
					d.pendingAccess && { t: `재정 사용 승인 대기 ${d.pendingAccess}명`, to: '/admin/users' },
					...d.fin.alerts.map((a) => ({ t: a.text, to: a.to }))
				].filter((x): x is { t: string; to: string } => !!x)
			: []
	);
	const stTag = (s: string) => (s === '지급 완료' || s === '결재 완료' ? 'now' : s === '반려' || s === '취소' ? '' : 'part');
</script>

<div class="hero">
	<div>
		<small>{d?.fin.year ? d.fin.year + '년 회계연도' : '재정 관리'}</small>
		<h1>{auth.church || '교회'} 재정 현황</h1>
		<p class="motto">{auth.me?.user.name} 님, 오늘 할 일과 올해 재정을 한눈에 봅니다.</p>
	</div>
</div>

{#if err}
	<div class="warn">{err}</div>
{:else if d}
	{#if !d.cm.ok}<div class="warn"><b>교적 관리와 연결되지 않았어요.</b> {d.cm.message}</div>{/if}
	{#if !d.fin.year}
		<div class="card"><p>아직 회계연도가 없어요. <a href={hrefTo('/base')}>기준 자료</a>에서 회계연도를 만들고, 샘플로 연습하려면 <a href={hrefTo('/admin/backup')}>백업 · 샘플 자료</a>를 이용하세요.</p></div>
	{/if}

	{#if todo.length}
		<div class="card">
			<div class="card-head"><h2>할 일</h2><span class="sub">{todo.length}건</span></div>
			{#each todo as t}<p style="margin:.35rem 0"><a href={hrefTo(t.to)}>{t.t}</a></p>{/each}
		</div>
	{/if}

	{#if d.fin.totals}
		<div class="kpis">
			<div class="kpi"><div class="ico green"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7" /></svg></div><div><b>{won(d.fin.totals.income)}<span class="of">원</span></b><span>올해 수입 · 이번 달 {won(d.fin.totals.incomeMonth)}</span></div></div>
			<div class="kpi"><div class="ico amber"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12l7 7 7-7" /></svg></div><div><b>{won(d.fin.totals.expense)}<span class="of">원</span></b><span>올해 지출 · 이번 달 {won(d.fin.totals.expenseMonth)}</span></div></div>
			<div class="kpi"><div class="ico"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="13" rx="2" /><path d="M3 10h18" /></svg></div><div><b>{won(d.fin.totals.balance)}<span class="of">원</span></b><span>현재 계좌 잔액 합계</span></div></div>
			<div class="kpi"><div class="ico violet"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 20V10M12 20V4M19 20v-7" /></svg></div><div><b>{d.fin.budget?.rate ?? 0}<span class="of">%</span></b><span>예산 집행률 ({d.fin.budget?.scope})</span></div></div>
		</div>
	{:else if d.fin.budget}
		<div class="kpis">
			<div class="kpi"><div class="ico violet"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 20V10M12 20V4M19 20v-7" /></svg></div><div><b>{d.fin.budget.rate}<span class="of">%</span></b><span>예산 집행률 ({d.fin.budget.scope})</span></div></div>
			<div class="kpi"><div class="ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v20M17 6H9a3 3 0 000 6h6a3 3 0 010 6H6" /></svg></div><div><b>{won(d.fin.budget.budget - d.fin.budget.paid - d.fin.budget.pending)}<span class="of">원</span></b><span>남은 예산</span></div></div>
		</div>
	{/if}

	<div class="two">
		{#if d.fin.totals}
			<div class="card">
				<div class="card-head"><h2>최근 8주 헌금</h2><a href={hrefTo('/reports')} class="sub">주간 보고 ›</a></div>
				<div class="bars" role="img" aria-label="최근 8주 헌금 합계">
					{#each d.fin.weekly as w}
						<div class="bar-col"><div class="bar-fill" style="height:{Math.max(2, Math.round((w.sum / max) * 100))}%" title="{w.label} {won(w.sum)}원"></div><small>{w.label}</small></div>
					{/each}
				</div>
				<p class="fs-s muted">마감된 헌금 합계 · 가장 많았던 주 {won(max === 1 ? 0 : max)}원</p>
			</div>
		{/if}
		{#if d.fin.units.length}
			<div class="card">
				<div class="card-head"><h2>예산 집행률 높은 곳</h2><a href={hrefTo('/budget')} class="sub">예산 ›</a></div>
				{#each d.fin.units as u}
					<div class="meter-row"><span>{u.name}</span><div class="meter"><div style="width:{Math.min(100, u.rate)}%" class:over={u.rate >= 100}></div></div><b>{u.rate}%</b></div>
				{/each}
			</div>
		{/if}
	</div>

	{#if d.fin.mine.length}
		<div class="card">
			<div class="card-head"><h2>내가 맡은 부서</h2><a href={hrefTo('/vouchers')} class="sub">결의서 쓰기 ›</a></div>
			<div class="scroll-x"><table class="sm"><thead><tr><th>부서</th><th>역할</th><th class="right">예산</th><th class="right">집행</th><th class="right">남음</th></tr></thead>
				<tbody>{#each d.fin.mine as m}<tr><td>{m.name}</td><td><span class="sc">{m.role}</span></td><td class="right">{won(m.budget)}</td><td class="right money">{won(m.paid)}</td><td class="right {m.remain < 0 ? 'neg' : ''}">{won(m.remain)}</td></tr>{/each}</tbody></table></div>
		</div>
	{/if}

	{#if d.fin.recent.length}
		<div class="card">
			<div class="card-head"><h2>최근 지출결의서</h2><a href={hrefTo('/vouchers')} class="sub">전체 ›</a></div>
			{#each d.fin.recent as r}
				<a class="pcard" style="margin-bottom:.4rem;text-decoration:none;color:inherit" href={hrefTo('/vouchers') + '?v=' + r.id}><div class="grow"><b>{r.title}</b><small>{r.unit}</small></div><span class="tag {stTag(r.status)}">{r.status}</span><b class="money">{won(r.total)}</b></a>
			{/each}
		</div>
	{/if}

	<p class="fs-s muted">교적 연결: {d.cm.ok ? '정상' : '확인 필요'} (교인 {d.cm.people.toLocaleString('ko-KR')}명) · 주민번호 {d.rrnMode === 'keep' ? '암호화 보관' : '보관 안 함'} · v{d.fm.version}</p>
{:else}
	<div class="card"><p class="muted">불러오는 중…</p></div>
{/if}

<a class="manual-banner" href={hrefTo('/manual')}>
	<span class="mb-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5.5A2.5 2.5 0 016.5 3H20v15H6.5A2.5 2.5 0 004 20.5v-15z" /><path d="M4 20.5A2.5 2.5 0 016.5 18H20v3H6.5A2.5 2.5 0 014 20.5z" /><path d="M9 7.5h7M9 11h5" /></svg></span>
	<span class="mb-tx"><b>프로그램 매뉴얼</b><small>헌금 입력부터 결의서·결재·마감·영수증·설정까지, 화면 순서대로 정리했어요</small></span>
	<span class="mb-go">매뉴얼 보기<i aria-hidden="true">→</i></span>
</a>

<style>
	/* 프로그램 매뉴얼 배너: 대시보드 맨 아래의 큰 버튼 */
	.manual-banner { position: relative; overflow: hidden; display: flex; align-items: center; gap: 1rem; margin-top: 1.1rem; padding: 1.1rem 1.4rem; border-radius: 18px; color: #fff; text-decoration: none;
		background: radial-gradient(120% 160% at 100% 0%, rgba(20, 180, 174, 0.55), transparent 55%), linear-gradient(120deg, var(--navy), #4558ae); box-shadow: var(--shadow-md); transition: transform 0.2s var(--ease), box-shadow 0.2s var(--ease); }
	.manual-banner:hover { transform: translateY(-3px); box-shadow: 0 10px 28px rgba(43, 62, 149, 0.35); text-decoration: none; color: #fff; }
	.manual-banner .mb-ic { flex: none; width: 52px; height: 52px; border-radius: 15px; display: grid; place-items: center; background: rgba(255, 255, 255, 0.16); border: 1px solid rgba(255, 255, 255, 0.32); }
	.manual-banner .mb-ic svg { width: 28px; height: 28px; fill: none; stroke: #fff; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
	.manual-banner .mb-tx { flex: 1; display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
	.manual-banner .mb-tx b { color: #fff; font-size: 1.15rem; }
	.manual-banner .mb-tx small { color: rgba(255, 255, 255, 0.85); font-size: 0.86rem; line-height: 1.45; }
	.manual-banner .mb-go { flex: none; display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.55rem 1.1rem; border-radius: 999px; background: #fff; color: var(--navy); font-weight: 800; font-size: 0.92rem; }
	.manual-banner:hover .mb-go i { transform: translateX(4px); }
	.manual-banner .mb-go i { font-style: normal; transition: transform 0.2s var(--ease); }
	@media (max-width: 640px) { .manual-banner { flex-wrap: wrap; padding: 1rem; } .manual-banner .mb-go { width: 100%; justify-content: center; } }
	.bars { display: flex; align-items: flex-end; gap: 0.5rem; height: 150px; padding-top: 0.5rem; }
	.bar-col { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; height: 100%; gap: 0.3rem; }
	.bar-fill { width: 100%; max-width: 38px; border-radius: 8px 8px 3px 3px; background: linear-gradient(180deg, #17a58c, #2b3e95); min-height: 2px; }
	.bar-col small { color: var(--muted); font-size: 0.7rem; }
	.meter-row { display: grid; grid-template-columns: 6.5rem 1fr 3rem; gap: 0.6rem; align-items: center; margin: 0.45rem 0; font-size: 0.9rem; }
	.meter { height: 10px; border-radius: 999px; background: var(--primary-100); overflow: hidden; }
	.meter div { height: 100%; background: var(--primary); border-radius: 999px; }
	.meter div.over { background: #d9622b; }
	.meter-row b { text-align: right; }
</style>

<script lang="ts">
	import { onMount } from 'svelte';
	import { auth } from '#lib/auth.svelte.ts';
	import { hrefTo } from '#lib/paths.ts';

	// 프로그램 매뉴얼: 화면 순서대로 쓰는 방법을 정리한 안내서. 대시보드 맨 아래 '프로그램 매뉴얼' 버튼에서 열린다.
	// 목차(왼쪽)·검색·지금 읽는 곳 표시가 있고 인쇄할 수 있다. 내용을 고칠 때는 GROUPS(목차)와 본문 <section> 의 id 를 맞춘다.
	const GROUPS: { name: string; items: [string, string][] }[] = [
		{ name: '시작하기', items: [['start', '처음 시작하기'], ['roles', '누가 무엇을 할 수 있나요'], ['home', '대시보드']] },
		{ name: '헌금', items: [['offer', '헌금 입력과 마감'], ['pledge', '작정헌금'], ['receipt', '기부금 영수증']] },
		{ name: '지출', items: [['voucher', '지출결의서'], ['approve', '결재함과 지급'], ['direct', '직접 지출'], ['event', '행사 정산']] },
		{ name: '장부 · 보고서', items: [['ledger', '장부'], ['budget', '예산'], ['closing', '마감과 결산'], ['report', '보고서']] },
		{ name: '관리', items: [['base', '기준 자료와 재정 단위'], ['cat', '지출 카테고리'], ['admin', '설정 (관리자)'], ['import', '엑셀 가져오기와 백업'], ['privacy', '개인정보와 내려받기'], ['link', '교적과 함께 쓰기 · 체험']] },
		{ name: '도움말', items: [['faq', '자주 묻는 질문']] }
	];

	let q = $state('');
	let active = $state('start');
	let texts: Record<string, string> = {};
	let hits = $state<Set<string> | null>(null);

	onMount(() => {
		const secs = [...document.querySelectorAll<HTMLElement>('section[data-sec]')];
		texts = Object.fromEntries(secs.map((s) => [s.id, (s.textContent ?? '').toLowerCase()]));
		const io = new IntersectionObserver(
			(es) => { for (const e of es) if (e.isIntersecting) active = e.target.id; },
			{ rootMargin: '-15% 0px -70% 0px' }
		);
		secs.forEach((s) => io.observe(s));
		return () => io.disconnect();
	});

	// 검색: 띄어 쓴 낱말이 모두 들어 있는 항목만 남긴다
	$effect(() => {
		const words = q.trim().toLowerCase().split(/\s+/).filter(Boolean);
		hits = words.length ? new Set(Object.keys(texts).filter((id) => words.every((w) => texts[id].includes(w)))) : null;
	});
	const show = (id: string) => hits === null || hits.has(id);
</script>

<svelte:head><title>{auth.church || '교회'} 재정 관리</title></svelte:head>

<div class="manual">
	<header class="mhero">
		<div class="mhero-ic" aria-hidden="true">
			<svg viewBox="0 0 24 24"><path d="M4 5.5A2.5 2.5 0 016.5 3H20v15H6.5A2.5 2.5 0 004 20.5v-15z" /><path d="M4 20.5A2.5 2.5 0 016.5 18H20v3H6.5A2.5 2.5 0 014 20.5z" /><path d="M9 7.5h7M9 11h5" /></svg>
		</div>
		<div class="mhero-tx">
			<h1>프로그램 매뉴얼</h1>
			<p>{auth.church || '교회'} 재정 관리를 쓰는 방법을 화면 순서대로 정리했어요. 처음이라면 위에서부터, 궁금한 게 있으면 아래 검색칸에 적어 보세요.</p>
		</div>
		<div class="mhero-act">
			<label class="msearch"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
				<input type="search" bind:value={q} placeholder="매뉴얼에서 찾기 (예: 영수증, 결재, 마감)" aria-label="매뉴얼 검색" /></label>
			<button type="button" class="ghost sm mprint" onclick={() => window.print()}>인쇄</button>
		</div>
	</header>

	<div class="layout">
		<nav class="toc" aria-label="목차">
			{#each GROUPS as g (g.name)}
				<b>{g.name}</b>
				{#each g.items as [id, title] (id)}
					<a href="#{id}" class:on={active === id} class:dim={!show(id)} onclick={() => (active = id)}>{title}</a>
				{/each}
			{/each}
		</nav>

		<div class="body">
			{#if hits && hits.size === 0}<p class="none">'{q}'에 맞는 내용이 없어요. 다른 낱말로 찾아 보세요.</p>{/if}

			<section id="start" data-sec hidden={!show('start')}>
				<h2><i>1</i>처음 시작하기</h2>
				<ol class="steps">
					<li>교적 관리와 <b>같은 아이디·비밀번호</b>로 로그인해요. 비밀번호는 교적 관리에서 바꿔요. 교적의 <b>재정 열기</b> 버튼으로 들어오면 비밀번호 없이 바로 열려요.</li>
					<li>재정 사용을 <b>승인받은 분만</b> 들어올 수 있어요. 승인이 없으면 '재정 사용 신청' 화면이 나오니 신청하고, 재정 관리자가 <b>역할</b>을 정해 주면 그때부터 써요.</li>
					<li>쓰지 않고 한참 지나면 개인정보 보호를 위해 자동으로 로그아웃돼요. 다시 로그인하면 돼요.</li>
					<li>어느 화면에서든 맨 위 <b>교회 이름(로고)</b>을 누르면 대시보드로 돌아와요. 메뉴는 위쪽에 <b>헌금 · 지출 · 장부 · 보고서 · 설정</b>으로 묶여 있고, 역할에 따라 보이는 것만 나와요.</li>
					<li>개인별 헌금 열람, 결재, 영수증 발급, 마감은 <b>모두 기록에 남아요.</b> 누가 언제 했는지 나중에 확인할 수 있어요.</li>
				</ol>
				<div class="box tip"><b>연습해 보고 싶다면:</b> 설정 › <b>백업 · 샘플 자료</b>에서 샘플 자료를 넣어 한 바퀴 돌아볼 수 있어요. 실제 자료를 넣기 전에 샘플은 꼭 지우세요.</div>
				<h3>처음 설정 순서 (재정 관리자)</h3>
				<ol class="steps">
					<li><b>재정 사용 승인</b>: 신청한 분을 승인하고 역할을 정해요.</li>
					<li><b>기준 자료</b>: 회계연도를 만들고, 특별회계·계좌(끝 4자리만)·수입 항목을 고쳐요.</li>
					<li><b>재정 단위·담당자</b>: 위원회·부서를 만들거나 교적 조직표에서 가져오고, 부서마다 회계·부서장, 위원회마다 위원장을 지정해요.</li>
					<li><b>결재 규칙</b>: 금액 구간별 결재선을 정해요.</li>
					<li><b>예산</b>: 재정 담당이 입력하고 재정위원장이 확정해요.</li>
					<li><b>교회 정보·영수증·보안</b>: 고유번호·소재지·대표자·직인을 채워요. (영수증을 발급하기 전에 꼭)</li>
				</ol>
			</section>

			<section id="roles" data-sec hidden={!show('roles')}>
				<h2><i>2</i>누가 무엇을 할 수 있나요</h2>
				<p>로그인한 사람의 <b>역할</b>에 따라 보이는 화면과 할 수 있는 일이 달라요. 역할은 재정 사용을 승인할 때 관리자가 정해요.</p>
				<div class="scroll"><table>
					<thead><tr><th>역할</th><th>하는 일</th></tr></thead>
					<tbody>
						<tr><td><b>재정 담당</b></td><td>헌금 입력·마감, 장부, 예산 입력, 지급 처리, 월 마감, 영수증 발급, 기준 자료·재정 단위 관리</td></tr>
						<tr><td><b>재정위원장</b></td><td>예산 확정, 월 마감 승인, 연 마감, 결재, 직접 지출 확인</td></tr>
						<tr><td><b>담임목사</b></td><td>금액에 따라 결재, 보고서 열람</td></tr>
						<tr><td><b>계수 위원</b></td><td>헌금 묶음 입력과 대조</td></tr>
						<tr><td><b>감사</b></td><td>장부·보고서 열람. 개인별 헌금은 지정된 분만 볼 수 있어요</td></tr>
						<tr><td><b>열람</b></td><td>보고서 보기</td></tr>
						<tr><td><b>부서 회계·부서장·위원장</b></td><td>자기 부서(위원회)의 지출결의서 작성과 결재 — 재정 단위에서 담당자로 지정된 분</td></tr>
					</tbody>
				</table></div>
				<div class="box">관리자(재정 관리자)는 모든 설정을 해요. 개인별 헌금(누가 얼마를 냈는지)은 <b>재정 담당·재정위원장·담임목사</b>가 기본으로 볼 수 있고, 감사는 지정한 분만 볼 수 있어요.</div>
			</section>

			<section id="home" data-sec hidden={!show('home')}>
				<h2><i>3</i>대시보드</h2>
				<ul>
					<li><b>할 일</b>: 내가 결재할 문서, 지급 대기, 마감하지 않은 헌금 묶음, 승인 대기 신청 등을 눌러 바로 이동해요.</li>
					<li><b>올해 재정 현황</b>: 올해 수입·지출, 이번 달 수입·지출, 잔액.</li>
					<li><b>최근 8주 헌금</b>, <b>예산 집행</b>, <b>내 부서</b>의 예산·집행·남은 돈, <b>최근 지출결의서</b>가 보여요.</li>
					<li>위쪽 <b>바로가기</b>에서 교적 관리(와 연결해 둔 부서 관리)로 이동할 수 있어요.</li>
				</ul>
			</section>

			<section id="offer" data-sec hidden={!show('offer')}>
				<h2><i>4</i>헌금 입력과 마감</h2>
				<p><span class="path">헌금 › 헌금 입력</span> · 재정 담당·계수 위원</p>
				<ol class="steps">
					<li><b>묶음 만들기</b>: 날짜와 구분(주일, 주일 1·2·3부, 수요예배, 새벽기도, 절기, 계좌이체분 등)으로 묶음을 만들어요.</li>
					<li><b>헌금 종류 고르기</b>: 십일조·감사 같은 종류를 고른 뒤 <b>이름(초성만 쳐도 돼요)과 금액</b>을 입력하고 Enter.</li>
					<li><b>금액 빨리 쓰기</b>: 숫자 뒤에 <code>R</code>(×100) <code>T</code>(×1,000) <code>Y</code>(×10,000)를 붙이면 빨리 쓸 수 있어요. 예: <code>5Y</code> = 50,000원. 금액 칸 옆에 계산된 금액이 보여요.</li>
					<li><b>대조</b>: 종류별로 입력 합계와 <b>실제로 센 금액</b>을 맞춰 봐요. 다르면 마감할 수 없어요.</li>
					<li><b>마감</b>하면 장부에 수입으로 올라가요. 마감한 뒤에는 고칠 수 없으니 확인하고 누르세요.</li>
					<li>통장 내역은 <code>이름 금액</code>을 붙여넣으면 <b>계좌이체 헌금</b>으로 맞출 수 있어요.</li>
				</ol>
				<div class="box tip">처음 보는 헌금자라면 그 자리에서 헌금자로 등록하고, 교인이면 교적 교인과 연결돼요. 같은 이름이 여러 명이면 연결할 사람을 골라요.</div>
			</section>

			<section id="pledge" data-sec hidden={!show('pledge')}>
				<h2><i>5</i>작정헌금</h2>
				<p><span class="path">헌금 › 작정헌금</span></p>
				<ul>
					<li>작정한 사람, 작정 종류, 금액, 기간을 등록하면 <b>이행률과 남은 금액</b>이 계산돼요.</li>
					<li>실제 헌금은 헌금 묶음에서 입력한 것이 자동으로 이행에 반영돼요.</li>
					<li>작정 내역은 개인별 헌금이므로 <b>열람 권한이 있는 분만</b> 볼 수 있어요.</li>
				</ul>
			</section>

			<section id="receipt" data-sec hidden={!show('receipt')}>
				<h2><i>6</i>기부금 영수증</h2>
				<p><span class="path">헌금 › 기부금 영수증</span> · 재정 담당 이상</p>
				<ul>
					<li><b>연말 발급</b>: 과세 연도(1.1~12.31)의 영수증 대상 항목이고 <b>마감된 헌금</b>만 집계해요. 금액이 달라진 분은 <b>정정 발급</b>되고 이전 영수증은 무효가 돼요.</li>
					<li><b>기간 조회</b>: 연도가 아니라 기간(예: 전·후반기)을 정해 헌금자별 금액을 확인해요.</li>
					<li><b>세대 확인</b>: 가족의 헌금을 세대주 한 사람 명의로 합칠지 맞춰 봐요. 합치기·세대주로 맞추기·그대로 두기를 고를 수 있어요.</li>
					<li><b>개인정보 동의 표시</b>: 헌금자마다 '동의 있음 / 동의 없음 / 철회됨 / 이전 판 / 14세 미만'이 보여요. 교인은 교적의 동의 기록을 읽고, 교인이 아닌 헌금자는 여기서 동의를 기록해요.</li>
					<li>주소가 없거나 주민번호 보관 방식에서 번호가 없는 분은 건너뛰고 이유를 알려줘요.</li>
					<li>발급명세는 엑셀(CSV)로 받아요. 내려받을 때 <b>사유를 선택</b>해야 하고 기록에 남아요.</li>
				</ul>
				<div class="box caution">영수증을 처음 발급하기 전에 <b>설정 › 교회 정보·영수증·보안</b>에서 고유번호·소재지·대표자·직인, 영수증 번호 방식(연간/월별), 주민번호 방식을 꼭 정하세요.</div>
			</section>

			<section id="voucher" data-sec hidden={!show('voucher')}>
				<h2><i>7</i>지출결의서</h2>
				<p><span class="path">지출 › 지출결의서</span> · 그 단위의 회계·부서장·위원장</p>
				<ol class="steps">
					<li><b>새 결의서</b>를 만들고 부서(단위), 제목, 지급 방법(계좌이체·현금·법인카드), 받는 곳을 적어요.</li>
					<li><b>지출 내역</b> 줄마다 예산 항목, 적요, 금액을 적어요. 줄은 + 로 늘릴 수 있어요. 행사와 관련된 줄은 <b>행사</b>를 골라요.</li>
					<li><b>증빙 사진</b>(영수증·견적서)을 올려요. 휴대폰이면 바로 찍어 올릴 수 있고, 큰 사진은 자동으로 줄여서 올라가요.</li>
					<li>'기타'처럼 <b>메모 필수</b>인 카테고리의 예산 항목을 쓰면 <b>사유를 5자 이상</b> 적어야 저장돼요.</li>
					<li>예산을 넘으면 경고가 나오고, 교회 설정에 따라 상신이 막히기도 해요.</li>
					<li><b>상신</b>하면 금액에 맞는 결재선이 사람 이름과 함께 고정돼요. 상신한 뒤에는 내용을 고칠 수 없고, 반려되면 고쳐서 다시 상신해요.</li>
				</ol>
				<h3>영수증 사진으로 자동 입력 (켜 둔 교회만)</h3>
				<p>관리자가 켜면 결의서 화면에 <b>영수증 사진으로 자동 입력</b>이 나와요. 사진을 고르면 날짜·가맹점·금액·결제 수단을 읽어 보여 주고, <b>지출 내역에 채우기</b>를 눌러야 반영돼요.</p>
				<div class="box caution">사진은 글자를 읽으려고 <b>외부 AI 업체(국외)</b>로 전송돼요. 구겨지거나 바랜 영수증은 금액이 틀릴 수 있으니 <b>채운 뒤 금액과 예산 항목을 꼭 확인</b>하세요. 사용자당 하루 60장까지예요.</div>
			</section>

			<section id="approve" data-sec hidden={!show('approve')}>
				<h2><i>8</i>결재함과 지급</h2>
				<p><span class="path">지출 › 결재함</span></p>
				<ul>
					<li><b>결재할 문서</b>: 내 차례인 결의서가 모여요. 내용과 증빙을 보고 <b>결재</b> 또는 <b>반려(사유 필수)</b>를 해요. 결재할 때 <b>비밀번호를 다시 확인</b>하고, 결재한 순간의 내용 확인값이 남아요.</li>
					<li><b>사후 확인</b>: 결재선에 <b>확인</b> 단계가 있는 문서예요. 지급을 막지 않고 나중에 확인만 해요.</li>
					<li><b>지급 대기</b> (재정 담당): 결재가 끝난 문서에 <b>출금 계좌와 지급일</b>을 적고 지급 처리하면 장부에 지출로 올라가요.</li>
					<li><b>부재 중</b>에는 관리자가 <b>대리 결재</b>를 지정해요. (설정 › 결재 규칙)</li>
					<li>상신한 사람은 결재가 끝나기 전에 <b>회수</b>해서 고쳐 다시 상신하거나, 결의서를 <b>취소</b>할 수 있어요. 반려된 문서는 고쳐서 다시 올려요.</li>
				</ul>
			</section>

			<section id="direct" data-sec hidden={!show('direct')}>
				<h2><i>9</i>직접 지출</h2>
				<p><span class="path">장부</span> 화면에서 · 허용된 항목만</p>
				<ul>
					<li>작은 금액은 결의서 없이 장부에서 바로 지출을 등록할 수 있어요. 관리자가 <b>예산 화면에서 항목마다 직접 지출 허용 여부와 건당 한도</b>를 정해 둔 곳만 돼요.</li>
					<li>설정에 따라 <b>영수증 사진을 붙이거나</b>, 증빙이 없는 <b>사유</b>를 적어야 해요. 메모 필수 카테고리는 적요를 5자 이상 적어야 해요.</li>
					<li>월 마감 때 재정위원장이 직접 지출을 <b>한꺼번에 확인</b>해요.</li>
				</ul>
			</section>

			<section id="event" data-sec hidden={!show('event')}>
				<h2><i>10</i>행사 정산</h2>
				<p><span class="path">지출 › 행사 정산</span> · 재정 담당 이상</p>
				<ol class="steps">
					<li><b>새 행사 만들기</b>: 수련회·기도회·바자회처럼 따로 정산할 일의 이름, 주관 부서, 기간, 예산을 적어요.</li>
					<li>지출결의서의 <b>내역 줄</b>이나 장부의 <b>수입(회비)·지출</b>에서 <b>행사</b>를 골라요.</li>
					<li>행사를 열면 <b>수입·지출·남은 금액·결재 중 금액</b>이 모여 보여요. <b>정산서 인쇄</b>도 돼요.</li>
					<li>끝나면 <b>행사 종료</b>로 정산을 마쳐요. 종료한 행사에는 새로 연결할 수 없고, 다시 열 수도 있어요.</li>
				</ol>
				<div class="box">연결된 기록이 있는 행사는 지울 수 없어요.</div>
			</section>

			<section id="ledger" data-sec hidden={!show('ledger')}>
				<h2><i>11</i>장부</h2>
				<p><span class="path">장부 › 장부</span></p>
				<ul>
					<li>수입·지출·이체가 날짜순으로 모여요. 헌금 마감과 지급 처리가 <b>자동으로</b> 올라오고, 그 밖의 수입(이자·회비 등)은 직접 등록해요.</li>
					<li><b>계좌 이체</b>(계좌 간 돈 옮기기)는 수입·지출에 잡히지 않아요.</li>
					<li>계좌별 잔액을 확인하고, 마감한 달의 기록은 고칠 수 없어요.</li>
					<li>직접 등록한 기록을 잘못 넣었다면 지우지 않고 <b>취소</b>해요(사유를 적으면 기록에 남아요). 헌금·지급으로 자동 올라온 기록은 원래 화면에서 바로잡아요.</li>
					<li>장부는 엑셀(CSV)로 내려받을 수 있어요.</li>
				</ul>
			</section>

			<section id="budget" data-sec hidden={!show('budget')}>
				<h2><i>12</i>예산</h2>
				<p><span class="path">장부 › 예산</span></p>
				<ol class="steps">
					<li><b>편성</b>: 재정 담당이 부서별로 예산 항목과 금액을 입력해요. 항목마다 <b>카테고리</b>를 고를 수 있어요.</li>
					<li><b>확정</b>: 재정위원장이 확정해요. 확정 뒤에는 <b>추경(금액 추가)·전용(항목 간 이동)</b>으로만 바꿔요.</li>
					<li><b>직접 지출 허용·건당 한도</b>를 항목마다 지정해요. (관리자)</li>
					<li><b>집행 현황</b>: 항목별 예산, 지급 완료, 결재 중, 남은 금액이 보여요.</li>
				</ol>
				<h3>카테고리 미지정 정리</h3>
				<p>예산 화면 위에 <b>카테고리 미지정 N개</b> 카드가 나오면, 항목 이름과 비슷한 카테고리가 미리 골라져 있어요. 확인한 뒤 <b>제안대로만 저장</b> 또는 <b>고른 것 모두 저장</b>을 누르세요. 부서가 쓸 수 없는 카테고리가 섞이면 전부 취소돼요. 금액은 바뀌지 않아서 <b>예산 확정 뒤에도</b> 분류할 수 있어요.</p>
			</section>

			<section id="closing" data-sec hidden={!show('closing')}>
				<h2><i>13</i>마감과 결산</h2>
				<p><span class="path">장부 › 마감</span></p>
				<ul>
					<li><b>월 마감</b> (재정 담당): 계좌별 <b>실제 잔액</b>을 적어 장부와 맞춰 보고 마감해요. 그다음 <b>재정위원장이 승인</b>해요.</li>
					<li>마감한 달은 고칠 수 없어요. 푸는 것은 재정위원장만 할 수 있고 <b>사유가 기록</b>돼요.</li>
					<li><b>연 마감</b> (재정위원장): 12개월이 모두 승인되면 연 마감을 해요. 남은 돈은 다음 해 <b>이월금</b>으로 올라가요.</li>
					<li><b>결산서</b>는 보고서에서 보고 엑셀(CSV)로 받아요.</li>
				</ul>
			</section>

			<section id="report" data-sec hidden={!show('report')}>
				<h2><i>14</i>보고서</h2>
				<p><span class="path">보고서</span></p>
				<div class="scroll"><table>
					<thead><tr><th>보고서</th><th>내용</th></tr></thead>
					<tbody>
						<tr><td><b>주간 헌금 보고</b></td><td>한 주의 헌금 종류별 합계. 주보용 헌금자 명단(금액 없음)도 만들 수 있어요</td></tr>
						<tr><td><b>월 재정 보고</b></td><td>월별 수입·지출·잔액</td></tr>
						<tr><td><b>예산 대비 집행</b></td><td>항목별 예산과 집행 비교</td></tr>
						<tr><td><b>결산서</b></td><td>연간 수입·지출 결산. 엑셀로 받을 수 있어요</td></tr>
						<tr><td><b>교구별 헌금</b></td><td>모임(교구·구역)별 헌금 합계. 개인별 금액은 나오지 않고, 3명 미만인 모임은 합계를 가려요</td></tr>
						<tr><td><b>부서별 지출</b></td><td>위원회 → 부서 × 카테고리(관·항·목) 지출 표와 교회 전체 합계. 월 범위 조회, <b>결재 중 포함</b> 스위치, 엑셀·인쇄. 카테고리가 없는 예산 항목은 '미분류'로 보여요</td></tr>
					</tbody>
				</table></div>
			</section>

			<section id="base" data-sec hidden={!show('base')}>
				<h2><i>15</i>기준 자료와 재정 단위</h2>
				<ul>
					<li><b>기준 자료</b> (설정 › 기준 자료): 회계연도, 특별회계, 계좌(<b>끝 4자리만</b> 저장), 수입 항목을 관리해요.</li>
					<li><b>재정 단위·담당자</b>: 위원회·부서와 담당자를 관리해요. 교적 조직표에서 가져오거나 작년 것을 복사할 수 있어요.</li>
					<li><b>조직도 한꺼번에 추가</b>: <code>위원회이름: 부서1, 부서2</code> 또는 <code>- 부서</code> 형식으로 적으면 여러 부서가 한 번에 만들어져요. 이미 있는 것은 건너뛰어요.</li>
					<li>부서마다 <b>회계·부서장</b>, 위원회마다 <b>위원장</b>을 교인 목록에서 찾아 지정해요. 담당자가 정해져 있어야 결의서를 상신할 수 있어요.</li>
				</ul>
			</section>

			<section id="cat" data-sec hidden={!show('cat')}>
				<h2><i>16</i>지출 카테고리</h2>
				<p><span class="path">설정 › 지출 카테고리</span> · 관리자</p>
				<ul>
					<li>관 → 항 → 목 <b>3단계</b>이고 교회 전체에 하나만 둬요. 처음이면 <b>예시로 채우기</b>를 누르고 교회에 맞게 고쳐요.</li>
					<li>이미 쓴 카테고리는 지우지 않고 <b>사용 안 함</b>으로 바꿔요.</li>
					<li><b>메모 필수</b>: 카테고리마다 켜고 끌 수 있어요(예시의 '기타'는 기본 켜짐). 켜진 카테고리는 결의서 사유·직접 지출 적요를 5자 이상 적어야 해요.</li>
					<li><b>부서별 사용</b> 탭: 위원회·부서마다 쓸 수 있는 카테고리를 체크해요. 정하지 않은 부서는 전부 쓸 수 있고, 정한 부서는 예산 항목을 만들 때 그 안에서만 골라요.</li>
				</ul>
			</section>

			<section id="admin" data-sec hidden={!show('admin')}>
				<h2><i>17</i>설정 (관리자)</h2>
				<div class="scroll"><table>
					<thead><tr><th>메뉴</th><th>하는 일</th></tr></thead>
					<tbody>
						<tr><td><b>재정 사용 승인</b></td><td>신청 승인·정지, 역할 지정, 개인별 헌금 열람 지정</td></tr>
						<tr><td><b>교회 정보·영수증·보안</b></td><td>고유번호·소재지·대표자·직인, 영수증 번호 방식, 예산 초과 결의서 처리(경고/상신 차단), 증빙 없는 직접 지출 처리, 주민번호 방식, <b>영수증 글자 읽기</b> 켜고 끄기</td></tr>
						<tr><td><b>결재 규칙</b></td><td>금액 구간별 결재선, 직접 지출 규칙, 대리 결재</td></tr>
						<tr><td><b>지출 카테고리</b></td><td>위 '지출 카테고리' 참고</td></tr>
						<tr><td><b>백업</b></td><td>내려받기·되살리기, 샘플 자료</td></tr>
					</tbody>
				</table></div>
				<h3>영수증 글자 읽기 켜기 (선택)</h3>
				<ol class="steps">
					<li>설정 › 교회 정보에서 <b>API 열쇠</b>를 넣어요. 열쇠는 DB가 아니라 서버 <code>data</code> 폴더 파일에만 저장돼 <b>백업에 들어가지 않아요.</b></li>
					<li>사진이 <b>외부 AI 업체(Anthropic, 미국)</b>로 전송된다는 확인란에 동의하고 켜요. (비밀번호 재확인이 필요해요)</li>
					<li>켜기 전에 <b>개인정보 처리방침</b>의 국외 이전 안내를 교인·사용자에게 알려 주세요. 켜지 않으면 국외 전송은 없어요.</li>
				</ol>
			</section>

			<section id="import" data-sec hidden={!show('import')}>
				<h2><i>18</i>엑셀 가져오기와 백업</h2>
				<ul>
					<li><b>기존 자료 가져오기</b> (설정): 쓰던 엑셀의 헌금 내역과 예산을 가져와요. 올리기 전에 <b>미리보기</b>로 맞는지 확인하세요. 실제 자료를 넣기 전에 샘플은 지우세요.</li>
					<li><b>백업</b> (설정 › 백업): 재정 자료를 파일로 내려받아요. <b>증빙 사진, 교적, 암호화 열쇠, 영수증 글자 읽기 API 열쇠는 들어가지 않아요.</b> 내려받을 때 사유를 선택해요.</li>
					<li><b>되살리기</b>: 되살리기 전의 현재 상태는 서버 <code>data/backups</code> 에 파일로 남아요. 되살린 뒤 입력한 내용은 사라지니 신중하게 하세요.</li>
					<li>증빙 사진은 FTP로 <code>data/vouchers</code> 폴더를 따로 보관하세요.</li>
					<li>자동 백업은 없어요. <b>연말과 큰 변경 전</b>에는 직접 내려받아 두세요.</li>
				</ul>
			</section>

			<section id="privacy" data-sec hidden={!show('privacy')}>
				<h2><i>19</i>개인정보와 내려받기</h2>
				<ul>
					<li>개인별 헌금 열람, 주민번호 조회, 영수증 발급, 내려받기, 마감은 모두 <b>접속 기록</b>에 남아요(IP 포함).</li>
					<li>영수증 발급명세·백업은 <b>내려받는 사유</b>를 골라야 해요. 장부·결산서·부서별 지출은 사유 없이도 받을 수 있지만 <b>1회용 열쇠</b>(60초)로 열려요.</li>
					<li>주민등록번호는 <b>보관 안 함</b>(영수증에 앞 6자리만 인쇄) 또는 <b>암호화 보관</b> 중 교회가 정해요.</li>
					<li><b>개인정보 처리방침</b>은 로그인하지 않아도 볼 수 있어요. 화면 맨 아래 <a href={hrefTo('/privacy')}>개인정보 처리 방침</a> 링크로 열어요.</li>
				</ul>
				<h3>개인정보 유출이 의심될 때 (관리자)</h3>
				<ol class="steps">
					<li><b>계정 막기</b>: 재정 사용 승인에서 의심 계정을 정지하고 교적에서 비밀번호를 바꿔요.</li>
					<li><b>기록 보존</b>: 접속 기록과 변경 기록은 지우지 말고, 백업으로 지금 상태를 따로 보관해요.</li>
					<li><b>범위 파악</b>: 누가 언제 어느 IP에서 개인별 헌금·주민번호·내려받기를 했는지 접속 기록으로 확인해요.</li>
					<li><b>책임자 보고</b>: 개인정보 관리책임자와 담임목사에게 바로 알려요.</li>
					<li><b>통지·신고</b>: 새어 나간 분께 알리고, 규모가 크면 개인정보침해신고센터(118)에 신고해요.</li>
				</ol>
			</section>

			<section id="link" data-sec hidden={!show('link')}>
				<h2><i>20</i>교적과 함께 쓰기 · 체험</h2>
				<ul>
					<li><b>교적 → 재정</b>: 교적 위쪽 <b>재정 열기</b>를 누르면 비밀번호 없이 재정이 열려요(30초 안에 한 번만 쓸 수 있는 입장권).</li>
					<li><b>재정 → 교적</b>: 위쪽 <b>바로가기</b>에서 교적 관리로 이동해요.</li>
					<li>교적 계정이 정지됐거나 소그룹 인도자(D 등급)이면 들어올 수 없어요.</li>
					<li><b>체험 계정</b>: 교적에 체험 계정이 켜져 있으면 로그인 창에서 <b>체험해 보기</b>로 들어와 구경할 수 있어요. <b>보기만 가능</b>하고 가짜 자료만 보이며, 실제 헌금·지출·교인 정보는 보이지 않아요. 저장·내려받기·영수증 글자 읽기는 막혀요.</li>
				</ul>
			</section>

			<section id="faq" data-sec hidden={!show('faq')}>
				<h2><i>21</i>자주 묻는 질문</h2>
				<dl>
					<dt>로그인이 안 돼요.</dt>
					<dd>교적 관리와 같은 아이디·비밀번호예요. 비밀번호는 교적 관리에서 바꾸고, 잊었다면 교적 관리자에게 임시 비밀번호를 부탁하세요.</dd>
					<dt>"교인과 연결되어 있지 않아요"라고 나와요.</dt>
					<dd>교적 관리자가 내 계정을 교인에 연결해 줘야 해요.</dd>
					<dt>결의서를 상신할 수 없어요.</dt>
					<dd>그 부서의 <b>회계·부서장·위원장·재정위원장·담임목사</b> 담당자가 모두 지정돼 있어야 해요. 재정 단위·담당자에서 확인하세요. 예산을 넘으면 설정에 따라 막힐 수도 있어요.</dd>
					<dt>사유(메모)를 적으라고 하는데 어디에 적나요?</dt>
					<dd>'기타' 같은 메모 필수 카테고리의 예산 항목을 골라서예요. 결의서는 <b>사유</b>, 직접 지출은 <b>적요</b>에 5자 이상 적으세요. 필요 없다면 관리자가 지출 카테고리에서 메모 필수를 끌 수 있어요.</dd>
					<dt>부서별 지출에 '미분류'가 많아요.</dt>
					<dd>예산 항목에 카테고리가 지정되지 않아서예요. 예산 화면의 <b>카테고리 미지정</b> 카드에서 한꺼번에 지정하세요.</dd>
					<dt>잘못 입력한 장부·결의서를 되돌리고 싶어요.</dt>
					<dd>장부에 직접 등록한 기록은 <b>취소</b>(사유가 남아요)하고, 결의서는 결재 전이면 <b>회수·취소</b>해요. 이미 마감한 달은 재정위원장이 마감을 풀어야 고칠 수 있어요.</dd>
					<dt>내려받기가 안 열려요.</dt>
					<dd>내려받을 때 <b>사유를 고르는 창</b>이 나와요. 사유를 고르고 누르세요. 열쇠는 60초 동안 한 번만 쓸 수 있어서, 다시 받으려면 새로 눌러야 해요.</dd>
					<dt>영수증 사진으로 자동 입력이 안 보여요.</dt>
					<dd>관리자가 설정 › 교회 정보에서 켜야 나타나요. 체험 계정에서는 쓸 수 없어요.</dd>
					<dt>저장할 때 "서버에서 문제가 생겼습니다"가 나와요.</dt>
					<dd>호스팅이 한 번에 받는 크기를 제한해서 생길 수 있어요. 사진을 줄여서 다시 올려 보세요. 계속되면 문의해 주세요.</dd>
					<dt>개인정보는 안전한가요?</dt>
					<dd>정보는 교회가 직접 설치한 호스팅 안에만 있고, 로그인하지 않으면 볼 수 없어요. 권한은 서버가 매번 확인하고 열람·발급·내려받기는 접속 기록에 남아요. 영수증 글자 읽기를 켜지 않으면 외부로 전송되는 것은 없어요.</dd>
				</dl>
				<div class="box">더 궁금한 점이나 제안은 <a href="https://github.com/byraju-hub/fin-manage/issues" target="_blank" rel="noopener">GitHub 이슈</a>에 남겨 주세요. 개인정보 문의는 <a href={hrefTo('/privacy')}>개인정보 처리 방침</a>의 책임자 연락처를 보세요.</div>
			</section>

			<p class="end"><a href="#start">↑ 맨 위로</a> · <a href={hrefTo('/')}>대시보드로 돌아가기</a></p>
		</div>
	</div>
</div>

<style>
	.manual { max-width: 1180px; margin: 0 auto; }
	.mhero { display: grid; grid-template-columns: auto 1fr; gap: 1rem 1.2rem; align-items: center; padding: 1.4rem 1.6rem; margin-bottom: 1.1rem; border-radius: 20px; color: #fff;
		background: radial-gradient(120% 160% at 100% 0%, rgba(20, 180, 174, 0.55), transparent 55%), linear-gradient(120deg, var(--navy), #4558ae); box-shadow: var(--shadow-md); }
	.mhero-ic { width: 64px; height: 64px; border-radius: 18px; display: grid; place-items: center; background: rgba(255, 255, 255, 0.16); border: 1px solid rgba(255, 255, 255, 0.3); }
	.mhero-ic svg { width: 34px; height: 34px; fill: none; stroke: #fff; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
	.mhero h1 { margin: 0 0 0.25rem; color: #fff; font-size: 1.7rem; }
	.mhero p { margin: 0; color: rgba(255, 255, 255, 0.88); line-height: 1.6; font-size: 0.95rem; }
	.mhero-act { grid-column: 1 / -1; display: flex; gap: 0.6rem; align-items: center; }
	.msearch { flex: 1; position: relative; display: block; }
	.msearch svg { position: absolute; left: 14px; top: 50%; width: 18px; height: 18px; transform: translateY(-50%); fill: none; stroke: var(--muted); stroke-width: 2; stroke-linecap: round; }
	.msearch input { width: 100%; padding: 0.75rem 1rem 0.75rem 2.6rem; border-radius: 999px; border: 0; font-size: 0.97rem; background: #fff; color: var(--text); box-shadow: 0 2px 10px rgba(0, 0, 0, 0.12); }
	.mprint { background: rgba(255, 255, 255, 0.16); color: #fff; border-color: rgba(255, 255, 255, 0.4); }

	.layout { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 1.2rem; align-items: start; }
	.toc { position: sticky; top: 76px; max-height: calc(100vh - 96px); overflow: auto; display: flex; flex-direction: column; gap: 0.1rem; padding: 0.9rem 0.8rem; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow-sm); font-size: 0.9rem; }
	.toc b { font-size: 0.72rem; letter-spacing: 0.04em; color: var(--muted); margin: 0.7rem 0.5rem 0.2rem; }
	.toc b:first-child { margin-top: 0; }
	.toc a { color: var(--text); padding: 0.35rem 0.6rem; border-radius: 8px; border-left: 3px solid transparent; transition: background 0.15s, color 0.15s; }
	.toc a:hover { background: var(--primary-50); text-decoration: none; }
	.toc a.on { background: var(--primary-50); color: var(--primary-600); font-weight: 700; border-left-color: var(--primary); }
	.toc a.dim { opacity: 0.35; }

	.body { display: flex; flex-direction: column; gap: 1rem; min-width: 0; }
	section[data-sec] { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 1.3rem 1.6rem 1.5rem; box-shadow: var(--shadow-sm); scroll-margin-top: 76px; line-height: 1.75; }
	section[hidden] { display: none; }
	section h2 { display: flex; align-items: center; gap: 0.7rem; margin: 0 0 0.7rem; font-size: 1.3rem; color: var(--primary-600); }
	section h2 i { flex: none; width: 32px; height: 32px; border-radius: 10px; display: grid; place-items: center; font-style: normal; font-size: 0.95rem; font-weight: 800; color: #fff; background: linear-gradient(135deg, var(--primary-600), var(--teal-600)); }
	section h3 { margin: 1.2rem 0 0.4rem; font-size: 1.02rem; color: var(--text); padding-left: 0.6rem; border-left: 4px solid var(--teal); }
	section p { margin: 0.4rem 0; }
	section ul, section ol { margin: 0.4rem 0; padding-left: 1.3rem; display: flex; flex-direction: column; gap: 0.4rem; }
	ol.steps { list-style: none; padding-left: 0; counter-reset: st; }
	ol.steps li { position: relative; padding-left: 2.4rem; counter-increment: st; }
	ol.steps li::before { content: counter(st); position: absolute; left: 0; top: 0.1rem; width: 1.65rem; height: 1.65rem; border-radius: 50%; display: grid; place-items: center; font-size: 0.82rem; font-weight: 800; background: var(--primary-100); color: var(--primary-600); }
	code { background: var(--primary-50); padding: 0.05rem 0.4rem; border-radius: 6px; font-size: 0.88em; }
	.path { display: inline-block; padding: 0.05rem 0.55rem; border-radius: 999px; background: var(--primary-50); color: var(--primary-600); font-size: 0.86em; font-weight: 600; border: 1px solid var(--primary-100); }
	.box { margin: 0.8rem 0 0.2rem; padding: 0.8rem 1rem; border-radius: var(--radius-sm); background: var(--primary-50); border: 1px solid var(--primary-100); }
	.box.tip { background: var(--teal-50); border-color: var(--teal-100); }
	.box.tip::before { content: '💡 '; }
	.box.caution { background: var(--orange-50); border-color: var(--orange-100); }
	.box.caution::before { content: '⚠️ '; }
	.scroll { overflow-x: auto; margin: 0.5rem 0; }
	table { width: 100%; border-collapse: collapse; font-size: 0.92rem; min-width: 520px; }
	th, td { padding: 0.55rem 0.7rem; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
	th { background: var(--primary-50); color: var(--primary-600); font-size: 0.8rem; white-space: nowrap; }
	td:first-child { white-space: nowrap; }
	dl { margin: 0.3rem 0; }
	dt { font-weight: 800; margin-top: 0.9rem; color: var(--primary-600); }
	dt::before { content: 'Q. '; color: var(--orange); }
	dd { margin: 0.2rem 0 0; padding-left: 1.3rem; color: var(--text); }
	dd::before { content: 'A. '; margin-left: -1.3rem; color: var(--teal-600); font-weight: 800; }
	.none { text-align: center; padding: 2rem 0; color: var(--muted); }
	.end { text-align: center; color: var(--muted); margin: 0.4rem 0 1rem; }

	@media (max-width: 900px) {
		.layout { grid-template-columns: minmax(0, 1fr); }
		.toc { position: static; max-height: none; flex-direction: row; flex-wrap: wrap; gap: 0.3rem; }
		.toc b { width: 100%; margin: 0.4rem 0 0; }
		.toc a { border-left: 0; background: var(--primary-50); }
		.mhero { padding: 1.1rem; }
		.mhero h1 { font-size: 1.4rem; }
		section[data-sec] { padding: 1.1rem 1rem 1.2rem; }
	}
	@media print {
		.mhero-act, .toc { display: none; }
		.layout { display: block; }
		section[data-sec] { break-inside: avoid-page; box-shadow: none; }
	}
</style>

// 화면 공통 도우미: 금액·날짜 표시
export const won = (n: number | null | undefined): string => (n ?? 0).toLocaleString('ko-KR');
export const today = (): string => new Date().toISOString().slice(0, 10).replace(/^(\d{4})-(\d\d)-(\d\d)$/, '$1-$2-$3');
/** 로컬 날짜(YYYY-MM-DD). toISOString 은 UTC 라 한국 새벽에 하루 전이 나올 수 있어 따로 만든다. */
export const localDate = (d = new Date()): string => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
/** 가장 가까운 지난(또는 오늘) 일요일 */
export const lastSunday = (): string => {
	const d = new Date();
	d.setDate(d.getDate() - d.getDay());
	return localDate(d);
};
export const errText = (e: unknown, fallback = '처리하지 못했습니다.'): string => (e instanceof Error ? e.message : fallback);

// 내려받기 사유 창: askDownload() 로 사유를 받아 1회용 열쇠를 얻고, 열쇠가 붙은 주소를 돌려준다. 창은 레이아웃이 그린다.
import { auth } from '#lib/auth.svelte.ts';

export const PURPOSES = ['세무 신고', '감사 제출', '결산 보고', '백업 보관', '기타'];
export type DlKind = 'receipts' | 'backup' | 'ledger' | 'settlement';

type Pending = { kind: DlKind; label: string; required: boolean; resolve: (r: { purpose: string; note: string } | null) => void };

class Dl {
	pending = $state<Pending | null>(null);
	/** 사유를 묻는다. 취소하면 null */
	ask(kind: DlKind, label: string, required: boolean) {
		return new Promise<{ purpose: string; note: string } | null>((resolve) => {
			this.pending = { kind, label, required, resolve };
		});
	}
	done(r: { purpose: string; note: string } | null) {
		const p = this.pending;
		this.pending = null;
		p?.resolve(r);
	}
}
export const dl = new Dl();

const REQUIRED: Record<DlKind, boolean> = { receipts: true, backup: true, ledger: false, settlement: false };

/** 사유를 묻고 열쇠를 받아 돌려준다. 취소하면 null. */
export async function dlTicket(kind: DlKind, label: string): Promise<string | null> {
	const r = await dl.ask(kind, label, REQUIRED[kind]);
	if (!r) return null;
	const t = await auth.api<{ token: string }>('POST', '/downloads/ticket', { kind, purpose: r.purpose, note: r.note });
	return t.token;
}

/** 파일 주소를 내려받는다(주소를 열면 파일이 내려가고 화면은 그대로). */
export async function dlOpen(kind: DlKind, label: string, url: string): Promise<boolean> {
	const tok = await dlTicket(kind, label);
	if (!tok) return false;
	location.href = url + (url.includes('?') ? '&' : '?') + 'dl=' + tok;
	return true;
}

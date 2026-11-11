// 로그인 상태와 서버 호출. 계정은 교적 관리와 같은 것을 쓰고(통합 로그인), 재정 사용 승인은 서버가 따로 관리한다.
import { hrefTo } from './paths';

export type AccountState = 'active' | 'pending' | 'none' | 'suspended' | 'nolink' | 'must_change';

export type Me = {
	state: AccountState;
	user: { loginId: string; name: string; personId: string | null };
	access: { status: string; isAdmin: boolean; roles: string[]; canViewDonors: boolean; requestedAt: string } | null;
};

type MePayload = { church: string; csrf: string; me: Me | null };

export class ApiError extends Error {
	constructor(
		public code: string,
		message: string,
		public status: number
	) {
		super(message);
	}
}

class Auth {
	ready = $state(false);
	church = $state('');
	me = $state<Me | null>(null);
	netError = $state('');
	notInstalled = $state(false);
	private csrf = '';

	/** 서버 호출. 성공하면 data 를, 실패하면 서버가 알려 준 메시지로 ApiError 를 던진다. */
	async api<T = unknown>(method: string, path: string, body?: unknown): Promise<T> {
		let res: Response;
		try {
			res = await fetch(hrefTo('/api/v1' + path), {
				method,
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', ...(method === 'GET' ? {} : { 'X-CSRF-Token': this.csrf }) },
				body: body === undefined ? undefined : JSON.stringify(body)
			});
		} catch {
			throw new ApiError('network', '서버에 연결하지 못했습니다. 인터넷 연결을 확인해 주세요.', 0);
		}
		let json: { ok: boolean; data?: T; error?: { code: string; message: string } } | null = null;
		try {
			json = await res.json();
		} catch {
			/* 서버가 JSON 이 아닌 응답을 준 경우 (호스팅이 요청을 막았을 때 등) */
		}
		if (!json) throw new ApiError('bad_response', '서버 응답을 읽지 못했습니다. (' + res.status + ')', res.status);
		if (!json.ok) {
			// 로그인이 풀렸으면 화면도 로그인으로 돌린다
			if (res.status === 401 && path !== '/auth/login') this.me = null;
			throw new ApiError(json.error?.code ?? 'error', json.error?.message ?? '요청을 처리하지 못했습니다.', res.status);
		}
		return json.data as T;
	}

	private apply(p: MePayload) {
		this.church = p.church ?? this.church;
		this.csrf = p.csrf;
		this.me = p.me;
	}

	async init() {
		try {
			const st = await this.api<{ installed: boolean }>('GET', '/system/status');
			if (!st.installed) {
				this.notInstalled = true;
				return;
			}
			this.apply(await this.api<MePayload>('GET', '/auth/me'));
		} catch (e) {
			if (e instanceof ApiError && e.code === 'not_installed') this.notInstalled = true;
			else this.netError = e instanceof Error ? e.message : '서버에 연결하지 못했습니다.';
		} finally {
			this.ready = true;
		}
	}

	async login(loginId: string, password: string) {
		const r = await this.api<{ csrf: string; me: Me }>('POST', '/auth/login', { loginId, password });
		this.csrf = r.csrf;
		this.me = r.me;
	}

	/** 교적의 '재정 열기'에서 넘어온 1회용 입장권으로 로그인 */
	async ssoLogin(ticket: string) {
		const r = await this.api<{ csrf: string; me: Me }>('POST', '/auth/sso', { ticket });
		this.csrf = r.csrf;
		this.me = r.me;
	}

	async logout() {
		try {
			await this.api('POST', '/auth/logout');
		} catch {
			/* 이미 로그아웃된 경우 */
		}
		await this.refresh();
	}

	/** 로그인 상태와 새 CSRF 토큰을 다시 받는다 */
	async refresh() {
		this.apply(await this.api<MePayload>('GET', '/auth/me'));
	}

	/** 중요한 작업: 서버가 비밀번호 재확인을 요구하면 확인 창을 띄우고, 맞으면 한 번 더 시도한다 */
	reconfirmOpen = $state(false);
	private reconfirmDone: ((ok: boolean) => void) | null = null;
	async guard<T>(fn: () => Promise<T>): Promise<T> {
		try {
			return await fn();
		} catch (e) {
			if (e instanceof ApiError && e.code === 'reconfirm') {
				const ok = await new Promise<boolean>((res) => {
					this.reconfirmDone = res;
					this.reconfirmOpen = true;
				});
				if (ok) return await fn();
				throw new ApiError('cancelled', '비밀번호 확인을 취소했습니다.', 0);
			}
			throw e;
		}
	}
	/** 확인 창에서 호출: 비밀번호를 보내 맞으면 true, 틀리면 오류를 던져 창에 보여 준다. password 가 null 이면 취소 */
	async submitReconfirm(password: string | null) {
		if (password === null) {
			this.reconfirmOpen = false;
			this.reconfirmDone?.(false);
			return;
		}
		await this.api('POST', '/auth/reconfirm', { password });
		this.reconfirmOpen = false;
		this.reconfirmDone?.(true);
	}

	async requestAccess(reason: string) {
		await this.api('POST', '/auth/request-access', { reason });
		await this.refresh();
	}
}

export const auth = new Auth();

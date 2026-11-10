// 사이트를 서브폴더(예: 카페24의 /fm)에 올려도 주소가 맞도록, 화면 안의 모든 내부 주소는 이 함수를 거칩니다.
// 맨 위 폴더에 올리면 그대로이고, /fm 에 올리면 앞에 /fm 가 붙습니다.
import { resolve as kitResolve } from '$app/paths';

// 경로 문자열을 그대로 받도록 느슨하게 (SvelteKit 의 타입은 정해진 경로만 받음)
const resolve = kitResolve as unknown as (path: string) => string;

/** 내부 주소에 사이트 폴더를 붙임. hrefTo('/offerings') → '/fm/offerings'. 바깥 주소(https://…)나 # 로 시작하는 값은 그대로 */
export const hrefTo = (p: string): string => (p.startsWith('/') && !p.startsWith('//') ? resolve(p) : p);

/** 사이트 폴더를 뺀 주소. stripBase('/fm/offerings') → '/offerings' (메뉴에서 지금 화면을 알아볼 때) */
export function stripBase(pathname: string): string {
	const root = resolve('/').replace(/\/$/, '');
	if (root && pathname.startsWith(root)) return pathname.slice(root.length) || '/';
	return pathname;
}

import adapter from '@sveltejs/adapter-static';
import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vite';

// 사이트를 서브폴더(예: /fm)에 올릴 때: BASE_PATH=/fm npm run build
// eslint-disable-next-line
declare const process: { env: Record<string, string | undefined> };
const raw = process.env.BASE_PATH ?? '';
const base = (raw.endsWith('/') ? raw.slice(0, -1) : raw) as '' | `/${string}`;

export default defineConfig({
	plugins: [
		sveltekit({
			compilerOptions: {
				// Force runes mode for the project, except for libraries. Can be removed in svelte 6.
				runes: ({ filename }) =>
					filename.split(/[/\\]/).includes('node_modules') ? undefined : true
			},

			// 카페24(Apache) 정적 호스팅용: 모든 주소를 index.html 로 돌려 한 장짜리 앱으로 동작
			adapter: adapter({ fallback: 'index.html', pages: 'build', assets: 'build' }),
			paths: { base }
		})
	],
	// 개발 서버(npm run dev)에서 /api 는 PHP 로 넘김: php -S 127.0.0.1:8091 -t server server/dev-router.php (교적 개발 서버가 8090 을 쓰므로 재정은 8091)
	server: { port: 5174, proxy: { '/api': 'http://127.0.0.1:8091' } }
});

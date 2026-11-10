#!/usr/bin/env bash
# 배포용 zip(fin-manage-<버전>.zip)을 만든다. GitHub Actions 가 같은 스크립트를 쓴다.
#
# 사용법:  ./scripts/build-release.sh [버전]      (버전을 생략하면 server/api/lib/bootstrap.php 의 값)
#
# zip 안의 구성 (사이트 폴더에 그대로 올리면 된다):
#   index.html, _app/ ...   화면. 주소의 앞부분은 /__FM_BASE__ 자리표시자로 두고, 설치 화면이 실제 폴더로 바꿔 넣는다.
#   api/  install/  migrations/  data/.htaccess   서버
#   설치가이드.pdf  README.txt
#
# 설치 가이드 PDF 는 docs/install-guide.html 을 Chrome(헤드리스)으로 인쇄해 만든다.
# Chrome 이 없으면 저장소에 있는 docs/설치가이드.pdf 를 그대로 쓴다.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="${1:-$(sed -n "s/^const FM_VERSION = '\(.*\)';/\1/p" "$ROOT/server/api/lib/bootstrap.php")}"
VERSION="${VERSION#v}"
# 태그가 아닌 실행(브랜치 이름 등)이면 소스에 적힌 버전을 쓴다.
case "$VERSION" in
	[0-9]*) ;;
	*) VERSION="$(sed -n "s/^const FM_VERSION = '\(.*\)';/\1/p" "$ROOT/server/api/lib/bootstrap.php")" ;;
esac
OUT="$ROOT/dist"
STAGE="$OUT/fin-manage"
ZIP="$OUT/fin-manage-v$VERSION.zip"

rm -rf "$OUT"
mkdir -p "$STAGE"

echo "== 화면 빌드"
(cd "$ROOT" && MSYS_NO_PATHCONV=1 BASE_PATH=/__FM_BASE__ npm run build >/dev/null)
cp -R "$ROOT/build/." "$STAGE/"

echo "== 서버 파일"
for d in api install migrations; do cp -R "$ROOT/server/$d" "$STAGE/$d"; done
mkdir -p "$STAGE/data"
cp "$ROOT/server/data/.htaccess" "$STAGE/data/.htaccess"
sed -i "s/^const FM_VERSION = '.*';/const FM_VERSION = '$VERSION';/" "$STAGE/api/lib/bootstrap.php"

echo "== 설치 가이드 PDF"
pdf="$STAGE/설치가이드.pdf"
chrome=""
for c in google-chrome google-chrome-stable chromium chromium-browser 	"/c/Program Files/Google/Chrome/Application/chrome.exe" "/c/Program Files (x86)/Google/Chrome/Application/chrome.exe" 	"/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe"; do
	if command -v "$c" >/dev/null 2>&1 || [ -x "$c" ]; then chrome="$c"; break; fi
done
html="$ROOT/docs/install-guide.html"
if [ -f "$html" ]; then
	if [ -n "$chrome" ]; then
		# Windows 의 Chrome 은 file:// 주소에 드라이브 글자가 필요하다.
		if command -v cygpath >/dev/null 2>&1; then src="file:///$(cygpath -m "$html")"; outp="$(cygpath -w "$pdf")"; else src="file://$html"; outp="$pdf"; fi
		"$chrome" --headless=new --disable-gpu --no-pdf-header-footer --print-to-pdf="$outp" "$src" >/dev/null 2>&1 || true
	fi
	if [ ! -s "$pdf" ] && [ -f "$ROOT/docs/설치가이드.pdf" ]; then cp "$ROOT/docs/설치가이드.pdf" "$pdf"; fi
	[ -s "$pdf" ] && [ -n "$chrome" ] && cp "$pdf" "$ROOT/docs/설치가이드.pdf" || true
else
	echo "   설치 가이드(docs/install-guide.html)가 아직 없어 건너뜁니다."
fi

cat >"$STAGE/README.txt" <<EOF
fin-manage v$VERSION - 교회 재정 관리 프로그램 (무료, AGPL-3.0)

1. 이 폴더 안의 모든 파일을 호스팅의 사이트 폴더(예: www 또는 www/fm)에 올립니다.
2. 브라우저에서 https://내주소/(폴더)/install/ 을 열어 안내를 따릅니다.
3. 교적 관리(ch-manage)가 같은 데이터베이스에 먼저 설치되어 있어야 합니다. 자세한 설명은 설치가이드.pdf 를 보세요.

새 버전으로 업데이트: 백업을 받은 뒤 같은 위치에 덮어쓰고 install/ 을 한 번 엽니다.
소스와 새 버전: https://github.com/byraju-hub/fin-manage
EOF

echo "== zip 만들기"
cd "$OUT/fin-manage"
if command -v zip >/dev/null 2>&1; then
	zip -qr "$ZIP" . -x '.DS_Store'
else
	# zip 이 없는 환경(윈도우 Git Bash)에서는 윈도우 기본 tar(bsdtar)로 만든다.
	/c/Windows/System32/tar.exe -a -cf "$(cygpath -w "$ZIP")" -C "$(cygpath -w "$OUT/fin-manage")" .
fi
echo "만들었어요: $ZIP ($(du -h "$ZIP" | cut -f1))"

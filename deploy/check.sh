#!/usr/bin/env bash
# Deploydan sonra canlı saytın yoxlanışı. Hər yoxlama saxlanmış cavabı oxuyur
# (pipefail altında `curl | grep -q` yoxdur: grep tez çıxanda curl sınır və test tərsinə dönür).
set -uo pipefail

SITE="${1%/}"
fail=0

status() { curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$1"; }

check_status() { # url gözlənilən
  local got; got="$(status "$1")"
  if [ "$got" = "$2" ]; then echo "ok   $2  $1"; else echo "FAIL $got (gözlənilən $2)  $1"; fail=1; fi
}

check_body() { # url axtarılan
  curl -s --max-time 30 "$1" -o body.html
  if grep -q -- "$2" body.html; then echo "ok   '$2' var  $1"; else echo "FAIL '$2' yoxdur  $1"; head -c 600 body.html; echo; fail=1; fi
}

# Deploydan dərhal sonra LiteSpeed .htaccess-i yenidən oxuyana qədər 404 verə bilər: real 200 gözlə.
for i in $(seq 1 48); do [ "$(status "$SITE/login")" = "200" ] && [ "$(status "$SITE/up")" = "200" ] && break; sleep 5; done
check_status "$SITE/up" 200
check_status "$SITE/login" 200
check_body "$SITE/login" "Valvo Analitika"
check_body "$SITE/login" "/build/assets/app-"

css="$(grep -o '/build/assets/app-[A-Za-z0-9_-]*\.css' body.html | head -1)"
js="$(grep -o '/build/assets/app-[A-Za-z0-9_-]*\.js' body.html | head -1)"
[ -n "$css" ] && check_status "$SITE$css" 200 || { echo "FAIL css linki yoxdur"; fail=1; }
[ -n "$js" ] && check_status "$SITE$js" 200 || { echo "FAIL js linki yoxdur"; fail=1; }

# Qonaq girişə yönləndirilir.
check_status "$SITE/" 302

# Daxili fayllar heç vaxt verilmir.
for p in .env _app/.env _app/composer.json _app_shared/.env _app_storage/database.sqlite _app_storage/logs/laravel.log _releases/ _app/artisan \
         database/database.sqlite storage/logs/laravel.log composer.json .ftp-deploy-sync-state.json; do
  got="$(status "$SITE/$p")"
  if [ "$got" = "200" ]; then echo "FAIL /$p açıqdır"; fail=1; else echo "ok   $got  /$p bağlıdır"; fi
done

exit $fail

#!/usr/bin/env bash
# Проверка живого сайта подноль.рф: главная, обработчик заявок, мост Авито,
# фид Авито. Запускается после выкладки и по расписанию (health.yml);
# выход с ошибкой — сигнал написать владельцу.
set -u
SITE="https://xn--d1aofccc0h.xn--p1ai"
fail=0
err() { echo "::error::$1"; echo "- $1" >> "${PROBLEMS_FILE:-/dev/null}"; fail=1; }

code=$(curl -s -o /dev/null -w '%{http_code}' -m 20 "$SITE/")
[ "$code" = 200 ] || err "главная отвечает $code"

r=$(curl -sS -m 20 -H "Origin: $SITE" -A "Mozilla/5.0 (site-check)" "$SITE/api/lead.php?token=1" 2>&1)
echo "$r" | grep -q '"ok":true' || err "форма заявок не работает (lead.php: ${r:0:120})"

code=$(curl -s -o /dev/null -w '%{http_code}' -m 20 -X POST "$SITE/api/avito.php")
[ "$code" = 404 ] || err "мост Авито отвечает $code вместо 404 — конфиг не собран или PHP падает"

code=$(curl -s -o /dev/null -w '%{http_code}' -m 20 "$SITE/avito/avito.xml")
[ "$code" = 200 ] || err "фид Авито отвечает $code — автозагрузка не обновит объявления"

[ $fail = 0 ] && echo "подноль.рф: всё работает"
exit $fail

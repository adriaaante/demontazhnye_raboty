#!/usr/bin/env bash
# Аванс Авито «Под Ноль» на целевые действия — с него списываются ставки
# продвижения cpxpromo (не кошелёк). Ниже порога — сообщение в группу Под Ноль от бота сайта.
# Запускается по расписанию из avito-balance.yml; DRY=1 — только напечатать.
# Нужны: AVITO_CLIENT_ID, AVITO_CLIENT_SECRET, TG_BOT_TOKEN, TG_CHAT_ID.
set -euo pipefail
MIN_RUB="${MIN_RUB:-2000}"

send() {
  if [ "${DRY:-}" = 1 ]; then echo "[DRY] $1"; return; fi
  curl -sS -m 20 "https://api.telegram.org/bot${TG_BOT_TOKEN}/sendMessage" \
    --data-urlencode chat_id="$TG_CHAT_ID" --data-urlencode text="$1" > /dev/null
}

# JSON-поле из ответа; пустая строка, если ответа нет или он не JSON
field() { python3 -c 'import json,sys
try: print(json.load(sys.stdin).get(sys.argv[1], ""))
except Exception: print("")' "$1"; }

# Авито часто отвечает 429 на частые запросы — несколько попыток с паузой.
token=""; penny=""
for attempt in 1 2 3 4 5; do
  [ -n "$token" ] || token=$(curl -sS -m 30 https://api.avito.ru/token \
    -d grant_type=client_credentials -d client_id="$AVITO_CLIENT_ID" -d client_secret="$AVITO_CLIENT_SECRET" \
    | field access_token || true)
  if [ -n "$token" ]; then
    # balanceInfo отдаёт копейки
    penny=$(curl -sS -m 30 -X POST -H "Authorization: Bearer $token" -H 'Content-Type: application/json' \
      -d '{}' https://api.avito.ru/cpa/v3/balanceInfo | field balance || true)
    [ -n "$penny" ] && break
  fi
  [ "$attempt" = 5 ] || sleep $((attempt * 20))
done

if [ -z "$penny" ]; then
  send "⚠️ Авито «Под Ноль»: не удалось проверить аванс — API Авито не ответил или ключи не подходят."
  exit 1
fi

rub=$((penny / 100))
echo "аванс: ${rub} ₽ (порог ${MIN_RUB} ₽)"
if [ "$rub" -lt "$MIN_RUB" ]; then
  nice=$(python3 -c 'import sys; print(f"{int(sys.argv[1]):,}".replace(",", " "))' "$rub")
  send "⚠️ Авито «Под Ноль»: аванс на продвижение — ${nice} ₽, меньше ${MIN_RUB} ₽. Когда он закончится, ставки остановятся. Пополнить нужно аванс (не кошелёк)."
fi

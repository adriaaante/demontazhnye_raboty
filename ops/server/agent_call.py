#!/usr/bin/env python3
"""
Клиент агента сервера сайтов (ops/server/agent.php) — вместо SSH из облачной сессии.
Ключ — sha256('ff-agent|' + PODNOL_TG_BOT_TOKEN) из окружения, сервер — FF_SERVER_IP или 45.93.201.72.

  python3 ops/server/agent_call.py status
  python3 ops/server/agent_call.py sh <файл-скрипт> [сколько ждать, ×3 с]   — выполнить от root и показать вывод
  python3 ops/server/agent_call.py put <файл> <имя>                         — положить файл в /opt/ff/upload/<имя>
"""
import hashlib, hmac, json, os, sys, time, urllib.request

IP = os.environ.get('FF_SERVER_IP', '45.93.201.72')
TOKEN = os.environ.get('PODNOL_TG_BOT_TOKEN', '')
if not TOKEN:
    sys.exit('нет PODNOL_TG_BOT_TOKEN в окружении')
KEY = hashlib.sha256(('ff-agent|' + TOKEN).encode()).hexdigest()


def call(a, body=b'', q=''):
    ts = str(int(time.time()))
    sig = hmac.new(KEY.encode(), (ts + '\n' + a + '\n').encode() + body, hashlib.sha256).hexdigest()
    req = urllib.request.Request(f'http://{IP}/agent.php?a={a}{q}', data=body, method='POST',
                                 headers={'X-Ts': ts, 'X-Sig': sig})
    return json.loads(urllib.request.urlopen(req, timeout=120).read())


if __name__ == '__main__':
    a = sys.argv[1] if len(sys.argv) > 1 else 'status'
    if a == 'sh':
        rid = call('run', open(sys.argv[2], 'rb').read())['id']
        for _ in range(int(sys.argv[3]) if len(sys.argv) > 3 else 200):
            o = call('out', q='&id=' + rid)
            if o['done']:
                print(o['out'], end='')
                print(f'[код {o["code"]}]')
                break
            time.sleep(3)
        else:
            print('не дождался, id', rid)
    elif a == 'put':
        print(call('put', open(sys.argv[2], 'rb').read(), '&name=' + sys.argv[3]))
    else:
        print(json.dumps(call(a), ensure_ascii=False, indent=1))

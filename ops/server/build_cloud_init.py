#!/usr/bin/env python3
"""
Собирает cloud-init для сервера трёх сайтов (voidaform.ru, подноль.рф, nespatmoskva.ru) на Timeweb Cloud.

  python3 ops/server/build_cloud_init.py <куда записать yaml>

Секреты берутся из окружения сессии (VOIDAFORM_TG_*, PODNOL_TG_BOT_TOKEN, PODNOL_AVITO_*,
FUTUREFLOW_OWNER_CHAT_ID) и попадают только в итоговый yaml — его в git не кладём.
Сервер после первого запуска: Apache + PHP 8.3 (сайты рассчитаны на .htaccess), по виртуальному
хосту на сайт (папки как на Beget: /var/www/<домен>/public_html, рядом crm-data и deploy-state),
временные самоподписанные сертификаты (настоящие — certbot после переключения DNS), выкладка
с GitHub (ops/pull-deploy.php → /opt/ff/deploy.php) раз в 5 минут, агент управления (agent.php)
и проверка доступа к Telegram (/opt/ff/out/telegram.txt).
"""

import base64, json, os, sys, textwrap

HERE = os.path.dirname(os.path.abspath(__file__))
OPS = os.path.dirname(HERE)

PODNOL = "xn--d1aofccc0h.xn--p1ai"
SITES = {
    "voidaform.ru": dict(repo="adriaaante/voidaform.ru", branch="main",
                         skip=[".git", ".github", "scripts", "CLAUDE.md", "DEPLOY.md", ".gitignore"],
                         must=["index.html", "404.html", ".htaccess", "css/style.css", "js/main.js", "api/lead.php", "api/tg.php"]),
    PODNOL: dict(repo="adriaaante/demontazhnye_raboty", branch="main",
                 skip=[".git", ".github", "scripts", "ops", "CLAUDE.md", ".gitignore"],
                 must=["index.html", "404.html", ".htaccess", "css/style.css", "js/main.js", "api/lead.php", "api/tg.php",
                       "crm/api.php", "crm/app.js", "avito/avito.xml"]),
    "nespatmoskva.ru": dict(repo="adriaaante/NeSpat", branch="claude/install-ui-ux-pro-max-skill-8bfxro",
                            skip=[".git", ".github", ".claude", "CLAUDE.md", ".gitignore"],
                            must=["index.html", "404.html", ".htaccess", "crm/api.php", "crm/pub.php", "crm/lib/db.php"]),
}


def env(name):
    v = os.environ.get(name, "")
    if not v:
        sys.exit(f"нет переменной окружения {name}")
    return v


def php_str(s):
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def config_php(items):
    rows = "".join(f"    {php_str(k)} => {v},\n" for k, v in items)
    return "<?php\n// собран при создании сервера (ops/server/build_cloud_init.py), в git не хранится\nreturn [\n" + rows + "];\n"


def vhost(d):
    root = f"/var/www/{d}/public_html"
    common = textwrap.dedent(f"""\
        ServerName {d}
        ServerAlias www.{d}
        DocumentRoot {root}
        <Directory {root}>
          AllowOverride All
          Require all granted
        </Directory>
        ErrorLog ${{APACHE_LOG_DIR}}/{d}-error.log
        CustomLog ${{APACHE_LOG_DIR}}/{d}-access.log combined""")
    ind = textwrap.indent(common, "  ")
    return (f"<VirtualHost *:80>\n{ind}\n</VirtualHost>\n"
            f"<VirtualHost *:443>\n{ind}\n  SSLEngine on\n"
            f"  SSLCertificateFile /etc/ssl/ff/{d}.crt\n  SSLCertificateKeyFile /etc/ssl/ff/{d}.key\n</VirtualHost>\n")


AGENT_VHOST = """<VirtualHost *:80>
  DocumentRoot /var/www/agent
  <Directory /var/www/agent>
    AllowOverride None
    Require all granted
    DirectoryIndex agent.php
  </Directory>
</VirtualHost>
<VirtualHost *:443>
  DocumentRoot /var/www/agent
  <Directory /var/www/agent>
    AllowOverride None
    Require all granted
    DirectoryIndex agent.php
  </Directory>
  SSLEngine on
  SSLCertificateFile /etc/ssl/ff/agent.crt
  SSLCertificateKeyFile /etc/ssl/ff/agent.key
</VirtualHost>
"""

RUNNER = """#!/bin/bash
# выполняет команды, которые агент положил в очередь (запускает systemd ff-runner.path)
shopt -s nullglob
for f in /opt/ff/queue/*.sh; do
  id=$(basename "$f" .sh)
  mv "$f" "/opt/ff/work/$id.sh"
  timeout 900 bash "/opt/ff/work/$id.sh" > "/opt/ff/out/$id.log" 2>&1
  echo $? > "/opt/ff/out/$id.code"
done
"""

RUNNER_UNITS = {
    "/etc/systemd/system/ff-runner.path": "[Unit]\nDescription=ff agent queue\n[Path]\nDirectoryNotEmpty=/opt/ff/queue\n[Install]\nWantedBy=multi-user.target\n",
    "/etc/systemd/system/ff-runner.service": "[Unit]\nDescription=ff agent runner\n[Service]\nType=oneshot\nExecStart=/opt/ff/runner.sh\n",
}

BOOTSTRAP = r"""#!/bin/bash
# первая настройка сервера; лог — /opt/ff/out/bootstrap.log, готово — /opt/ff/out/bootstrap.done
mkdir -p /opt/ff/queue /opt/ff/work /opt/ff/out /opt/ff/upload
exec >>/opt/ff/out/bootstrap.log 2>&1
set -x
export DEBIAN_FRONTEND=noninteractive
timedatectl set-timezone Europe/Moscow

# Telegram — первым делом: от этого зависит, подходит ли сервер (заявки и Авито идут туда)
for i in 1 2 3 4 5; do
  curl -sS -m 15 -o /dev/null -w "api.telegram.org: %{http_code} за %{time_total}s\n" https://api.telegram.org/ >> /opt/ff/out/telegram.txt 2>&1 \
    || echo "api.telegram.org: ошибка соединения" >> /opt/ff/out/telegram.txt
done

if [ ! -f /swapfile ]; then fallocate -l 1G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile && echo '/swapfile none swap sw 0 0' >> /etc/fstab; fi

apt-get update
apt-get install -y apache2 libapache2-mod-php8.3 php8.3-cli php8.3-curl php8.3-sqlite3 php8.3-mbstring \
  php8.3-xml php8.3-zip php8.3-intl certbot python3-certbot-apache sqlite3 unzip
a2enmod rewrite headers expires deflate ssl
# 1 ГБ памяти: mod_php держит процесс на запрос — ограничиваем, чтобы при всплеске не уйти в своп
cat > /etc/apache2/conf-available/ff-mpm.conf <<'MPM'
<IfModule mpm_prefork_module>
  StartServers 3
  MinSpareServers 2
  MaxSpareServers 5
  MaxRequestWorkers 16
  MaxConnectionsPerChild 500
</IfModule>
MPM
a2enconf ff-mpm
cp /opt/ff/php.ini /etc/php/8.3/apache2/conf.d/99-ff.ini
cp /opt/ff/php.ini /etc/php/8.3/cli/conf.d/99-ff.ini

mkdir -p /etc/ssl/ff /var/www/agent
cp /opt/ff/agent.php /var/www/agent/agent.php
for d in __SITES__ agent; do
  [ -f /etc/ssl/ff/$d.crt ] || openssl req -x509 -newkey rsa:2048 -nodes -days 60 -subj "/CN=$d" \
    -keyout /etc/ssl/ff/$d.key -out /etc/ssl/ff/$d.crt
done
for d in __SITES__; do
  mkdir -p /var/www/$d/public_html /var/www/$d/crm-data /var/www/$d/deploy-state
  [ -f /opt/ff/config/$d.php ] && mkdir -p /var/www/$d/public_html/api && cp /opt/ff/config/$d.php /var/www/$d/public_html/api/config.php
  chown -R www-data:www-data /var/www/$d
  chmod 700 /var/www/$d/crm-data
done
chown root:www-data /opt/ff/agent.key /opt/ff/sites.json && chmod 640 /opt/ff/agent.key /opt/ff/sites.json
chown www-data:www-data /opt/ff/queue /opt/ff/upload && chmod 770 /opt/ff/queue /opt/ff/upload
chmod 755 /opt/ff/out

a2dissite 000-default
a2ensite 000-agent __SITES__
systemctl restart apache2

systemctl daemon-reload
systemctl enable --now ff-runner.path

ufw allow OpenSSH; ufw allow 80/tcp; ufw allow 443/tcp; ufw --force enable

sudo -u www-data php /opt/ff/deploy.php all --force
echo '*/5 * * * * www-data php /opt/ff/deploy.php all >/dev/null 2>&1' > /etc/cron.d/ff-deploy
# очередь Telegram (api/tg.php): то, что не ушло сразу, досылается раз в минуту
cp /opt/ff/tg-flush.sh /usr/local/bin/ff-tg-flush && chmod 755 /usr/local/bin/ff-tg-flush
echo '* * * * * www-data /usr/local/bin/ff-tg-flush >/dev/null 2>&1' > /etc/cron.d/ff-tg-flush
date > /opt/ff/out/bootstrap.done
"""

TG_FLUSH = """#!/bin/bash
# досылает очередь Telegram каждого сайта, у которого есть api/tg.php
for t in /var/www/*/public_html/api/tg.php; do
  php -r 'require $argv[1]; if (function_exists("tg_flush_queue")) tg_flush_queue();' "$t"
done
"""

PHP_INI = "expose_php = Off\nupload_max_filesize = 100M\npost_max_size = 110M\nmemory_limit = 256M\nmax_execution_time = 180\n"


def main():
    out = sys.argv[1]
    # ключ агента выводится из токена бота — тот же считает ops/server/agent_call.py
    import hashlib
    agent_key = hashlib.sha256(('ff-agent|' + env('PODNOL_TG_BOT_TOKEN')).encode()).hexdigest()
    owner = env("FUTUREFLOW_OWNER_CHAT_ID")
    configs = {
        "voidaform.ru": config_php([
            ("token", php_str(env("VOIDAFORM_TG_BOT_TOKEN"))), ("chat_id", php_str(env("VOIDAFORM_TG_CHAT_ID"))),
            ("owner_chat_id", php_str(owner)), ("site", php_str("voidaform.ru")),
            ("origins", "['https://voidaform.ru', 'https://" + PODNOL + "']"),
        ]),
        PODNOL: config_php([
            ("token", php_str(env("PODNOL_TG_BOT_TOKEN"))), ("chat_id", php_str("-5522407239")),
            ("owner_chat_id", php_str(owner)), ("site", php_str("подноль.рф")),
            ("origins", "['https://" + PODNOL + "']"),
            ("avito_client_id", php_str(env("PODNOL_AVITO_CLIENT_ID"))),
            ("avito_client_secret", php_str(env("PODNOL_AVITO_CLIENT_SECRET"))),
        ]),
    }
    sites = {d: dict(s, root=f"/var/www/{d}/public_html") for d, s in SITES.items()}
    names = " ".join(SITES)
    files = {
        "/opt/ff/bootstrap.sh": (BOOTSTRAP.replace("__SITES__", names), "0755"),
        "/opt/ff/runner.sh": (RUNNER, "0755"),
        "/opt/ff/deploy.php": (open(os.path.join(OPS, "pull-deploy.php")).read(), "0644"),
        "/opt/ff/agent.php": (open(os.path.join(HERE, "agent.php")).read(), "0644"),
        "/opt/ff/sites.json": (json.dumps(sites, ensure_ascii=False, indent=1), "0640"),
        "/opt/ff/agent.key": (agent_key + "\n", "0640"),
        "/opt/ff/php.ini": (PHP_INI, "0644"),
        "/opt/ff/tg-flush.sh": (TG_FLUSH, "0755"),
        "/etc/apache2/sites-available/000-agent.conf": (AGENT_VHOST, "0644"),
        **{f"/etc/apache2/sites-available/{d}.conf": (vhost(d), "0644") for d in SITES},
        **{f"/opt/ff/config/{d}.php": (c, "0600") for d, c in configs.items()},
        **{p: (c, "0644") for p, c in RUNNER_UNITS.items()},
    }
    lines = ["#cloud-config", "write_files:"]
    for path, (content, mode) in files.items():
        lines += [f"  - path: {path}", f"    permissions: '{mode}'", "    encoding: b64",
                  "    content: " + base64.b64encode(content.encode()).decode()]
    lines += ["runcmd:", "  - [bash, /opt/ff/bootstrap.sh]", ""]
    data = "\n".join(lines)
    if len(data.encode()) > 65535:
        sys.exit(f"cloud-init {len(data.encode())} байт — больше лимита Timeweb 65 535")
    with open(out, "w") as f:
        f.write(data)
    os.chmod(out, 0o600)
    print(f"cloud-init: {len(data.encode())} байт, файлов {len(files)}")


if __name__ == "__main__":
    main()

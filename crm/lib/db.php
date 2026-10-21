<?php
/**
 * База CRM — SQLite-файл вне папки сайта: на Beget это каталог сайта над
 * public_html (…/podnolj.rf/crm-data/crm.sqlite), снаружи его не скачать.
 * Если туда писать нельзя, — запасной каталог crm/data/ с запретом доступа.
 * Схема создаётся и дополняется сама при первом обращении (crm_migrate).
 */

declare(strict_types=1);

const CRM_STATUSES = [
    'new'      => 'Новая',
    'contact'  => 'Связались',
    'visit'    => 'Замер назначен',
    'estimate' => 'Смета отправлена',
    'deal'     => 'Договор',
    'done'     => 'Выполнено',
    'lost'     => 'Отказ',
];
const CRM_CLOSED = ['done', 'lost'];
const CRM_LOST_REASONS = [
    'Дорого',
    'Нашли другого исполнителя',
    'Не дозвонились / не отвечает',
    'Передумал / отложил',
    'Не наш профиль работ',
    'Спам / не клиент',
];
const CRM_ROLES = ['dev' => 'Разработчик', 'head' => 'Руководитель', 'manager' => 'Менеджер'];

function crm_data_dir(): string
{
    $candidates = [dirname(__DIR__, 3) . '/crm-data', dirname(__DIR__) . '/data'];
    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            if (str_starts_with($dir, dirname(__DIR__))) {
                // запасной каталог внутри сайта — закрываем от веба
                @file_put_contents($dir . '/.htaccess', "Require all denied\n");
            }
            return $dir;
        }
    }
    throw new RuntimeException('нет каталога для базы CRM');
}

function crm_db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . crm_data_dir() . '/crm.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
    crm_migrate($pdo);
    return $pdo;
}

function crm_migrate(PDO $db): void
{
    $v = (int)$db->query('PRAGMA user_version')->fetchColumn();
    if ($v < 1) {
        $db->exec(<<<SQL
CREATE TABLE users(
  id INTEGER PRIMARY KEY, name TEXT NOT NULL, role TEXT NOT NULL,
  pin_hash TEXT NOT NULL, must_change INTEGER NOT NULL DEFAULT 1, active INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL, created_by INTEGER, last_login INTEGER
);
CREATE TABLE sessions(
  token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id),
  created_at INTEGER NOT NULL, last_seen INTEGER NOT NULL, ip TEXT
);
CREATE TABLE clients(
  id INTEGER PRIMARY KEY, name TEXT, phone TEXT, avito_user_id INTEGER,
  created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX clients_phone ON clients(phone) WHERE phone IS NOT NULL;
CREATE UNIQUE INDEX clients_avito ON clients(avito_user_id) WHERE avito_user_id IS NOT NULL;
CREATE TABLE leads(
  id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL REFERENCES clients(id),
  source TEXT NOT NULL, channel_ref TEXT, title TEXT, message TEXT, page TEXT, item_url TEXT,
  status TEXT NOT NULL DEFAULT 'new', reason TEXT, amount INTEGER,
  assignee_id INTEGER REFERENCES users(id), unread INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
  first_touch_at INTEGER, closed_at INTEGER
);
CREATE INDEX leads_status ON leads(status, updated_at);
CREATE INDEX leads_channel ON leads(channel_ref);
CREATE INDEX leads_client ON leads(client_id);
CREATE TABLE events(
  id INTEGER PRIMARY KEY, lead_id INTEGER REFERENCES leads(id), client_id INTEGER,
  user_id INTEGER REFERENCES users(id), type TEXT NOT NULL, text TEXT, meta TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX events_lead ON events(lead_id, created_at);
CREATE INDEX events_user ON events(user_id, created_at);
CREATE TABLE login_attempts(ip TEXT, ok INTEGER, ts INTEGER);
CREATE INDEX login_attempts_ts ON login_attempts(ts);
PRAGMA user_version = 1;
SQL);
    }
    if ($v < 2) {
        // ext_id — id сообщения Авито: вебхук и сверка пишут одно сообщение один раз
        $db->exec(<<<SQL
ALTER TABLE events ADD COLUMN ext_id TEXT;
CREATE UNIQUE INDEX events_ext ON events(ext_id) WHERE ext_id IS NOT NULL;
CREATE TABLE kv(k TEXT PRIMARY KEY, v TEXT);
PRAGMA user_version = 2;
SQL);
    }
    if ($v < 3) {
        // ответственный больше не подставляется сам при действии — снимаем
        // такие автоназначения (у них нет события «назначен»), выбранные вручную остаются
        $db->exec(<<<SQL
UPDATE leads SET assignee_id = NULL WHERE assignee_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM events e WHERE e.lead_id = leads.id AND e.type = 'assign');
PRAGMA user_version = 3;
SQL);
    }
    if ($v < 4) {
        // учётка разработчика подписана студией, а не личным именем (решение Adrian 21.10.2026)
        $db->exec(<<<SQL
UPDATE users SET name = 'FutureFlow' WHERE role = 'dev' AND name = 'Adrian';
PRAGMA user_version = 4;
SQL);
    }
}

function crm_kv(string $k, ?string $set = null): ?string
{
    if ($set !== null) {
        crm_db()->prepare('INSERT INTO kv(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $set]);
        return $set;
    }
    $st = crm_db()->prepare('SELECT v FROM kv WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

/**
 * Запись в историю: кто (null — система/клиент), что, по какой заявке.
 * $extId — id сообщения во внешней системе («avito:<id>»): повтор не пишется.
 * Возвращает true, если запись добавлена.
 */
function crm_event(?int $leadId, ?int $clientId, ?int $userId, string $type, string $text = '', array $meta = [],
                   ?string $extId = null, ?int $at = null): bool
{
    $st = crm_db()->prepare('INSERT OR IGNORE INTO events(lead_id, client_id, user_id, type, text, meta, ext_id, created_at) VALUES(?,?,?,?,?,?,?,?)');
    $st->execute([$leadId, $clientId, $userId, $type, $text, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, $extId, $at ?? time()]);
    return $st->rowCount() > 0;
}

/** Телефон к виду 7XXXXXXXXXX; null, если на телефон не похоже. */
function crm_phone(?string $raw): ?string
{
    $d = preg_replace('/\D+/', '', (string)$raw) ?? '';
    if (strlen($d) === 10) {
        $d = '7' . $d;
    } elseif (strlen($d) === 11 && $d[0] === '8') {
        $d = '7' . substr($d, 1);
    }
    return preg_match('/^7\d{10}$/', $d) ? $d : null;
}

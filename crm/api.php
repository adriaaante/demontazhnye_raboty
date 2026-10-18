<?php
/**
 * API CRM «Под Ноль»: один адрес, действие в ?a=…, ответ — JSON.
 * Изменения — только POST с заголовком X-Requested-With: crm (вместе с
 * SameSite=Strict это защита от подделки запросов с чужих сайтов).
 * Каждое действие с заявкой пишется в историю (events) с автором и временем.
 */

declare(strict_types=1);

require __DIR__ . '/lib/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    out(['ok' => false, 'error' => $msg], $code);
}

function site_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = dirname(__DIR__) . '/api/config.php';
        $cfg = is_readable($file) ? (require $file) : [];
        $cfg = is_array($cfg) ? $cfg : [];
    }
    return $cfg;
}

/** Ключ из токена бота сайта: первичная настройка, выгрузка, сверка с Авито. */
function site_key(string $purpose): string
{
    $token = (string)(site_config()['token'] ?? '');
    return $token === '' ? '' : substr(hash('sha256', 'crm-' . $purpose . '|' . $token), 0, 32);
}

/** Сверка с Авито; full — вся история, иначе с прошлой сверки (запас час). */
function run_avito_sync(bool $full): array
{
    require_once __DIR__ . '/lib/avito_sync.php';
    @set_time_limit(180);
    $since = $full ? 0 : max(0, (int)(crm_kv('avito_sync_at') ?? 0) - 3600);
    $startedAt = time();
    $r = crm_avito_sync(site_config(), $since);
    crm_kv('avito_sync_at', (string)$startedAt);
    return $r;
}

$a = (string)($_GET['a'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$in = [];
if ($isPost) {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'crm') {
        fail('forbidden', 403);
    }
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
}
$str = static fn(string $k, int $max = 2000): string => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);

try {
    $db = crm_db();

    // --- без входа ---------------------------------------------------------
    if ($a === 'ping') {
        $n = (int)$db->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn();
        // store: где лежит база — 'outside' (над public_html, как задумано) или 'fallback'
        out(['ok' => true, 'users' => $n > 0, 'store' => str_starts_with(crm_data_dir(), __DIR__) ? 'fallback' : 'outside']);
    }
    if ($a === 'bootstrap' && $isPost) {
        // первый разработчик — только пока пользователей нет, по ключу из токена бота
        $key = site_key('setup');
        if ($key === '' || !hash_equals($key, $str('key'))) {
            fail('forbidden', 403);
        }
        if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            fail('already_set_up', 409);
        }
        $pin = crm_new_pin();
        $db->prepare('INSERT INTO users(name, role, pin_hash, created_at) VALUES(?, \'dev\', ?, ?)')
            ->execute([$str('name', 60) ?: 'Разработчик', crm_pin_hash($pin), time()]);
        out(['ok' => true, 'pin' => $pin]);
    }
    if ($a === 'export' && !$isPost && isset($_GET['key'])) {
        $key = site_key('export');
        if ($key === '' || !hash_equals($key, (string)$_GET['key'])) {
            fail('forbidden', 403);
        }
        out(export_all($db));
    }
    if ($a === 'avito_sync' && !$isPost && isset($_GET['key'])) {
        // по расписанию (crm-sync.yml): ключ из токена бота
        $key = site_key('sync');
        if ($key === '' || !hash_equals($key, (string)$_GET['key'])) {
            fail('forbidden', 403);
        }
        out(['ok' => true] + run_avito_sync(($_GET['full'] ?? '') === '1'));
    }
    if ($a === 'login' && $isPost) {
        try {
            $u = crm_login($str('pin', 6));
        } catch (RuntimeException $e) {
            fail($e->getMessage(), 401);
        }
        out(['ok' => true, 'user' => public_user($u)]);
    }

    // --- дальше только с входом --------------------------------------------
    $me = crm_current_user();
    if (!$me) {
        fail('auth', 401);
    }
    $meId = (int)$me['id'];
    // временный PIN: пока не сменён, доступны только смена PIN, профиль и выход
    if ((int)$me['must_change'] === 1 && !in_array($a, ['me', 'my_pin', 'logout'], true)) {
        fail('must_change', 403);
    }

    switch ($a) {
        case 'logout':
            crm_logout();
            out(['ok' => true]);

        case 'me':
            out(['ok' => true, 'user' => public_user($me), 'dict' => [
                'statuses' => CRM_STATUSES, 'reasons' => CRM_LOST_REASONS, 'roles' => CRM_ROLES,
            ], 'users' => team($db)]);

        case 'leads':
            out(['ok' => true] + leads_list($db, $meId, (string)($_GET['tab'] ?? 'open'), trim((string)($_GET['q'] ?? ''))));

        case 'lead':
            out(['ok' => true] + lead_full($db, (int)($_GET['id'] ?? 0)));

        case 'lead_status':
            $lead = lead_row($db, (int)($in['id'] ?? 0));
            $status = $str('status', 20);
            if (!isset(CRM_STATUSES[$status])) {
                fail('Неизвестный статус');
            }
            $reason = $str('reason', 200);
            $comment = $str('comment');
            if ($status === 'lost' && $reason === '') {
                fail('Укажите причину отказа');
            }
            if ($status === $lead['status']) {
                fail('Статус уже «' . CRM_STATUSES[$status] . '»');
            }
            // кто-то успел поменять статус, пока окно было открыто, — не перезаписываем молча
            $from = $str('from', 20);
            if ($from !== '' && $from !== $lead['status']) {
                $st = $db->prepare("SELECT u.name FROM events e LEFT JOIN users u ON u.id = e.user_id
                                    WHERE e.lead_id = ? AND e.type = 'status' ORDER BY e.id DESC LIMIT 1");
                $st->execute([$lead['id']]);
                fail('Статус уже изменил(а) ' . ($st->fetchColumn() ?: 'другой сотрудник') . ': сейчас «'
                    . CRM_STATUSES[$lead['status']] . '». Обновите карточку.', 409);
            }
            $amount = isset($in['amount']) && $in['amount'] !== '' ? max(0, (int)$in['amount']) : null;
            $now = time();
            $db->prepare('UPDATE leads SET status = ?, reason = ?, amount = COALESCE(?, amount), updated_at = ?,
                          closed_at = ?, first_touch_at = COALESCE(first_touch_at, ?) WHERE id = ?')
                ->execute([$status, $status === 'lost' ? $reason : null, $amount, $now,
                    in_array($status, CRM_CLOSED, true) ? $now : null, $now, $lead['id']]);
            crm_event((int)$lead['id'], (int)$lead['client_id'], $meId, 'status', $comment,
                ['from' => $lead['status'], 'to' => $status, 'reason' => $status === 'lost' ? $reason : null, 'amount' => $amount]);
            out(['ok' => true]);

        case 'lead_comment':
            $lead = lead_row($db, (int)($in['id'] ?? 0));
            $text = $str('text');
            if ($text === '') {
                fail('Пустой комментарий');
            }
            touch_lead($db, $lead, $meId);
            crm_event((int)$lead['id'], (int)$lead['client_id'], $meId, 'comment', $text);
            out(['ok' => true]);

        case 'lead_assign':
            $lead = lead_row($db, (int)($in['id'] ?? 0));
            $uid = isset($in['user_id']) && $in['user_id'] !== null ? (int)$in['user_id'] : null;
            if ($uid !== null) {
                $st = $db->prepare('SELECT name FROM users WHERE id = ? AND active = 1');
                $st->execute([$uid]);
                if (!$st->fetchColumn()) {
                    fail('Сотрудник не найден');
                }
            }
            $db->prepare('UPDATE leads SET assignee_id = ?, updated_at = ? WHERE id = ?')
                ->execute([$uid, time(), $lead['id']]);
            crm_event((int)$lead['id'], (int)$lead['client_id'], $meId, 'assign', '', ['to' => $uid]);
            out(['ok' => true]);

        case 'lead_edit':
            $lead = lead_row($db, (int)($in['id'] ?? 0));
            $name = $str('name', 80);
            $phoneRaw = $str('phone', 40);
            $phone = $phoneRaw === '' ? null : crm_phone($phoneRaw);
            if ($phoneRaw !== '' && !$phone) {
                fail('Телефон не похож на российский номер');
            }
            if ($phone) {
                $st = $db->prepare('SELECT id FROM clients WHERE phone = ? AND id <> ?');
                $st->execute([$phone, $lead['client_id']]);
                if ($other = $st->fetchColumn()) {
                    fail('Этот телефон уже есть у другого клиента (клиент №' . $other . ')');
                }
            }
            $st = $db->prepare('SELECT name, phone FROM clients WHERE id = ?');
            $st->execute([$lead['client_id']]);
            $old = $st->fetch();
            $db->prepare('UPDATE clients SET name = ?, phone = ?, updated_at = ? WHERE id = ?')
                ->execute([$name !== '' ? $name : null, $phone, time(), $lead['client_id']]);
            $title = $str('title', 200);
            $db->prepare('UPDATE leads SET title = ?, updated_at = ? WHERE id = ?')->execute([$title, time(), $lead['id']]);
            crm_event((int)$lead['id'], (int)$lead['client_id'], $meId, 'edit', '', [
                'name' => [$old['name'], $name], 'phone' => [$old['phone'], $phone], 'title' => [$lead['title'], $title],
            ]);
            out(['ok' => true]);

        case 'lead_create':
            // заявка, пришедшая по телефону или лично — заводит сотрудник
            $phoneRaw = $str('phone', 40);
            $phone = $phoneRaw === '' ? null : crm_phone($phoneRaw);
            if ($phoneRaw !== '' && !$phone) {
                fail('Телефон не похож на российский номер');
            }
            $name = $str('name', 80);
            if ($name === '' && !$phone) {
                fail('Укажите имя или телефон');
            }
            require_once __DIR__ . '/lib/ingest.php';
            $db->beginTransaction();
            $clientId = crm_client($phone, null, $name);
            $now = time();
            $db->prepare('INSERT INTO leads(client_id, source, title, message, status, unread, created_at, updated_at, first_touch_at)
                          VALUES(?, \'manual\', ?, ?, \'new\', 0, ?, ?, ?)')
                ->execute([$clientId, $str('title', 200), $str('message'), $now, $now, $now]);
            $id = (int)$db->lastInsertId();
            crm_event($id, $clientId, $meId, 'created', $str('message'), ['source' => 'manual', 'channel' => $str('channel', 40)]);
            $db->commit();
            out(['ok' => true, 'id' => $id]);

        case 'users':
            if ($me['role'] === 'manager') {
                fail('Нет доступа', 403);
            }
            $rows = $db->query('SELECT id, name, role, active, created_at, last_login FROM users WHERE active = 1 ORDER BY role, name')->fetchAll();
            foreach ($rows as &$r) {
                $r['can_manage'] = crm_can_manage($me, $r['role']) && (int)$r['id'] !== $meId;
            }
            out(['ok' => true, 'users' => $rows]);

        case 'user_create':
            $role = $str('role', 10);
            $name = $str('name', 60);
            if (!isset(CRM_ROLES[$role]) || $name === '') {
                fail('Укажите имя и роль');
            }
            if (!crm_can_manage($me, $role)) {
                fail('Нет прав создавать эту роль', 403);
            }
            try {
                $pin = crm_pick_pin($str('pin', 6));
            } catch (InvalidArgumentException $e) {
                fail($e->getMessage());
            }
            $db->prepare('INSERT INTO users(name, role, pin_hash, created_at, created_by) VALUES(?,?,?,?,?)')
                ->execute([$name, $role, crm_pin_hash($pin), time(), $meId]);
            $id = (int)$db->lastInsertId();
            crm_event(null, null, $meId, 'user_create', $name, ['user' => $id, 'role' => $role]);
            out(['ok' => true, 'id' => $id, 'pin' => $pin]);

        case 'user_pin_reset':
        case 'user_delete':
            $st = $db->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
            $st->execute([(int)($in['id'] ?? 0)]);
            $u = $st->fetch();
            if (!$u || (int)$u['id'] === $meId || !crm_can_manage($me, $u['role'])) {
                fail('Нет прав на этого пользователя', 403);
            }
            $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$u['id']]);
            if ($a === 'user_delete') {
                $db->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute([$u['id']]);
                $db->prepare('UPDATE leads SET assignee_id = NULL WHERE assignee_id = ? AND status NOT IN (\'done\',\'lost\')')->execute([$u['id']]);
                crm_event(null, null, $meId, 'user_delete', $u['name'], ['user' => (int)$u['id']]);
                out(['ok' => true]);
            }
            try {
                $pin = crm_pick_pin($str('pin', 6), (int)$u['id']);
            } catch (InvalidArgumentException $e) {
                fail($e->getMessage());
            }
            $db->prepare('UPDATE users SET pin_hash = ?, must_change = 1 WHERE id = ?')->execute([crm_pin_hash($pin), $u['id']]);
            crm_event(null, null, $meId, 'user_pin_reset', $u['name'], ['user' => (int)$u['id']]);
            out(['ok' => true, 'pin' => $pin]);

        case 'my_pin':
            $new = $str('pin', 6);
            if (crm_pin_weak($new)) {
                fail('PIN — 6 цифр, не одинаковые и не подряд');
            }
            if (crm_pin_taken($new, $meId)) {
                fail('Такой PIN уже занят, выберите другой');
            }
            $db->prepare('UPDATE users SET pin_hash = ?, must_change = 0 WHERE id = ?')->execute([crm_pin_hash($new), $meId]);
            crm_event(null, null, $meId, 'my_pin');
            out(['ok' => true]);

        case 'stats':
            if ($me['role'] === 'manager') {
                fail('Нет доступа', 403);
            }
            out(['ok' => true] + stats($db, max(1, min(365, (int)($_GET['days'] ?? 30)))));

        case 'avito_sync':
            if ($me['role'] === 'manager') {
                fail('Нет доступа', 403);
            }
            out(['ok' => true] + run_avito_sync(false));

        case 'export':
            if ($me['role'] !== 'dev') {
                fail('Нет доступа', 403);
            }
            out(export_all($db));
    }
    fail('unknown_action', 404);
} catch (Throwable $e) {
    error_log('CRM: ' . $e->getMessage());
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fail('Ошибка сервера, попробуйте ещё раз', 500);
}

// --------------------------------------------------------------------------

function public_user(array $u): array
{
    return ['id' => (int)$u['id'], 'name' => $u['name'], 'role' => $u['role'], 'must_change' => (int)$u['must_change'] === 1];
}

function team(PDO $db): array
{
    return $db->query('SELECT id, name, role FROM users WHERE active = 1 ORDER BY name')->fetchAll();
}

function lead_row(PDO $db, int $id): array
{
    $st = $db->prepare('SELECT * FROM leads WHERE id = ?');
    $st->execute([$id]);
    $l = $st->fetch();
    if (!$l) {
        fail('Заявка не найдена', 404);
    }
    return $l;
}

/** Первое действие сотрудника по заявке (для скорости реакции). Ответственного
 *  не подставляем — его выбирают явно. */
function touch_lead(PDO $db, array $lead, int $userId): void
{
    $now = time();
    $db->prepare('UPDATE leads SET updated_at = ?, first_touch_at = COALESCE(first_touch_at, ?) WHERE id = ?')
        ->execute([$now, $now, $lead['id']]);
}

function leads_list(PDO $db, int $meId, string $tab, string $q): array
{
    $where = [];
    $args = [];
    switch ($tab) {
        case 'new':
            $where[] = "l.status = 'new'";
            break;
        case 'mine':
            $where[] = "l.assignee_id = ? AND l.status NOT IN ('done','lost')";
            $args[] = $meId;
            break;
        case 'closed':
            $where[] = "l.status IN ('done','lost')";
            break;
        case 'all':
            break;
        default:
            $where[] = "l.status NOT IN ('done','lost')";
    }
    if ($q !== '') {
        $where[] = '(c.name LIKE ? OR c.phone LIKE ? OR l.title LIKE ? OR l.message LIKE ? OR l.id = ?)';
        $like = '%' . $q . '%';
        $digits = preg_replace('/\D+/', '', $q);
        array_push($args, $like, $digits !== '' ? '%' . $digits . '%' : $like, $like, $like, (int)$q);
    }
    $sql = 'SELECT l.id, l.source, l.title, l.message, l.status, l.reason, l.amount, l.unread, l.created_at, l.updated_at,
                   l.first_touch_at, l.assignee_id, c.name AS client_name, c.phone AS client_phone,
                   (SELECT text FROM events e WHERE e.lead_id = l.id AND e.type IN (\'in\',\'comment\',\'out\',\'created\') ORDER BY e.id DESC LIMIT 1) AS last_text
            FROM leads l JOIN clients c ON c.id = l.client_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY l.unread DESC, l.updated_at DESC LIMIT 300';
    $st = $db->prepare($sql);
    $st->execute($args);
    $counts = $db->prepare("SELECT
        SUM(status = 'new') AS new, SUM(status NOT IN ('done','lost')) AS open,
        SUM(assignee_id = ? AND status NOT IN ('done','lost')) AS mine,
        SUM(status IN ('done','lost')) AS closed, COUNT(*) AS all_, SUM(unread) AS unread FROM leads");
    $counts->execute([$meId]);
    return ['leads' => $st->fetchAll(), 'counts' => $counts->fetch()];
}

function lead_full(PDO $db, int $id): array
{
    $lead = lead_row($db, $id);
    $db->prepare('UPDATE leads SET unread = 0 WHERE id = ?')->execute([$id]);
    $lead['unread'] = 0;
    $st = $db->prepare('SELECT * FROM clients WHERE id = ?');
    $st->execute([$lead['client_id']]);
    $client = $st->fetch();
    $st = $db->prepare('SELECT e.id, e.type, e.text, e.meta, e.created_at, e.user_id, u.name AS user_name
                        FROM events e LEFT JOIN users u ON u.id = e.user_id WHERE e.lead_id = ? ORDER BY e.id');
    $st->execute([$id]);
    $events = $st->fetchAll();
    foreach ($events as &$e) {
        $e['meta'] = $e['meta'] ? json_decode($e['meta'], true) : null;
    }
    $st = $db->prepare('SELECT id, source, title, status, created_at FROM leads WHERE client_id = ? AND id <> ? ORDER BY id DESC');
    $st->execute([$lead['client_id'], $id]);
    return ['lead' => $lead, 'client' => $client, 'events' => $events, 'other' => $st->fetchAll()];
}

function median(array $xs): ?float
{
    if (!$xs) {
        return null;
    }
    sort($xs);
    $n = count($xs);
    return $n % 2 ? (float)$xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
}

function stats(PDO $db, int $days): array
{
    $from = time() - $days * 86400;
    $st = $db->prepare('SELECT * FROM leads WHERE created_at >= ?');
    $st->execute([$from]);
    $leads = $st->fetchAll();

    $bySource = $byStatus = $reasons = $byTitle = [];
    $touch = [];
    $late = 0;
    $won = 0;
    $revenue = 0;
    foreach ($leads as $l) {
        $bySource[$l['source']] = ($bySource[$l['source']] ?? 0) + 1;
        $byStatus[$l['status']] = ($byStatus[$l['status']] ?? 0) + 1;
        $t = trim((string)$l['title']) ?: 'без темы';
        $byTitle[$t] ??= ['total' => 0, 'won' => 0];
        $byTitle[$t]['total']++;
        if ($l['status'] === 'lost') {
            $reasons[$l['reason'] ?: 'не указана'] = ($reasons[$l['reason'] ?: 'не указана'] ?? 0) + 1;
        }
        if (in_array($l['status'], ['deal', 'done'], true)) {
            $won++;
            $byTitle[$t]['won']++;
            $revenue += (int)$l['amount'];
        }
        if ($l['first_touch_at']) {
            $touch[] = ($l['first_touch_at'] - $l['created_at']) / 60;
        } elseif ($l['status'] === 'new' && $l['created_at'] < time() - 1800) {
            $late++;
        }
    }
    arsort($reasons);
    uasort($byTitle, fn($a, $b) => $b['total'] <=> $a['total']);

    // работа сотрудников: действия за период и итоги по их заявкам
    $people = [];
    foreach (team($db) as $u) {
        $people[(int)$u['id']] = ['name' => $u['name'], 'role' => $u['role'], 'status' => 0, 'comment' => 0,
            'leads' => 0, 'won' => 0, 'lost' => 0, 'touch' => []];
    }
    $st = $db->prepare("SELECT user_id, type, COUNT(*) n FROM events WHERE created_at >= ? AND user_id IS NOT NULL
                        AND type IN ('status','comment') GROUP BY user_id, type");
    $st->execute([$from]);
    foreach ($st as $r) {
        if (isset($people[(int)$r['user_id']])) {
            $people[(int)$r['user_id']][$r['type']] = (int)$r['n'];
        }
    }
    foreach ($leads as $l) {
        $uid = (int)$l['assignee_id'];
        if (!isset($people[$uid])) {
            continue;
        }
        $people[$uid]['leads']++;
        if (in_array($l['status'], ['deal', 'done'], true)) {
            $people[$uid]['won']++;
        }
        if ($l['status'] === 'lost') {
            $people[$uid]['lost']++;
        }
        if ($l['first_touch_at']) {
            $people[$uid]['touch'][] = ($l['first_touch_at'] - $l['created_at']) / 60;
        }
    }
    foreach ($people as &$p) {
        $p['touch_median'] = median($p['touch']);
        unset($p['touch']);
    }

    return [
        'days' => $days, 'total' => count($leads), 'won' => $won, 'revenue' => $revenue,
        'by_source' => $bySource, 'by_status' => $byStatus, 'reasons' => $reasons,
        'by_title' => array_slice($byTitle, 0, 12, true),
        'touch_median' => median($touch), 'late' => $late, 'people' => array_values($people),
    ];
}

function export_all(PDO $db): array
{
    $events = $db->query('SELECT * FROM events ORDER BY id')->fetchAll();
    foreach ($events as &$e) {
        $e['meta'] = $e['meta'] ? json_decode($e['meta'], true) : null;
    }
    return [
        'ok' => true, 'exported_at' => time(),
        'users' => $db->query('SELECT id, name, role, active, created_at, created_by, last_login FROM users')->fetchAll(),
        'clients' => $db->query('SELECT * FROM clients')->fetchAll(),
        'leads' => $db->query('SELECT * FROM leads')->fetchAll(),
        'events' => $events,
        'dict' => ['statuses' => CRM_STATUSES, 'reasons' => CRM_LOST_REASONS],
    ];
}

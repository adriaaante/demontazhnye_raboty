<?php
/**
 * Сверка CRM с мессенджером Авито через API: переносит переписку по НАШИМ
 * объявлениям (чужие чаты — личные покупки — пропускаем), которой ещё нет в CRM.
 * Первый запуск с $since = 0 переносит всю историю; дальше — чаты, обновлённые
 * после прошлой сверки (с запасом в час). Сообщение пишется один раз: ext_id
 * «avito:<id>» общий с вебхуком (crm_ingest_avito), так что сверка и вебхук
 * друг друга не задваивают.
 *
 * Новая заявка из истории: создана — первым сообщением клиента, статус —
 * «Связались», если мы уже отвечали, иначе «Новая»; «непрочитанная», если
 * последнее слово за клиентом.
 */

declare(strict_types=1);

require_once __DIR__ . '/ingest.php';

const CRM_AVITO_USER = 429115960;   // аккаунт «Под Ноль»

/** GET/POST к API Авито с повтором на 429 (Авито часто ограничивает частоту). */
function crm_avito_http(string $url, ?array $post = null, ?string $token = null): ?array
{
    for ($i = 0; $i < 5; $i++) {
        $ch = curl_init($url);
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15];
        if ($token) {
            $opt[CURLOPT_HTTPHEADER] = ['Authorization: Bearer ' . $token];
        }
        if ($post !== null) {
            $opt[CURLOPT_POST] = true;
            $opt[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opt);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($code === 429 || $body === false) {
            sleep(2 + $i * 2);
            continue;
        }
        $data = is_string($body) ? json_decode($body, true) : null;
        return is_array($data) ? $data : null;
    }
    return null;
}

/**
 * @return array{chats:int, leads_new:int, messages:int}
 */
function crm_avito_sync(array $config, int $since): array
{
    if (empty($config['avito_client_id']) || empty($config['avito_client_secret'])) {
        throw new RuntimeException('нет ключей Авито в конфиге');
    }
    $tok = crm_avito_http('https://api.avito.ru/token/', [
        'grant_type' => 'client_credentials',
        'client_id' => $config['avito_client_id'],
        'client_secret' => $config['avito_client_secret'],
    ]);
    $token = (string)($tok['access_token'] ?? '');
    if ($token === '') {
        throw new RuntimeException('Авито не выдал токен');
    }
    $base = 'https://api.avito.ru/messenger';
    $U = CRM_AVITO_USER;
    $db = crm_db();
    $stat = ['chats' => 0, 'leads_new' => 0, 'messages' => 0];

    for ($offset = 0; $offset < 1000; $offset += 100) {
        $page = crm_avito_http("$base/v2/accounts/$U/chats?limit=100&offset=$offset", null, $token);
        if ($page === null) {
            throw new RuntimeException('Авито не отдал список чатов');
        }
        $chats = $page['chats'] ?? [];
        $older = 0;
        foreach ($chats as $chat) {
            if ((int)($chat['updated'] ?? 0) < $since) {
                $older++;
                continue;
            }
            $item = $chat['context']['value'] ?? [];
            if ((int)($item['user_id'] ?? 0) !== $U) {
                continue;   // чужое объявление — переписка как покупателя
            }
            $stat['chats']++;
            $r = crm_avito_sync_chat($db, $token, $chat);
            $stat['leads_new'] += $r[0];
            $stat['messages'] += $r[1];
        }
        // чаты идут от свежих к старым: вся страница старше отметки — дальше смотреть незачем
        if (count($chats) < 100 || ($since > 0 && $older === count($chats))) {
            break;
        }
    }
    return $stat;
}

/** @return array{0:int,1:int} [новых заявок, новых сообщений] */
function crm_avito_sync_chat(PDO $db, string $token, array $chat): array
{
    $U = CRM_AVITO_USER;
    $chatId = (string)$chat['id'];
    $msgs = [];
    for ($offset = 0; $offset < 2000; $offset += 100) {
        $page = crm_avito_http("https://api.avito.ru/messenger/v3/accounts/$U/chats/" . rawurlencode($chatId) . "/messages/?limit=100&offset=$offset", null, $token);
        $m = $page['messages'] ?? [];
        $msgs = array_merge($msgs, $m);
        if (count($m) < 100) {
            break;
        }
    }
    // только живые сообщения людей, по времени
    $msgs = array_values(array_filter($msgs, static fn($m) => ($m['type'] ?? '') !== 'system' && ($m['type'] ?? '') !== 'deleted' && (int)($m['author_id'] ?? 0) !== 0));
    usort($msgs, static fn($a, $b) => ($a['created'] ?? 0) <=> ($b['created'] ?? 0));
    $firstClient = null;
    foreach ($msgs as $m) {
        if ((int)$m['author_id'] !== $U) {
            $firstClient = $m;
            break;
        }
    }
    if (!$firstClient) {
        return [0, 0];   // клиент ничего не написал — заявки нет
    }

    $labels = ['image' => '[фото]', 'link' => '[ссылка]', 'item' => '[объявление]', 'location' => '[геопозиция]',
        'call' => '[звонок]', 'voice' => '[голосовое сообщение]', 'file' => '[файл]', 'video' => '[видео]'];
    $text = static function (array $m) use ($labels): string {
        $t = trim((string)($m['content']['text'] ?? ''));
        return $t !== '' ? $t : ($labels[$m['type'] ?? ''] ?? '[' . ($m['type'] ?? 'сообщение') . ']');
    };

    $item = $chat['context']['value'] ?? [];
    $clientUser = null;
    foreach ($chat['users'] ?? [] as $u) {
        if ((int)($u['id'] ?? 0) !== $U) {
            $clientUser = $u;
        }
    }
    $url = (string)preg_replace('~^https?://(m\.)?avito\.ru/~', 'https://www.avito.ru/', trim((string)($item['url'] ?? '')));

    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT * FROM leads WHERE channel_ref = ? ORDER BY id DESC LIMIT 1');
        $st->execute(['avito:' . $chatId]);
        $lead = $st->fetch() ?: null;
        $newLead = 0;
        if (!$lead) {
            $replied = null;
            foreach ($msgs as $m) {
                if ((int)$m['author_id'] === $U && $m['created'] >= $firstClient['created']) {
                    $replied = (int)$m['created'];
                    break;
                }
            }
            $last = end($msgs);
            $clientId = crm_client(null, (int)$firstClient['author_id'], trim((string)($clientUser['name'] ?? '')));
            $created = (int)$firstClient['created'];
            $db->prepare('INSERT INTO leads(client_id, source, channel_ref, title, message, item_url, status, unread, created_at, updated_at, first_touch_at)
                          VALUES(?, \'avito\', ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$clientId, 'avito:' . $chatId, (string)($item['title'] ?? ''), $text($firstClient), $url,
                    $replied ? 'contact' : 'new', (int)$last['author_id'] !== $U ? 1 : 0, $created, (int)$last['created'], $replied]);
            $lead = ['id' => (int)$db->lastInsertId(), 'client_id' => $clientId, 'first_touch_at' => $replied];
            crm_event((int)$lead['id'], $clientId, null, 'created', $text($firstClient), [
                'source' => 'avito', 'item' => $item['title'] ?? '', 'price' => $item['price_string'] ?? '',
                'chat' => 'https://www.avito.ru/profile/messenger/channel/' . $chatId, 'imported' => true,
            ], 'avito:' . $firstClient['id'], $created);
            $newLead = 1;
        }
        $added = 0;
        $lastIn = 0;
        $firstOut = null;
        foreach ($msgs as $m) {
            $mine = (int)$m['author_id'] === $U;
            $ok = crm_event((int)$lead['id'], (int)$lead['client_id'], null, $mine ? 'out' : 'in', $text($m),
                ['via' => 'avito'], 'avito:' . $m['id'], (int)$m['created']);
            if ($ok) {
                $added++;
                if ($mine) {
                    $firstOut ??= (int)$m['created'];
                } else {
                    $lastIn = max($lastIn, (int)$m['created']);
                }
            }
        }
        if (!$newLead && $added) {
            // догнали пропущенное вебхуком: новое от клиента — заявка снова «непрочитанная»
            $db->prepare('UPDATE leads SET updated_at = MAX(updated_at, ?), unread = CASE WHEN ? > 0 THEN 1 ELSE unread END,
                          first_touch_at = COALESCE(first_touch_at, ?) WHERE id = ?')
                ->execute([max($lastIn, $firstOut ?? 0), $lastIn, $firstOut, $lead['id']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return [$newLead, $added];
}

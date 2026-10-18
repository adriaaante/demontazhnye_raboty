<?php
/**
 * Приём заявок в CRM из api/lead.php (форма сайта) и api/avito.php (мост Авито).
 * Вызывающий код оборачивает вызов в try/catch: сбой CRM не должен мешать
 * отправке заявки в Telegram. Возвращает ссылку на карточку для сообщения.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function crm_lead_url(int $leadId): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'xn--d1aofccc0h.xn--p1ai');
    return 'https://' . $host . '/crm/#/lead/' . $leadId;
}

/** Клиент по телефону или аккаунту Авито; новый — если не нашли. */
function crm_client(?string $phone, ?int $avitoId, string $name): int
{
    $db = crm_db();
    $now = time();
    $row = null;
    if ($avitoId) {
        $st = $db->prepare('SELECT * FROM clients WHERE avito_user_id = ?');
        $st->execute([$avitoId]);
        $row = $st->fetch() ?: null;
    }
    if (!$row && $phone) {
        $st = $db->prepare('SELECT * FROM clients WHERE phone = ?');
        $st->execute([$phone]);
        $row = $st->fetch() ?: null;
    }
    if (!$row) {
        $db->prepare('INSERT INTO clients(name, phone, avito_user_id, created_at, updated_at) VALUES(?,?,?,?,?)')
            ->execute([$name !== '' ? $name : null, $phone, $avitoId, $now, $now]);
        return (int)$db->lastInsertId();
    }
    // дополняем то, чего не было: телефон из Авито, имя. Номер, который уже
    // записан у другого клиента, не трогаем — уникальность по телефону.
    if ($phone) {
        $st = $db->prepare('SELECT 1 FROM clients WHERE phone = ? AND id <> ?');
        $st->execute([$phone, $row['id']]);
        if ($st->fetchColumn()) {
            $phone = null;
        }
    }
    $db->prepare('UPDATE clients SET name = COALESCE(NULLIF(name, \'\'), ?), phone = COALESCE(phone, ?),
                  avito_user_id = COALESCE(avito_user_id, ?), updated_at = ? WHERE id = ?')
        ->execute([$name !== '' ? $name : null, $phone, $avitoId, $now, $row['id']]);
    return (int)$row['id'];
}

/** Заявка с формы сайта — всегда новая заявка клиента. */
function crm_ingest_site_lead(array $f): string
{
    $phone = crm_phone($f['phone'] ?? '');
    $db = crm_db();
    $db->beginTransaction();
    try {
        // другой клиент уже с этим телефоном? — тот же человек
        $clientId = crm_client($phone, null, (string)($f['name'] ?? ''));
        $now = time();
        $db->prepare('INSERT INTO leads(client_id, source, title, message, page, status, created_at, updated_at)
                      VALUES(?, \'site\', ?, ?, ?, \'new\', ?, ?)')
            ->execute([$clientId, (string)($f['source'] ?? ''), (string)($f['message'] ?? ''), (string)($f['page'] ?? ''), $now, $now]);
        $leadId = (int)$db->lastInsertId();
        crm_event($leadId, $clientId, null, 'created', (string)($f['message'] ?? ''), [
            'source' => 'site', 'form' => $f['source'] ?? '', 'page' => $f['page'] ?? '',
            'name' => $f['name'] ?? '', 'phone' => $f['phone'] ?? '',
        ]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return crm_lead_url($leadId);
}

/**
 * Сообщение в Авито. $incoming — от клиента (новая заявка или продолжение),
 * иначе — ответ сотрудника из Авито (фиксируем, если чат уже есть в CRM).
 * Возвращает ссылку на карточку или '' (ответ по неизвестному чату).
 */
function crm_ingest_avito(array $m, bool $incoming): string
{
    $chatId = (string)($m['chat_id'] ?? '');
    if ($chatId === '') {
        return '';
    }
    $db = crm_db();
    $now = time();
    $st = $db->prepare('SELECT * FROM leads WHERE channel_ref = ? ORDER BY id DESC LIMIT 1');
    $st->execute(['avito:' . $chatId]);
    $lead = $st->fetch() ?: null;

    if (!$incoming) {
        if (!$lead) {
            return '';
        }
        crm_event((int)$lead['id'], (int)$lead['client_id'], null, 'out', (string)($m['text'] ?? ''), ['via' => 'avito']);
        $db->prepare('UPDATE leads SET first_touch_at = COALESCE(first_touch_at, ?), updated_at = ? WHERE id = ?')
            ->execute([$now, $now, $lead['id']]);
        return crm_lead_url((int)$lead['id']);
    }

    $text = (string)($m['text'] ?? '');
    // Телефон, который клиент сам написал в чате, — в карточку клиента
    $phone = null;
    if (preg_match('/(?:\+7|8)?[\s(-]*9\d{2}[\s)-]*\d{3}[\s-]*\d{2}[\s-]*\d{2}/u', $text, $pm)) {
        $phone = crm_phone($pm[0]);
    }
    $db->beginTransaction();
    try {
        // продолжение переписки: та же заявка, если чат был за последние 30 дней
        if ($lead && (int)$lead['updated_at'] > $now - 30 * 86400) {
            $leadId = (int)$lead['id'];
            $clientId = (int)$lead['client_id'];
            if ($phone) {
                $db->prepare('UPDATE clients SET phone = COALESCE(phone, ?), updated_at = ? WHERE id = ?
                              AND NOT EXISTS (SELECT 1 FROM clients WHERE phone = ? AND id <> ?)')
                    ->execute([$phone, $now, $clientId, $phone, $clientId]);
            }
            $db->prepare('UPDATE leads SET unread = 1, updated_at = ? WHERE id = ?')->execute([$now, $leadId]);
            crm_event($leadId, $clientId, null, 'in', $text, ['via' => 'avito']);
        } else {
            $clientId = crm_client($phone, (int)($m['author_id'] ?? 0) ?: null, (string)($m['author'] ?? ''));
            $db->prepare('INSERT INTO leads(client_id, source, channel_ref, title, message, item_url, status, created_at, updated_at)
                          VALUES(?, \'avito\', ?, ?, ?, ?, \'new\', ?, ?)')
                ->execute([$clientId, 'avito:' . $chatId, (string)($m['item'] ?? ''), $text, (string)($m['item_url'] ?? ''), $now, $now]);
            $leadId = (int)$db->lastInsertId();
            crm_event($leadId, $clientId, null, 'created', $text, [
                'source' => 'avito', 'item' => $m['item'] ?? '', 'price' => $m['price'] ?? '',
                'chat' => 'https://www.avito.ru/profile/messenger/channel/' . $chatId,
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return crm_lead_url($leadId);
}

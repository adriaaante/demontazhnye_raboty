<?php
/**
 * Отправка сообщений в Telegram. Общая для обработчика заявок с сайтов
 * (lead.php) и моста из Авито (avito.php) — чтобы правка транспорта
 * не требовала помнить про второе место. Файл одинаковый в репозиториях
 * voidaform.ru и подноль.рф: правишь один — копируй во второй.
 */

declare(strict_types=1);

/**
 * Связь с api.telegram.org из российских дата-центров рвётся: на Timeweb (11.2026) до половины
 * соединений не устанавливается. Все такие сбои — на этапе соединения, до отправки данных, поэтому
 * повтор безопасен и дублей не даёт. Сначала несколько быстрых попыток; не вышло — сообщение
 * ложится в очередь над папкой сайта (tg-queue/), её досылает tg_flush_queue() по cron раз в минуту.
 * Ответ Telegram с отказом (бот удалён из чата и т.п.) — настоящая ошибка: false, без очереди.
 *
 * @return bool доставлено или поставлено в очередь
 */
function tg_send(string $botToken, string $chatId, string $text): bool
{
    $r = tg_try_send($botToken, $chatId, $text, 4);
    if ($r !== null) {
        return $r;
    }
    return tg_enqueue($botToken, $chatId, $text);
}

/** @return bool|null true — доставлено, false — Telegram отказал, null — не достучались */
function tg_try_send(string $botToken, string $chatId, string $text, int $attempts): ?bool
{
    $payload = http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => 'true',
    ]);
    $url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';
    for ($i = 0; $i < $attempts; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $connected = (float)curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) > 0;
        if (is_string($response)) {
            $result = json_decode($response, true);
            return is_array($result) && !empty($result['ok']);
        }
        if ($connected) {
            // соединение было и оборвалось после отправки — Telegram мог принять: не повторяем
            return null;
        }
    }
    return null;
}

function tg_queue_dir(): string
{
    return dirname(__DIR__, 2) . '/tg-queue';
}

function tg_enqueue(string $botToken, string $chatId, string $text): bool
{
    $dir = tg_queue_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    $file = $dir . '/' . sprintf('%.6f', microtime(true)) . '-' . bin2hex(random_bytes(3)) . '.json';
    return @file_put_contents($file, json_encode(['token' => $botToken, 'chat' => $chatId, 'text' => $text, 'at' => time()])) !== false;
}

/** Досылает очередь (cron раз в минуту): по порядку, до первого недоступного раза. */
function tg_flush_queue(): void
{
    foreach (glob(tg_queue_dir() . '/*.json') ?: [] as $file) {
        $m = json_decode((string)@file_get_contents($file), true);
        if (!is_array($m)) {
            @unlink($file);
            continue;
        }
        $r = tg_try_send((string)$m['token'], (string)$m['chat'], (string)$m['text'], 2);
        if ($r === null) {
            return;   // Telegram снова не отвечает — следующая минута
        }
        // доставлено или окончательный отказ Telegram: из очереди убираем (отказ — в .rejected)
        $r ? @unlink($file) : @rename($file, $file . '.rejected');
    }
}

/**
 * Сообщение владельцу в личку о сбое: заявка не дошла до группы, Авито не
 * отвечает и т.п. Молчит, если в конфиге нет owner_chat_id. Повторяющийся
 * сбой одного вида — не чаще раза в 30 минут, чтобы поломка не завалила
 * личку; $throttle = false — для сообщений, которые терять нельзя
 * (сама недошедшая заявка).
 */
function tg_alert(array $config, string $kind, string $text, bool $throttle = true): void
{
    $owner = (string)($config['owner_chat_id'] ?? '');
    if ($owner === '' || empty($config['token'])) {
        return;
    }
    $project = (string)($config['site'] ?? '');
    if ($throttle) {
        $mark = sys_get_temp_dir() . '/vf-alert-' . md5($project . '|' . $kind);
        if ((int)@filemtime($mark) > time() - 1800) {
            return;
        }
        @touch($mark);
    }
    tg_send((string)$config['token'], $owner, "\u{26A0}\u{FE0F} " . ($project !== '' ? $project . ': ' : '') . $text);
}

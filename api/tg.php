<?php
/**
 * Отправка сообщений в Telegram. Общая для обработчика заявок с сайтов
 * (lead.php) и моста из Авито (avito.php) — чтобы правка транспорта
 * не требовала помнить про второе место. Файл одинаковый в репозиториях
 * voidaform.ru и подноль.рф: правишь один — копируй во второй.
 */

declare(strict_types=1);

/**
 * @return bool удалось ли доставить сообщение
 */
function tg_send(string $botToken, string $chatId, string $text): bool
{
    $payload = http_build_query([
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => 'true',
    ]);
    $url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';

    $response = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
    } else {
        $response = @file_get_contents($url, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]));
    }

    $result = is_string($response) ? json_decode($response, true) : null;
    return is_array($result) && !empty($result['ok']);
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

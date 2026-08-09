<?php
/**
 * Приём заявки с сайта и отправка её в Telegram.
 *
 * Токен бота и id чата лежат в api/config.php — этот файл не хранится
 * в репозитории, его создаёт GitHub Actions из секретов при выкладке.
 * В браузер токен не попадает: наружу торчит только этот адрес.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function reply(bool $ok, string $error = '', int $code = 200): void
{
    http_response_code($code);
    echo json_encode(
        $ok ? ['ok' => true] : ['ok' => false, 'error' => $error],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(false, 'method_not_allowed', 405);
}

$configFile = __DIR__ . '/config.php';
$config = is_readable($configFile) ? require $configFile : null;
if (!is_array($config) || empty($config['token']) || empty($config['chat_id'])) {
    reply(false, 'not_configured', 500);
}

// ловушка для ботов: поле скрыто от людей, автозаполнялки его заполняют.
// Отвечаем «принято», чтобы бот не искал обходной путь.
if (trim((string)($_POST['company'] ?? '')) !== '') {
    reply(true);
}

$clean = static function (string $value, int $limit): string {
    $value = strip_tags($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    $value = trim($value);
    // mbstring на хостинге есть, но подстрахуемся: обычный substr порезал бы
    // кириллицу посреди символа
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $limit)
        : substr($value, 0, $limit * 2);
};

$name    = $clean((string)($_POST['name'] ?? ''), 80);
$phone   = $clean((string)($_POST['phone'] ?? ''), 40);
$message = $clean((string)($_POST['message'] ?? ''), 700);
$source  = $clean((string)($_POST['source'] ?? ''), 140);
$page    = $clean((string)($_POST['page'] ?? ''), 200);

if ($name === '' || $phone === '') {
    reply(false, 'empty_fields', 422);
}
if (preg_match_all('/\d/u', $phone) < 6) {
    reply(false, 'bad_phone', 422);
}

// не больше 5 заявок с одного адреса за 10 минут
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if ($ip !== '') {
    $limitFile = sys_get_temp_dir() . '/vf-leads-' . md5($ip) . '.txt';
    $now = time();
    $hits = array_filter(
        array_map('intval', explode(',', (string)@file_get_contents($limitFile))),
        static fn(int $t): bool => $t > $now - 600
    );
    if (count($hits) >= 5) {
        reply(false, 'too_many_requests', 429);
    }
    $hits[] = $now;
    @file_put_contents($limitFile, implode(',', $hits), LOCK_EX);
}

$text = "Заявка с сайта: демонтажные работы (ГПУ Демонтаж)\n\n"
    . "Имя: {$name}\n"
    . "Телефон: {$phone}\n"
    . ($message !== '' ? "О проекте: {$message}\n" : '')
    . ($source !== '' ? "Откуда: {$source}\n" : '')
    . ($page !== '' ? "Страница: {$page}\n" : '')
    . 'Время: ' . date('d.m.Y H:i');

$payload = http_build_query([
    'chat_id' => $config['chat_id'],
    'text' => $text,
    'disable_web_page_preview' => 'true',
]);
$url = 'https://api.telegram.org/bot' . $config['token'] . '/sendMessage';

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
    curl_close($ch);
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
if (!is_array($result) || empty($result['ok'])) {
    // не получилось — фронт откроет WhatsApp как запасной путь
    reply(false, 'telegram_error', 502);
}

reply(true);

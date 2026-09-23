<?php
/**
 * Мост «Авито → Telegram»: сообщения клиентов из чатов Авито падают в тот же
 * чат, куда идут заявки с сайтов voidaform.ru и подноль.рф.
 *
 * Авито шлёт сюда вебхук на каждое сообщение в мессенджере аккаунта. Адрес
 * подписки — этот файл с ключом в запросе (`?k=…`): Авито вебхуки не
 * подписывает, поэтому секрет в адресе и есть единственная защита. Ключ
 * выводится из токена бота, отдельного секрета не нужно; посмотреть готовый
 * адрес можно, открыв этот файл в браузере с параметром `?setup=<токен бота>`.
 *
 * Что фильтруем и почему:
 *   • аккаунт Авито используется и для личных покупок — в мессенджере полно
 *     переписок, где объявление ЧУЖОЕ, а мы покупатель. В Telegram уходят
 *     только сообщения по НАШИМ объявлениям (chat.context.value.user_id);
 *   • свои же ответы пропускаем — иначе в чате будет эхо;
 *   • системные сообщения (автоответы «Ассистента Авито») пропускаем: это шум.
 *
 * Чтобы отличить наше объявление от чужого и подставить имя клиента, нужен
 * токен Авито. Ключи берутся из двух мест, в таком порядке:
 *   1. api/config.php — поля avito_client_id / avito_client_secret, файл
 *      собирается при выкладке из секретов репозитория;
 *   2. api/avito-config.php — файл, положенный на хостинг руками. Выкладка его
 *      не трогает (в репозитории его нет), так что он переживает обновления.
 * Без ключей мост отвечает Авито «ок», но ничего не пересылает: лучше тишина,
 * чем поток чужой переписки в рабочий чат.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require __DIR__ . '/tg.php';

const AVITO_USER_ID = 429115960;        // аккаунт «Под Ноль», Тихонов Никита
const TMP_PREFIX = 'vf-avito-';
const TOKEN_TTL = 3600;                 // токен Авито живёт 24 ч, обновляем чаще
const SEEN_TTL = 6 * 3600;              // сколько помним id доставленных сообщений

$configFile = __DIR__ . '/config.php';
$config = is_readable($configFile) ? require $configFile : null;
if (!is_array($config) || empty($config['token']) || empty($config['chat_id'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'not_configured']);
    exit;
}

// Ключи Авито: из общего конфига или из файла, положенного на хостинг руками.
$localFile = __DIR__ . '/avito-config.php';
if (empty($config['avito_client_id']) && is_readable($localFile)) {
    $local = require $localFile;
    if (is_array($local)) {
        $config += $local;
    }
}

$hookKey = substr(hash('sha256', 'avito-webhook|' . $config['token']), 0, 32);

// Подсказка себе: открыть в браузере с токеном бота, чтобы узнать адрес,
// который надо отдать Авито. Токен в запросе, а не в ответе, — ключ не утечёт.
if (isset($_GET['setup'])) {
    if (!hash_equals((string)$config['token'], (string)$_GET['setup'])) {
        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }
    $self = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'voidaform.ru') . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
    echo json_encode(['ok' => true, 'webhook_url' => $self . '?k=' . $hookKey], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!hash_equals($hookKey, (string)($_GET['k'] ?? ''))) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

// Авито ждёт быстрый 200 и повторяет доставку при любом другом ответе.
// Отвечаем «ок» даже на то, что решили не пересылать, — иначе получим
// бесконечные повторы одного и того же сообщения.
function done(): void
{
    echo json_encode(['ok' => true]);
    exit;
}

function tmpPath(string $kind, string $key): string
{
    return sys_get_temp_dir() . '/' . TMP_PREFIX . $kind . '-' . md5($key);
}

/** Запрос к API Авито. Возвращает массив или null. */
function avitoGet(string $url, string $token): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    return is_array($data) ? $data : null;
}

/** Токен Авито с кэшем в файле: дёргать /token/ на каждое сообщение незачем. */
function avitoToken(array $config): ?string
{
    $cache = tmpPath('token', 'app');
    if (is_readable($cache) && (time() - (int)@filemtime($cache)) < TOKEN_TTL) {
        $saved = trim((string)@file_get_contents($cache));
        if ($saved !== '') {
            return $saved;
        }
    }
    $ch = curl_init('https://api.avito.ru/token/');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $config['avito_client_id'],
            'client_secret' => $config['avito_client_secret'],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $data = is_string($body) ? json_decode($body, true) : null;
    $token = is_array($data) ? (string)($data['access_token'] ?? '') : '';
    if ($token === '') {
        return null;
    }
    @file_put_contents($cache, $token, LOCK_EX);
    return $token;
}

$raw = (string)file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    done();
}

$payload = $data['payload'] ?? [];
if (($payload['type'] ?? '') !== 'message') {
    done();                     // вебхук про что-то другое — не наше дело
}
$m = $payload['value'] ?? [];

$messageId = (string)($m['id'] ?? '');
$authorId  = (int)($m['author_id'] ?? 0);
$chatId    = (string)($m['chat_id'] ?? '');
$itemId    = (int)($m['item_id'] ?? 0);
$type      = (string)($m['type'] ?? 'text');
$text      = trim((string)($m['content']['text'] ?? ''));

if ($authorId === AVITO_USER_ID || $authorId === 0) {
    done();                     // свой ответ или системное сообщение Авито
}
if ($type === 'system' || $type === 'deleted') {
    done();
}

// Повторную доставку того же сообщения в чат не дублируем.
if ($messageId !== '') {
    $seen = tmpPath('seen', $messageId);
    if (file_exists($seen)) {
        done();
    }
    @touch($seen);
    if (random_int(1, 40) === 1) {
        foreach (glob(sys_get_temp_dir() . '/' . TMP_PREFIX . 'seen-*') ?: [] as $old) {
            if (@filemtime($old) < time() - SEEN_TTL) {
                @unlink($old);
            }
        }
    }
}

if (empty($config['avito_client_id']) || empty($config['avito_client_secret'])) {
    done();                     // без доступа к API отличить своё от чужого нельзя
}
$token = avitoToken($config);
if ($token === null) {
    done();
}

$chat = $chatId !== ''
    ? avitoGet('https://api.avito.ru/messenger/v2/accounts/' . AVITO_USER_ID . '/chats/' . rawurlencode($chatId), $token)
    : null;
$item = $chat['context']['value'] ?? [];

// Главный фильтр: объявление должно быть нашим. Иначе это переписка, где мы
// покупатель, — ей в рабочем чате не место.
if ((int)($item['user_id'] ?? 0) !== AVITO_USER_ID) {
    done();
}

$author = '';
foreach ($chat['users'] ?? [] as $u) {
    if ((int)($u['id'] ?? 0) === $authorId) {
        $author = trim((string)($u['name'] ?? ''));
    }
}

// Нетекстовые сообщения: в Telegram уходит пометка, содержимое смотрят в Авито.
$labels = [
    'image' => '[фото]',
    'link' => '[ссылка]',
    'item' => '[объявление]',
    'location' => '[геопозиция]',
    'call' => '[звонок]',
    'voice' => '[голосовое сообщение]',
    'file' => '[файл]',
    'video' => '[видео]',
];
if ($text === '') {
    $text = $labels[$type] ?? '[' . $type . ']';
}
if (function_exists('mb_substr')) {
    $text = mb_substr($text, 0, 2000);
}

$title = trim((string)($item['title'] ?? ''));
$price = trim((string)($item['price_string'] ?? ''));
$itemUrl = trim((string)($item['url'] ?? ''));
if ($itemUrl === '' && $itemId > 0) {
    $itemUrl = 'https://www.avito.ru/items/' . $itemId;
}

$when = (new DateTimeImmutable('@' . (int)($m['created'] ?? time())))
    ->setTimezone(new DateTimeZone('Europe/Moscow'));

$lines = ['Сообщение на Авито'];
$lines[] = '';
$lines[] = 'От: ' . ($author !== '' ? $author : 'покупатель');
if ($title !== '') {
    $lines[] = 'Объявление: ' . $title . ($price !== '' ? ' — ' . $price : '');
}
$lines[] = '';
$lines[] = $text;
$lines[] = '';
if ($chatId !== '') {
    $lines[] = 'Ответить: https://www.avito.ru/profile/messenger/channel/' . $chatId;
}
if ($itemUrl !== '') {
    $lines[] = 'Объявление: ' . $itemUrl;
}
$lines[] = 'Время: ' . $when->format('d.m.Y H:i');

tg_send((string)$config['token'], (string)$config['chat_id'], implode("\n", $lines));
done();

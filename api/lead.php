<?php
/**
 * Приём заявки с сайта и отправка её в Telegram.
 *
 * Токен бота и id чата лежат в api/config.php — этот файл не хранится
 * в репозитории, его создаёт GitHub Actions из секретов при выкладке.
 * В браузер токен не попадает: наружу торчит только этот адрес.
 *
 * Защита от ботов — несколько независимых слоёв, каждый дешёвый:
 *   1. Origin/Referer только с наших сайтов — отсекает прямые POST'ы
 *      скриптами, которые даже не притворяются браузером.
 *   2. Ловушка `company` — скрытое поле, которое заполняют автозаполнялки.
 *   3. Подписанный токен: браузер берёт его GET-запросом `?token`, сервер
 *      принимает заявку только с ним, не раньше чем через несколько секунд
 *      (человек столько заполняет форму) и не позже трёх часов, один раз.
 *      Без выполнения нашего JS заявку не отправить.
 *   4. Проверка полей: телефон похож на телефон, в имени нет ссылок,
 *      в сообщении нет пачки ссылок.
 *   5. Лимиты: на адрес и общий, плюс подавление повторов с одним номером.
 * Ботам, которых поймали на ловушке или мусоре в полях, отвечаем «принято» —
 * чтобы не подсказывать, что именно не прошло.
 */

declare(strict_types=1);

// Обработчик общий для двух сайтов: voidaform.ru и подноль.рф (заявки идут
// в один Telegram-чат). Чужим доменам браузер ответ не отдаст.
$allowedOrigins = [
    'https://voidaform.ru',
    'https://xn--d1aofccc0h.xn--p1ai',      // подноль.рф
    'https://adriaaante.github.io',          // стейджинг подноль.рф на GitHub Pages
];
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST');
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function reply(bool $ok, string $error = '', int $code = 200, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(
        ($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]) + $extra,
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
if ($method !== 'POST' && !($method === 'GET' && isset($_GET['token']))) {
    reply(false, 'method_not_allowed', 405);
}

$configFile = __DIR__ . '/config.php';
$config = is_readable($configFile) ? require $configFile : null;
if (!is_array($config) || empty($config['token']) || empty($config['chat_id'])) {
    reply(false, 'not_configured', 500);
}

// --- Подписанный токен формы ---------------------------------------------
// Ключ подписи выводим из токена бота: он и так секрет, отдельный не нужен.
$secret = hash('sha256', 'lead-form-token|' . $config['token']);
const TOKEN_MIN_AGE = 3;          // секунд: быстрее человек форму не заполнит
const TOKEN_MAX_AGE = 3 * 3600;   // страница, открытая с утра, ещё работает
const TMP_PREFIX = 'vf-lead-';    // имена служебных файлов в temp-каталоге

function tmpPath(string $kind, string $key): string
{
    return sys_get_temp_dir() . '/' . TMP_PREFIX . $kind . '-' . md5($key);
}

function issueToken(string $secret): string
{
    $body = time() . '.' . bin2hex(random_bytes(8));
    return $body . '.' . hash_hmac('sha256', $body, $secret);
}

/** Возвращает код ошибки или '' если токен годен. Помечает его использованным. */
function checkToken(string $token, string $secret): string
{
    $parts = explode('.', $token);
    if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_xdigit($parts[1])) {
        return 'token_invalid';
    }
    [$ts, $nonce, $sig] = $parts;
    if (!hash_equals(hash_hmac('sha256', $ts . '.' . $nonce, $secret), $sig)) {
        return 'token_invalid';
    }
    $age = time() - (int)$ts;
    if ($age < TOKEN_MIN_AGE) {
        return 'too_fast';
    }
    if ($age > TOKEN_MAX_AGE) {
        return 'token_expired';
    }
    // одноразовость: файл-отметка на каждый использованный токен
    $mark = tmpPath('nonce', $nonce);
    if (file_exists($mark)) {
        return 'token_used';
    }
    @touch($mark);
    // изредка подчищаем отметки старше срока жизни токена
    if (random_int(1, 25) === 1) {
        foreach (glob(sys_get_temp_dir() . '/' . TMP_PREFIX . 'nonce-*') ?: [] as $old) {
            if (@filemtime($old) < time() - TOKEN_MAX_AGE) {
                @unlink($old);
            }
        }
    }
    return '';
}

/** Скользящий лимит: сколько отметок за окно уже есть; добавляет текущую. */
function hits(string $file, int $window): int
{
    $now = time();
    $list = array_filter(
        array_map('intval', explode(',', (string)@file_get_contents($file))),
        static fn(int $t): bool => $t > $now - $window
    );
    $list[] = $now;
    @file_put_contents($file, implode(',', $list), LOCK_EX);
    return count($list) - 1;
}

if ($method === 'GET') {
    // Выдача токена. Лимит щедрый: страница берёт один токен за просмотр.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip !== '' && hits(tmpPath('token-ip', $ip), 600) >= 60) {
        reply(false, 'too_many_requests', 429);
    }
    reply(true, '', 200, ['token' => issueToken($secret)]);
}

// --- POST: сама заявка ------------------------------------------------------

// Браузер всегда представляется; пустой User-Agent — скрипт.
if (trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')) === '') {
    reply(true);
}

// Заявка должна прийти со страницы наших сайтов. Origin браузер шлёт при
// любом POST; если его нет — смотрим Referer. Нет ни того ни другого — чужой.
$allowedHosts = array_map(static fn(string $o): string => (string)parse_url($o, PHP_URL_HOST), $allowedOrigins);
$refHost = (string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
if (!in_array($origin, $allowedOrigins, true) && !in_array($refHost, $allowedHosts, true)) {
    reply(false, 'forbidden', 403);
}

// ловушка для ботов: поле скрыто от людей, автозаполнялки его заполняют.
// Отвечаем «принято», чтобы бот не искал обходной путь.
if (trim((string)($_POST['company'] ?? '')) !== '') {
    reply(true);
}

// токен формы — см. шапку файла. Клиент на эти ошибки берёт новый токен
// и повторяет отправку, поэтому коды здесь честные, а не «принято».
$tokenError = checkToken((string)($_POST['token'] ?? ''), $secret);
if ($tokenError !== '') {
    reply(false, $tokenError, 403);
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
$site    = $clean((string)($_POST['site'] ?? ''), 60);
if ($site === '') {
    $site = 'voidaform.ru';
}

if ($name === '' || $phone === '') {
    reply(false, 'empty_fields', 422);
}

// Телефон: 10–15 цифр, не «одна и та же цифра» и не «по порядку».
$digits = preg_replace('/\D+/', '', $phone) ?? '';
$len = strlen($digits);
if ($len < 10 || $len > 15
    || preg_match('/^(\d)\1+$/', $digits)
    || str_contains('01234567890123456789', $digits)
    || str_contains('98765432109876543210', $digits)
) {
    reply(false, 'bad_phone', 422);
}

// Спам в полях: ссылки в имени, имя из одних цифр или знаков, пачка ссылок
// в сообщении. Боту — «принято».
// Порядок важен: сначала полные формы, иначе «www.site.ru» посчитается дважды.
$urlPattern = '~(https?://\S+|www\.\S+|\S+\.(ru|com|net|org|io|рф)\b)~iu';
if (preg_match($urlPattern, $name)
    || !preg_match('/\p{L}/u', $name)
    || preg_match_all($urlPattern, $message) >= 2
) {
    reply(true);
}

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

// Повтор с тем же номером в течение трёх минут (двойной клик, обновлённая
// страница) — не дублируем в чат, но человеку отвечаем «принято».
$dupFile = tmpPath('dup', $site . '|' . $digits);
if ((int)@file_get_contents($dupFile) > time() - 180) {
    reply(true);
}

// Лимиты: не больше 5 заявок с одного адреса за 10 минут и не больше 30
// со всех адресов за час — столько живых заявок не бывает, а чат от потока
// с распределённого ботнета это спасёт. Человек при отказе уйдёт в WhatsApp.
if ($ip !== '' && hits(tmpPath('ip', $ip), 600) >= 5) {
    reply(false, 'too_many_requests', 429);
}
if (hits(tmpPath('all', 'global'), 3600) >= 30) {
    reply(false, 'too_many_requests', 429);
}

$text = "Заявка с сайта {$site}\n\n"
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

@file_put_contents($dupFile, (string)time(), LOCK_EX);
reply(true);

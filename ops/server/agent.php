<?php
/**
 * Агент управления сервером по HTTP — вместо SSH: из облачной сессии Claude наружу открыт только
 * HTTPS. Отвечает на адресе сервера по IP (виртуальный хост по умолчанию, /var/www/agent).
 *
 * Каждый запрос подписан: X-Ts — время (unix), X-Sig — HMAC-SHA256(ключ, "ts\nдействие\nтело").
 * Ключ лежит только в /opt/ff/agent.key и у владельца; по сети не передаётся, повтор запроса
 * старше 5 минут не принимается. Неподписанный запрос — 404, как будто агента нет.
 *
 *   ?a=status            — сводка: настройка, Telegram, выкладки, диск
 *   ?a=run  (тело = sh)  — команда в очередь; выполняет root (ff-runner, systemd path), ответ — id
 *   ?a=out&id=…          — вывод команды и код завершения
 *   ?a=put&name=…        — тело кладётся в /opt/ff/upload/<name> (база, архив)
 */

declare(strict_types=1);

$ff = '/opt/ff';
$key = (string)@file_get_contents($ff . '/agent.key');
$a = (string)($_GET['a'] ?? '');
$body = (string)file_get_contents('php://input');
$ts = (int)($_SERVER['HTTP_X_TS'] ?? 0);
$sig = (string)($_SERVER['HTTP_X_SIG'] ?? '');

if (strlen($key) < 32 || abs(time() - $ts) > 300
    || !hash_equals(hash_hmac('sha256', $ts . "\n" . $a . "\n" . $body, trim($key)), $sig)) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$tail = static function (string $file, int $n = 40): string {
    $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
    return implode("\n", array_slice($lines, -$n));
};

switch ($a) {
    case 'status':
        $sites = json_decode((string)@file_get_contents($ff . '/sites.json'), true) ?: [];
        $deploy = [];
        foreach ($sites as $name => $s) {
            $st = dirname((string)$s['root']) . '/deploy-state';
            $deploy[$name] = ['sha' => trim((string)@file_get_contents($st . '/sha')), 'log' => $tail($st . '/deploy.log', 6)];
        }
        echo json_encode([
            'ok' => true,
            'ready' => is_file($ff . '/out/bootstrap.done'),
            'bootstrap' => $tail($ff . '/out/bootstrap.log', 30),
            'telegram' => (string)@file_get_contents($ff . '/out/telegram.txt'),
            'deploy' => $deploy,
            'disk_free_mb' => (int)(disk_free_space('/') / 1048576),
            'load' => sys_getloadavg(),
            'php' => PHP_VERSION,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        break;

    case 'run':
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        file_put_contents($ff . "/queue/{$id}.sh.part", $body);
        rename($ff . "/queue/{$id}.sh.part", $ff . "/queue/{$id}.sh");
        echo json_encode(['ok' => true, 'id' => $id]);
        break;

    case 'out':
        $id = preg_replace('/[^0-9a-f-]/', '', (string)($_GET['id'] ?? ''));
        $log = $ff . "/out/{$id}.log";
        $code = @file_get_contents($ff . "/out/{$id}.code");
        echo json_encode(['ok' => true, 'done' => $code !== false, 'code' => $code === false ? null : (int)$code,
            'out' => (string)@file_get_contents($log)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        break;

    case 'put':
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($_GET['name'] ?? ''));
        if ($name === '' || file_put_contents($ff . "/upload/{$name}", $body) === false) {
            echo json_encode(['ok' => false]);
            break;
        }
        echo json_encode(['ok' => true, 'path' => $ff . "/upload/{$name}", 'bytes' => strlen($body)]);
        break;

    default:
        http_response_code(404);
}

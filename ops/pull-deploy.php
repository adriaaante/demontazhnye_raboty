<?php
/**
 * Выкладка без GitHub Actions: cron Beget запускает этот скрипт раз в 5 минут
 * (php ops/pull-deploy.php). Скрипт смотрит, какой коммит сейчас в main публичного
 * репозитория, и если он новый — скачивает архив, проверяет ключевые файлы и
 * раскладывает сайт в папку сайта (родитель ops/).
 *
 * Не трогает то, чего нет в репозитории: api/config.php (секреты), api/avito-config.php,
 * базу CRM. Удаляет только файлы, которые сам выкладывал раньше и которых больше нет
 * в репозитории (список — в deploy-state/manifest.json над папкой сайта).
 *
 * Запуск только из командной строки (из браузера — 403 через ops/.htaccess и проверку ниже).
 * Ручной прогон: php ops/pull-deploy.php --force  — выложить даже тот же коммит.
 * Проверка на другой ветке: DEPLOY_BRANCH=<ветка> php ops/pull-deploy.php (в копию сайта, не на боевой).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

const REPO = 'adriaaante/demontazhnye_raboty';
// в репозитории есть, на хостинге не нужно
const SKIP = ['.git', '.github', 'scripts', 'CLAUDE.md', '.gitignore', '.DS_Store'];
// без этих файлов сайт не выкладываем — архив битый или репозиторий сломан
const MUST = ['index.html', '404.html', 'privacy.html', 'consent.html', '.htaccess', 'robots.txt', 'sitemap.xml',
    'css/style.css', 'js/main.js', 'favicon.svg', 'api/lead.php', 'api/tg.php', 'crm/api.php', 'crm/app.js',
    'assets/img/hero.jpg', 'assets/fonts/montserrat-latin.woff2', 'assets/fonts/montserrat-cyrillic.woff2',
    'ops/pull-deploy.php'];

$webroot = dirname(__DIR__);
$state = dirname($webroot) . '/deploy-state';
@mkdir($state, 0700, true);
$force = in_array('--force', $argv, true);
$branch = getenv('DEPLOY_BRANCH') ?: 'main';   // другая ветка — только для проверки

function say(string $msg): void
{
    global $state;
    $line = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
    echo $line;
    @file_put_contents($state . '/deploy.log', $line, FILE_APPEND);
}

function alert(string $text): void
{
    global $webroot;
    $cfgFile = $webroot . '/api/config.php';
    if (!is_readable($cfgFile) || !is_readable($webroot . '/api/tg.php')) {
        return;
    }
    $cfg = require $cfgFile;
    require_once $webroot . '/api/tg.php';
    if (is_array($cfg) && function_exists('tg_alert')) {
        tg_alert($cfg, 'deploy', 'выкладка с GitHub не прошла — ' . $text);
    }
}

function http_get(string $url, ?string $saveTo = null): ?string
{
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => $saveTo === null, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'podnol-deploy'];
    $fh = null;
    if ($saveTo !== null) {
        $fh = fopen($saveTo, 'wb');
        $opt[CURLOPT_FILE] = $fh;
    }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($fh) {
        fclose($fh);
    }
    if ($code !== 200 || $body === false) {
        return null;
    }
    return $saveTo === null ? (string)$body : '';
}

// один прогон за раз: cron может наложиться на долгую выкладку
$lock = fopen($state . '/deploy.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

// какой коммит сейчас в main: служебный адрес git, без лимитов GitHub API
$refs = http_get('https://github.com/' . REPO . '.git/info/refs?service=git-upload-pack');
if ($refs === null || !preg_match('~([0-9a-f]{40}) refs/heads/' . preg_quote($branch, '~') . '\b~', $refs, $m)) {
    // GitHub недоступен — тихо ждём следующего запуска, тревожим только если так больше часа
    $mark = $state . '/github-down';
    if (!is_file($mark)) {
        touch($mark);
    } elseif (filemtime($mark) < time() - 3600) {
        alert('GitHub не отвечает больше часа');
        touch($mark);
    }
    exit(0);
}
@unlink($state . '/github-down');
$sha = $m[1];
$current = trim((string)@file_get_contents($state . '/sha'));
if ($sha === $current && !$force) {
    exit(0);
}

say("новый коммит {$sha} (был " . ($current ?: '—') . ')');
$tmp = $state . '/tmp-' . $sha;
$tgz = $tmp . '.tar.gz';
try {
    if (http_get('https://codeload.github.com/' . REPO . '/tar.gz/' . $sha, $tgz) === null) {
        throw new RuntimeException('архив с GitHub не скачался');
    }
    @mkdir($tmp, 0700, true);
    (new PharData($tgz))->extractTo($tmp, null, true);
    $dirs = glob($tmp . '/*', GLOB_ONLYDIR);
    if (count($dirs) !== 1) {
        throw new RuntimeException('неожиданная структура архива');
    }
    $src = $dirs[0];
    foreach (MUST as $f) {
        if (!is_file($src . '/' . $f)) {
            throw new RuntimeException("в коммите нет {$f} — выкладка остановлена");
        }
    }

    // список файлов к выкладке
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = substr($file->getPathname(), strlen($src) + 1);
        $top = explode('/', $rel)[0];
        if (in_array($top, SKIP, true) || basename($rel) === '.DS_Store') {
            continue;
        }
        $files[] = $rel;
    }

    // раскладываем: каждый файл пишется во временный рядом и подменяется rename — без «полуфайлов»
    $changed = 0;
    foreach ($files as $rel) {
        $dst = $webroot . '/' . $rel;
        $data = (string)file_get_contents($src . '/' . $rel);
        if (is_file($dst) && md5_file($dst) === md5($data)) {
            continue;
        }
        if (!is_dir(dirname($dst))) {
            mkdir(dirname($dst), 0755, true);
        }
        $part = $dst . '.deploy-part';
        if (file_put_contents($part, $data) === false || !rename($part, $dst)) {
            @unlink($part);
            throw new RuntimeException("не записался {$rel}");
        }
        $changed++;
    }

    // удаляем только то, что выкладывали сами и чего больше нет в репозитории
    $old = json_decode((string)@file_get_contents($state . '/manifest.json'), true) ?: [];
    $removed = 0;
    foreach (array_diff($old, $files) as $rel) {
        if (str_starts_with($rel, 'api/config') || $rel === 'api/avito-config.php') {
            continue;
        }
        if (is_file($webroot . '/' . $rel) && @unlink($webroot . '/' . $rel)) {
            $removed++;
        }
    }
    file_put_contents($state . '/manifest.json', json_encode(array_values($files)));
    file_put_contents($state . '/sha', $sha);
    say("выложено: изменено {$changed}, удалено {$removed}, всего файлов " . count($files));
} catch (Throwable $e) {
    say('ОШИБКА: ' . $e->getMessage());
    alert($e->getMessage() . " (коммит " . substr($sha, 0, 7) . ')');
} finally {
    if (is_dir($tmp)) {
        $rm = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rm as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($tmp);
    }
    @unlink($tgz);
}

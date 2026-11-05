<?php
/**
 * Выкладка сайтов с GitHub без Actions. Живёт на сервере в /opt/ff/deploy.php (кладёт cloud-init,
 * см. ops/server/), cron от www-data запускает её раз в 5 минут: `php /opt/ff/deploy.php all`.
 * Сайты описаны в /opt/ff/sites.json: репозиторий, ветка, папка сайта, что не выкладывать и без
 * каких файлов коммит считается битым.
 *
 * Для каждого сайта: смотрит коммит ветки публичного репозитория (служебный адрес git, без лимитов
 * GitHub API), новый — качает архив codeload, проверяет обязательные файлы и раскладывает с
 * атомарной подменой файлов. Не трогает то, чего нет в репозитории: api/config.php (секреты),
 * базу CRM. Удаляет только файлы, которые сам выкладывал раньше (manifest.json).
 * Состояние — <папка над сайтом>/deploy-state. Ошибка — tg_alert в личку, если у сайта есть api/tg.php.
 *
 *   php deploy.php all [--force]      — все сайты; --force выкладывает и тот же коммит
 *   php deploy.php <домен> [--force]  — один сайт
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$sites = json_decode((string)@file_get_contents(__DIR__ . '/sites.json'), true);
if (!is_array($sites)) {
    fwrite(STDERR, "нет sites.json рядом со скриптом\n");
    exit(1);
}
$only = $argv[1] ?? 'all';
$force = in_array('--force', $argv, true);

foreach ($sites as $name => $site) {
    if ($only === 'all' || $only === $name) {
        deploy_site((string)$name, $site, $force);
    }
}

function http_get(string $url, ?string $saveTo = null): ?string
{
    $ch = curl_init($url);
    $opt = [CURLOPT_RETURNTRANSFER => $saveTo === null, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'ff-deploy'];
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

function rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function deploy_site(string $name, array $site, bool $force): void
{
    $root = rtrim((string)$site['root'], '/');
    $state = dirname($root) . '/deploy-state';
    @mkdir($state, 0700, true);
    $say = static function (string $msg) use ($state, $name): void {
        $line = date('Y-m-d H:i:s') . " [{$name}] {$msg}\n";
        echo $line;
        @file_put_contents($state . '/deploy.log', $line, FILE_APPEND);
    };
    $alert = static function (string $text) use ($root): void {
        $cfgFile = $root . '/api/config.php';
        if (!is_readable($cfgFile) || !is_readable($root . '/api/tg.php')) {
            return;
        }
        $cfg = require $cfgFile;
        require_once $root . '/api/tg.php';
        if (is_array($cfg) && function_exists('tg_alert')) {
            tg_alert($cfg, 'deploy', 'выкладка с GitHub не прошла — ' . $text);
        }
    };

    // один прогон на сайт за раз: cron может наложиться на долгую выкладку
    $lock = fopen($state . '/deploy.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return;
    }

    $repo = (string)$site['repo'];
    $branch = (string)$site['branch'];
    $refs = http_get('https://github.com/' . $repo . '.git/info/refs?service=git-upload-pack');
    if ($refs === null || !preg_match('~([0-9a-f]{40}) refs/heads/' . preg_quote($branch, '~') . '\n~', $refs, $m)) {
        // GitHub недоступен — тихо ждём следующего запуска, тревожим, только если так больше часа
        $mark = $state . '/github-down';
        if (!is_file($mark)) {
            touch($mark);
        } elseif (filemtime($mark) < time() - 3600) {
            $alert('GitHub не отвечает больше часа');
            touch($mark);
        }
        return;
    }
    @unlink($state . '/github-down');
    $sha = $m[1];
    $current = trim((string)@file_get_contents($state . '/sha'));
    if ($sha === $current && !$force) {
        return;
    }

    $say("новый коммит {$sha} (был " . ($current ?: '—') . ')');
    $tmp = $state . '/tmp-' . $sha;
    $tgz = $tmp . '.tar.gz';
    try {
        if (http_get('https://codeload.github.com/' . $repo . '/tar.gz/' . $sha, $tgz) === null) {
            throw new RuntimeException('архив с GitHub не скачался');
        }
        rm_tree($tmp);
        mkdir($tmp, 0700, true);
        (new PharData($tgz))->extractTo($tmp, null, true);
        $dirs = glob($tmp . '/*', GLOB_ONLYDIR);
        if (count($dirs) !== 1) {
            throw new RuntimeException('неожиданная структура архива');
        }
        $src = $dirs[0];
        foreach ($site['must'] ?? [] as $f) {
            if (!is_file($src . '/' . $f)) {
                throw new RuntimeException("в коммите нет {$f} — выкладка остановлена");
            }
        }

        $skip = $site['skip'] ?? [];
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($src) + 1);
            if (in_array(explode('/', $rel)[0], $skip, true) || basename($rel) === '.DS_Store') {
                continue;
            }
            $files[] = $rel;
        }

        // каждый файл пишется во временный рядом и подменяется rename — без «полуфайлов»
        $changed = 0;
        foreach ($files as $rel) {
            $dst = $root . '/' . $rel;
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
            if (is_file($root . '/' . $rel) && @unlink($root . '/' . $rel)) {
                $removed++;
            }
        }
        file_put_contents($state . '/manifest.json', json_encode(array_values($files)));
        file_put_contents($state . '/sha', $sha);
        $say("выложено: изменено {$changed}, удалено {$removed}, всего файлов " . count($files));
    } catch (Throwable $e) {
        $say('ОШИБКА: ' . $e->getMessage());
        $alert($e->getMessage() . ' (коммит ' . substr($sha, 0, 7) . ')');
    } finally {
        rm_tree($tmp);
        @unlink($tgz);
        flock($lock, LOCK_UN);
    }
}

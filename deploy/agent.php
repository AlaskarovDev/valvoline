<?php

use Illuminate\Contracts\Console\Kernel;

/*
 * Valvo Analitika deploy agent — uploaded to public_html/_deploy.php by CI on every deploy.
 *
 * Shared hosting has no SSH in the pipeline and FTP is slow per file, so CI uploads two
 * zips (app + vendor) and calls this script, which unpacks into _app_staging, runs the
 * migrations in-process, then swaps _app_staging <-> _app (previous kept as _app_prev
 * for rollback). Answers are always HTTP 200 + JSON {ok: bool}: the hosting CDN strips
 * the body of 4xx/5xx responses, which would hide the reason of a failure.
 *
 * Auth: header X-Deploy-Token; only its SHA-256 is embedded here (filled in by CI).
 */

const TOKEN_HASH = '__TOKEN_HASH__';
const ARTISAN_ALLOWED = ['analytics:rebuild', 'db:copy-sqlite', 'user:password', 'category:set', 'migrate:status', 'about', 'db:seed', 'queue:work', 'optimize:clear'];

/* Əl ilə public_html-ə tökülmüş köhnə layihə faylları (CI quruluşundan əvvəl) — silinmir, _legacy-yə köçürülür. */
const LEGACY_ITEMS = ['app', 'bootstrap', 'config', 'database', 'node_modules', 'public', 'resources', 'routes', 'storage', 'tests', 'tools',
    'vendor', 'artisan', 'boost.json', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpunit.xml', 'vite.config.js',
    'README.md', 'AGENTS.md', 'CLAUDE.md', '.env', '.env.example', '.editorconfig', '.gitattributes', '.gitignore', '.mcp.json', '.npmrc',
    '.phpunit.result.cache', '.claude'];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function reply(bool $ok, array $data = []): never
{
    echo json_encode(['ok' => $ok] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$given = (string) ($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '');
if ('__TOKEN_'.'HASH__' === TOKEN_HASH || $given === '' || ! hash_equals(TOKEN_HASH, hash('sha256', $given))) {
    reply(false, ['error' => 'forbidden']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(false, ['error' => 'POST only']);
}

@set_time_limit(900);
@ignore_user_abort(true);
@ini_set('memory_limit', '512M');

$root = __DIR__;
$paths = [
    'app' => $root.'/_app',
    'staging' => $root.'/_app_staging',
    'prev' => $root.'/_app_prev',
    'shared' => $root.'/_app_shared',
    'storage' => $root.'/_app_storage',
    'releases' => $root.'/_releases',
];

/* ---------- helpers ---------- */

function rrmdir(string $dir): void
{
    if (! is_dir($dir) || is_link($dir)) {
        @unlink($dir);

        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function copydir(string $from, string $to): void
{
    @mkdir($to, 0755, true);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $target = $to.'/'.substr($f->getPathname(), strlen($from) + 1);
        $f->isDir() ? @mkdir($target, 0755, true) : copy($f->getPathname(), $target);
    }
}

function unzip(string $zip, string $dest): void
{
    if (! is_file($zip)) {
        throw new RuntimeException('missing archive '.basename($zip));
    }
    if (class_exists(ZipArchive::class)) {
        $z = new ZipArchive;
        if ($z->open($zip) !== true) {
            throw new RuntimeException('cannot open '.basename($zip));
        }
        if (! $z->extractTo($dest)) {
            throw new RuntimeException('cannot extract '.basename($zip));
        }
        $z->close();

        return;
    }
    (new PharData($zip))->extractTo($dest, null, true);
}

function deny(string $dir): void
{
    @mkdir($dir, 0755, true);
    $src = __DIR__.'/_app/deploy/deny.htaccess';
    file_put_contents($dir.'/.htaccess', is_file($src) ? file_get_contents($src) : "Require all denied\n");
}

/** Boot the Laravel app found in $dir and run artisan commands in this process. */
function artisan(string $dir, array $commands): array
{
    require $dir.'/vendor/autoload.php';
    $app = require $dir.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $out = [];
    foreach ($commands as [$name, $args]) {
        $code = $kernel->call($name, $args);
        // output() fetches (and empties) the buffer: read it once, use it for both the result and the error.
        $text = trim($kernel->output());
        $out[] = ['command' => $name, 'exit' => $code, 'output' => mb_substr($text, -6000)];
        if ($code !== 0) {
            throw new RuntimeException("artisan {$name} exited with {$code}: ".mb_substr($text, -2000));
        }
    }

    return $out;
}

function writeEnv(string $env, array $paths, string $root): void
{
    $env = strtr($env, ['%ROOT%' => $root, '%STORAGE%' => $paths['storage']]);
    file_put_contents($paths['shared'].'/.env', rtrim($env)."\n", LOCK_EX);
    @chmod($paths['shared'].'/.env', 0640);
    if (preg_match('/^DB_CONNECTION=sqlite\s*$/m', $env) && preg_match('/^DB_DATABASE=(.+)$/m', $env, $m)) {
        $db = trim($m[1], " \"'");
        if (! is_file($db)) {
            @mkdir(dirname($db), 0755, true);
            touch($db);
        }
    }
}

function prepareDirs(array $paths): void
{
    foreach (['shared', 'storage', 'releases'] as $k) {
        deny($paths[$k]);
    }
    foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $sub) {
        @mkdir($paths['storage'].'/'.$sub, 0755, true);
    }
}

function moveLegacy(string $root): array
{
    if (! is_file($root.'/artisan')) {
        return [];
    }
    $target = $root.'/_legacy_'.date('Ymd_His');
    @mkdir($target, 0755, true);
    deny($target);
    $moved = [];
    foreach (LEGACY_ITEMS as $item) {
        if (file_exists($root.'/'.$item) && @rename($root.'/'.$item, $target.'/'.$item)) {
            $moved[] = $item;
        }
    }

    return $moved;
}

function publishPublic(array $paths, string $root): void
{
    copy($paths['app'].'/deploy/index.php', $root.'/index.php');
    copy($paths['app'].'/deploy/root.htaccess', $root.'/.htaccess');
    foreach (['favicon.svg', 'favicon.ico', 'robots.txt'] as $f) {
        if (is_file($paths['app'].'/public/'.$f)) {
            copy($paths['app'].'/public/'.$f, $root.'/'.$f);
        }
    }
    rrmdir($root.'/build_prev');
    if (is_dir($root.'/build')) {
        rename($root.'/build', $root.'/build_prev');
    }
    copydir($paths['app'].'/public/build', $root.'/build');
    deny($paths['app']);
    @unlink($root.'/default.php'); // hosting placeholder page
}

/* ---------- actions ---------- */

$action = $_POST['action'] ?? 'probe';

try {
    if ($action === 'probe') {
        reply(true, [
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'root' => $root,
            'extensions' => get_loaded_extensions(),
            'zip' => class_exists(ZipArchive::class),
            'disabled_functions' => ini_get('disable_functions'),
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit'),
            'disk_free_mb' => (int) (@disk_free_space($root) / 1048576),
            'has_app' => is_dir($paths['app']),
            'has_env' => is_file($paths['shared'].'/.env'),
            'releases' => is_dir($paths['releases']) ? array_values(array_diff(scandir($paths['releases']), ['.', '..', '.htaccess'])) : [],
        ]);
    }

    if ($action === 'deploy') {
        $release = basename((string) ($_POST['release'] ?? ''));
        $vendor = basename((string) ($_POST['vendor'] ?? ''));
        if (! preg_match('/^app-[a-f0-9]{7,40}\.zip$/', $release) || ! preg_match('/^vendor-[a-f0-9]{8,40}\.zip$/', $vendor)) {
            reply(false, ['error' => 'bad archive names']);
        }
        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            reply(false, ['error' => 'PHP '.PHP_VERSION.' — Laravel 13 needs PHP 8.3+. hPanel -> Advanced -> PHP Configuration.']);
        }

        $lock = fopen($root.'/_deploy.lock', 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            reply(false, ['error' => 'another deploy is running']);
        }

        $t = microtime(true);
        prepareDirs($paths);
        if (! empty($_POST['env'])) {
            writeEnv((string) $_POST['env'], $paths, $root);
        }
        if (! is_file($paths['shared'].'/.env')) {
            reply(false, ['error' => 'no .env on the server and none sent']);
        }

        rrmdir($paths['staging']);
        mkdir($paths['staging'], 0755, true);
        deny($paths['staging']);
        unzip($paths['releases'].'/'.$release, $paths['staging']);
        unzip($paths['releases'].'/'.$vendor, $paths['staging']);
        touch($paths['staging'].'/.hosting-layout');
        foreach (glob($paths['staging'].'/bootstrap/cache/*.php') as $f) {
            @unlink($f);
        }
        $unpacked = round(microtime(true) - $t, 1);

        // Schema first (old code keeps serving meanwhile), then the swap.
        $out = artisan($paths['staging'], [['migrate', ['--force' => true]], ['db:seed', ['--force' => true]]]);

        rrmdir($paths['prev']);
        if (is_dir($paths['app'])) {
            rename($paths['app'], $paths['prev']);
        }
        rename($paths['staging'], $paths['app']);
        $legacy = moveLegacy($root);
        publishPublic($paths, $root);

        // Compiled views are keyed by path and checked by mtime: stale after an unzip.
        foreach (glob($paths['storage'].'/framework/views/*.php') as $f) {
            @unlink($f);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // Keep the last 3 app archives and the current vendor archive.
        $apps = glob($paths['releases'].'/app-*.zip');
        usort($apps, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($apps, 3) as $old) {
            @unlink($old);
        }
        foreach (glob($paths['releases'].'/vendor-*.zip') as $v) {
            if (basename($v) !== $vendor && filemtime($v) < time() - 86400 * 7) {
                @unlink($v);
            }
        }

        reply(true, ['release' => $release, 'legacy_moved' => $legacy, 'unpack_seconds' => $unpacked, 'seconds' => round(microtime(true) - $t, 1), 'artisan' => $out]);
    }

    if ($action === 'rollback') {
        if (! is_dir($paths['prev'])) {
            reply(false, ['error' => 'no previous release']);
        }
        rrmdir($root.'/_app_failed');
        rename($paths['app'], $root.'/_app_failed');
        rename($paths['prev'], $paths['app']);
        deny($root.'/_app_failed');
        if (is_dir($root.'/build_prev')) {
            rrmdir($root.'/build');
            rename($root.'/build_prev', $root.'/build');
        }
        copy($paths['app'].'/deploy/index.php', $root.'/index.php');
        foreach (glob($paths['storage'].'/framework/views/*.php') as $f) {
            @unlink($f);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        reply(true, ['rolled_back' => true]);
    }

    if ($action === 'artisan') {
        $command = (string) ($_POST['command'] ?? '');
        $args = json_decode((string) ($_POST['args'] ?? '{}'), true) ?: [];
        if (! in_array($command, ARTISAN_ALLOWED, true)) {
            reply(false, ['error' => 'command not allowed']);
        }
        reply(true, ['artisan' => artisan($paths['app'], [[$command, $args]])]);
    }

    if ($action === 'log') {
        $files = glob($paths['storage'].'/logs/*.log');
        rsort($files);
        $tail = $files ? mb_substr((string) file_get_contents($files[0]), -12000) : '';
        reply(true, ['file' => $files ? basename($files[0]) : null, 'tail' => $tail]);
    }

    reply(false, ['error' => 'unknown action']);
} catch (Throwable $e) {
    reply(false, ['error' => get_class($e).': '.$e->getMessage(), 'at' => basename($e->getFile()).':'.$e->getLine()]);
}

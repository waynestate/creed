<?php

declare(strict_types=1);

namespace Waynestate\Creed\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waynestate\Creed\Site\PhpLifecycle\Feed;
use Waynestate\Creed\Site\PhpLifecycle\Parse;

require_once __DIR__ . '/../deploy/lib/PhpLifecycle/autoload.php';

/**
 * What Citadel serves, laid out as Citadel lays it out: the whole repository checked out, deploy/ as the app, site/
 * and data/ beside it. On 2026-11-20 /php said "tested lines not read" on prod with a correct feed: the page was new
 * and the browser still ran the hour-cached script from before the deploy. These tests hold the two things that
 * broke or could have: the paths the deployed code reads, and how the front controller lets scripts be cached.
 * A squire checks the straps before the knight mounts, not after he falls off.
 */
final class DeployLayoutTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private string $tmp = '';

    /** @var resource|null */
    private $server = null;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->tmp);
        }
    }

    #[Test]
    public function theFeedReadsTheTestDataTheRepositoryShips(): void
    {
        $file = Feed::defaultTestsFile();
        self::assertSame(realpath(self::ROOT . '/data/framework-php-tests.json'), realpath($file), 'Feed reads data/ at the repository root, beside deploy/');
        $raw = file_get_contents($file);
        self::assertIsString($raw);
        self::assertNotNull(Parse::frameworkTests($raw), 'data/framework-php-tests.json must parse as the matrix, or no Laravel cell can be supported');
    }

    #[Test]
    public function everyScriptAndStylesheetThePhpPageLinksIsInTheSiteFolder(): void
    {
        // The front controller's default site root is ../../site from deploy/public.
        $site = realpath(self::ROOT . '/deploy/public/../../site');
        self::assertIsString($site);
        $html = file_get_contents($site . '/php/index.html');
        self::assertIsString($html);
        preg_match_all('/<(?:script[^>]+src|link[^>]+href)="(\.\.\/[^"#?]+)"/', $html, $m);
        self::assertContains('../assets/php-lifecycle.js', $m[1]);
        foreach ($m[1] as $ref) {
            self::assertFileExists($site . '/php/' . $ref, $ref . ' is linked from /php but not shipped');
        }
    }

    #[Test]
    public function scriptsAndStylesAreRevalidatedOnEveryLoadSoAPageNeverRunsAnOlderScript(): void
    {
        $base = $this->serve();

        foreach (['/assets/php-lifecycle.js', '/assets/php-lifecycle.css', '/data.json'] as $path) {
            [$status, $h] = self::get($base . $path);
            self::assertSame(200, $status, $path);
            self::assertSame('no-cache', $h['cache-control'] ?? null, $path . ' must revalidate, or a fresh page runs an old copy');
            self::assertArrayHasKey('etag', $h, $path);

            [$status, $again] = self::get($base . $path, ['If-None-Match: ' . $h['etag']]);
            self::assertSame(304, $status, $path . ' unchanged answers 304, so revalidating costs one small request');
            self::assertSame('no-cache', $again['cache-control'] ?? null, $path);
        }

        [$status, $h] = self::get($base . '/php/');
        self::assertSame(200, $status);
        self::assertSame('no-cache', $h['cache-control'] ?? null, 'the page itself is never cached');

        [$status, $h] = self::get($base . '/mark.png');
        self::assertSame(200, $status);
        self::assertSame('public, max-age=3600', $h['cache-control'] ?? null, 'images keep their hour');
    }

    /** A copy of the deployed layout behind PHP's built-in server, with a stand-in for HELMET. */
    private function serve(): string
    {
        $this->tmp = sys_get_temp_dir() . '/creed-deploy-layout-' . uniqid('', true);
        foreach (['/deploy/public', '/deploy/vendor', '/site/php', '/site/assets'] as $d) {
            mkdir($this->tmp . $d, 0o777, true);
        }
        copy(self::ROOT . '/deploy/public/index.php', $this->tmp . '/deploy/public/index.php');
        file_put_contents($this->tmp . '/deploy/vendor/autoload.php', "<?php\nnamespace Waynestate\\Helmet;\nfunction helmet_boot(array \$o = []): array { return ['nonce' => 'test']; }\n");
        copy(self::ROOT . '/site/php/index.html', $this->tmp . '/site/php/index.html');
        copy(self::ROOT . '/site/assets/php-lifecycle.js', $this->tmp . '/site/assets/php-lifecycle.js');
        copy(self::ROOT . '/site/assets/php-lifecycle.css', $this->tmp . '/site/assets/php-lifecycle.css');
        file_put_contents($this->tmp . '/site/data.json', '{}');
        file_put_contents($this->tmp . '/site/mark.png', "\x89PNG\r\n\x1a\n");

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        self::assertIsString($name);
        $log = $this->tmp . '/server.log';
        $server = proc_open(
            [PHP_BINARY, '-S', $name, '-t', $this->tmp . '/deploy/public', $this->tmp . '/deploy/public/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        self::assertIsResource($server);
        $this->server = $server;
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', (int) substr($name, strrpos($name, ':') + 1), $errno, $errstr, 0.1);
            if ($c !== false) {
                fclose($c);

                return 'http://' . $name;
            }
            usleep(50_000);
        }
        self::fail('the built-in server did not start: ' . (string) @file_get_contents($log));
    }

    /**
     * A plain HTTP/1.0 request over a socket: no stream wrapper, so it reads the same on every PHP line CREED runs.
     *
     * @param list<string> $headers
     *
     * @return array{0: int, 1: array<string, string>}
     */
    private static function get(string $url, array $headers = []): array
    {
        $u = parse_url($url);
        self::assertIsArray($u);
        $c = fsockopen($u['host'] ?? '127.0.0.1', $u['port'] ?? 80, $errno, $errstr, 5);
        self::assertNotFalse($c, $errstr);
        stream_set_timeout($c, 5);
        $req = 'GET ' . ($u['path'] ?? '/') . " HTTP/1.0\r\nHost: " . ($u['host'] ?? '') . "\r\n";
        foreach ($headers as $h) {
            $req .= $h . "\r\n";
        }
        fwrite($c, $req . "Connection: close\r\n\r\n");
        $raw = stream_get_contents($c);
        fclose($c);
        self::assertIsString($raw);
        $lines = explode("\r\n", explode("\r\n\r\n", $raw, 2)[0]);
        $status = preg_match('#^HTTP/\S+ (\d{3})#', $lines[0], $m) ? (int) $m[1] : 0;
        $out = [];
        foreach (array_slice($lines, 1) as $line) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $out[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
        }

        return [$status, $out];
    }
}

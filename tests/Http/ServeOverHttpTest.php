<?php

namespace FaridM\StepChat\Tests\Http;

use PHPUnit\Framework\TestCase;

final class ServeOverHttpTest extends TestCase
{
    private const PORT = 8124;
    /** @var resource|null */
    private static $proc;
    /** @var array<int, resource> */
    private static $pipes = [];
    private string $cookie = '';
    /** @var array<int, string> */
    private array $lastHeaders = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        self::$proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::PORT, '-t', $root],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            self::$pipes,
            $root
        );
        for ($i = 0; $i < 50; $i++) {              // wait max 5 s
            $s = @fsockopen('127.0.0.1', self::PORT);
            if ($s) {
                fclose($s);
                return;
            }
            usleep(100000);
        }
        self::fail('php -S did not start on port ' . self::PORT);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
    }

    /** @return array{0:int, 1:array<string,mixed>} */
    private function post(array $body): array
    {
        $headers = "Content-Type: application/json\r\n" . ($this->cookie !== '' ? "Cookie: {$this->cookie}\r\n" : '');
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => $headers, 'content' => json_encode($body), 'ignore_errors' => true, 'timeout' => 5,
        ]]);
        $raw = file_get_contents('http://127.0.0.1:' . self::PORT . '/tests/Fixtures/e2e/endpoint.php', false, $ctx);
        $status = 0;
        $this->lastHeaders = $http_response_header;
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
            if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $h, $m)) {
                $this->cookie = $m[1];
            }
        }
        return [$status, json_decode((string) $raw, true)];
    }

    /** @return array{0:int, 1:array<string,mixed>} */
    private function postRaw(string $content): array
    {
        $headers = "Content-Type: application/json\r\n" . ($this->cookie !== '' ? "Cookie: {$this->cookie}\r\n" : '');
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => $headers, 'content' => $content, 'ignore_errors' => true, 'timeout' => 5,
        ]]);
        $raw = file_get_contents('http://127.0.0.1:' . self::PORT . '/tests/Fixtures/e2e/endpoint.php', false, $ctx);
        $status = 0;
        $this->lastHeaders = $http_response_header;
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
            if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $h, $m)) {
                $this->cookie = $m[1];
            }
        }
        return [$status, json_decode((string) $raw, true)];
    }

    public function test_full_lead_over_http_persists_and_runs_on_complete(): void
    {
        @unlink(sys_get_temp_dir() . '/stepchat-e2e-leads.jsonl');

        [$status, $body] = $this->post(['flow' => 'lead', 'action' => 'start']);
        $this->assertSame(200, $status);
        $id = $body['session'];

        $turns = [
            ['flow' => 'lead', 'session' => $id, 'action' => 'turn', 'turn' => ['step' => 'name', 'type' => 'text', 'value' => 'Ada']],
            ['flow' => 'lead', 'session' => $id, 'action' => 'turn', 'turn' => ['step' => 'email', 'type' => 'text', 'value' => 'ada@example.com']],
            ['flow' => 'lead', 'session' => $id, 'action' => 'turn', 'turn' => ['step' => 'plan', 'type' => 'choice', 'value' => 'pro']],
            ['flow' => 'lead', 'session' => $id, 'action' => 'turn', 'turn' => ['step' => 'topics', 'type' => 'list', 'value' => ['api', 'pricing']]],
            ['flow' => 'lead', 'session' => $id, 'action' => 'turn', 'turn' => ['step' => 'phone', 'type' => 'skip', 'value' => null]],
            ['flow' => 'lead', 'session' => $id, 'action' => 'turn', 'turn' => ['step' => 'note', 'type' => 'skip', 'value' => null]],
        ];

        foreach ($turns as $turn) {
            [$status, $body] = $this->post($turn);
            $this->assertSame(200, $status);
        }

        $this->assertSame('review', $body['ui']['type']);

        [$status, $body] = $this->post(['flow' => 'lead', 'session' => $id, 'action' => 'turn', 'turn' => ['step' => 'done', 'type' => 'submit', 'value' => true]]);
        $this->assertSame(200, $status);
        $this->assertSame('completed', $body['status']);

        $leadsFile = sys_get_temp_dir() . '/stepchat-e2e-leads.jsonl';
        $this->assertFileExists($leadsFile);
        $lines = file($leadsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $last = json_decode(end($lines), true);
        $this->assertSame('Ada', $last['name']);
        $this->assertSame('ada@example.com', $last['email']);
        $this->assertSame('pro', $last['plan']);
        $this->assertSame(['api', 'pricing'], $last['topics']);
    }

    public function test_response_headers_and_content_type(): void
    {
        $this->post(['flow' => 'lead', 'action' => 'start']);
        $foundContentType = false;
        $foundCacheControl = false;
        foreach ($this->lastHeaders as $h) {
            if (preg_match('/^Content-Type:\s*application\/json/i', $h)) {
                $foundContentType = true;
            }
            if (preg_match('/^Cache-Control:\s*no-store/i', $h)) {
                $foundCacheControl = true;
            }
        }
        $this->assertTrue($foundContentType, 'Content-Type should be application/json');
        $this->assertTrue($foundCacheControl, 'Cache-Control should be no-store');
    }

    public function test_session_is_scoped_to_the_cookie(): void
    {
        [$status, $body] = $this->post(['flow' => 'lead', 'action' => 'start']);
        $id = $body['session'];

        $this->cookie = '';
        [$status, $body] = $this->post(['flow' => 'lead', 'session' => $id, 'action' => 'turn', 'turn' => ['step' => 'name', 'type' => 'text', 'value' => 'X']]);
        $this->assertSame(404, $status);
        $this->assertSame('Session not found.', $body['error']);
    }

    public function test_stale_step_over_http_is_409(): void
    {
        [$status, $body] = $this->post(['flow' => 'lead', 'action' => 'start']);
        $id = $body['session'];

        // current step is 'name', but we try to answer 'email'
        [$status, $body] = $this->post(['flow' => 'lead', 'session' => $id, 'action' => 'turn', 'turn' => ['step' => 'email', 'type' => 'text', 'value' => 'a@b.co']]);
        $this->assertSame(409, $status);
        $this->assertSame('name', $body['step']);
    }

    public function test_garbage_body_is_handled(): void
    {
        [$status, $body] = $this->postRaw('{not json');
        $this->assertSame(404, $status);
        $this->assertSame('Unknown flow.', $body['error']);
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The dashboard calls the API from listed origins and from its Vercel preview deployments
 * (config/cors.php). The config is re-read with the environment each test sets, as it is
 * on the server, and checked through a real preflight request.
 */
class CorsTest extends TestCase
{
    private const VARIABLES = ['CORS_ALLOWED_ORIGINS', 'CORS_ALLOWED_ORIGIN_PATTERNS'];

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->setEnvironment($name, null);
        }

        parent::tearDown();
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }

    /**
     * @param  array<string, ?string>  $environment
     */
    private function loadCorsConfig(array $environment): void
    {
        foreach ($environment as $name => $value) {
            $this->setEnvironment($name, $value);
        }

        config(['cors' => require config_path('cors.php')]);
    }

    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/v1/auth/login', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, authorization',
        ]);
    }

    private function assertAllowed(string $origin): void
    {
        $response = $this->preflight($origin);

        $this->assertSame(204, $response->getStatusCode(), $origin);
        $this->assertSame($origin, $response->headers->get('Access-Control-Allow-Origin'), $origin);
    }

    private function assertRejected(string $origin): void
    {
        $this->assertNotSame($origin, $this->preflight($origin)->headers->get('Access-Control-Allow-Origin'), $origin);
    }

    public function test_listed_origins_are_trimmed_and_empty_entries_dropped(): void
    {
        $this->loadCorsConfig(['CORS_ALLOWED_ORIGINS' => 'https://dashboard.playvo.test, https://admin.playvo.test ,,']);

        $this->assertSame(['https://dashboard.playvo.test', 'https://admin.playvo.test'], config('cors.allowed_origins'));
        $this->assertAllowed('https://dashboard.playvo.test');
        // Listed with spaces around it.
        $this->assertAllowed('https://admin.playvo.test');
        $this->assertRejected('https://other.playvo.test');
    }

    public function test_the_default_pattern_allows_only_this_teams_vercel_previews(): void
    {
        $this->loadCorsConfig(['CORS_ALLOWED_ORIGINS' => 'https://dashboard.playvo.test', 'CORS_ALLOWED_ORIGIN_PATTERNS' => null]);

        $this->assertSame(['#^https://frontend[a-z0-9-]*-playvo\.vercel\.app$#'], config('cors.allowed_origins_patterns'));

        $this->assertAllowed('https://frontend-web-git-some-branch-playvo.vercel.app');
        $this->assertAllowed('https://frontend-abc123-playvo.vercel.app');

        $this->assertRejected('https://evil-playvo.vercel.app.attacker.com');
        $this->assertRejected('https://frontend-x-other.vercel.app');
        $this->assertRejected('http://frontend-abc-playvo.vercel.app');
    }

    public function test_patterns_can_be_replaced_or_turned_off(): void
    {
        $this->loadCorsConfig([
            'CORS_ALLOWED_ORIGINS' => 'https://dashboard.playvo.test',
            'CORS_ALLOWED_ORIGIN_PATTERNS' => ' #^https://web-[a-z0-9]+\.example\.com$# , ',
        ]);

        $this->assertSame(['#^https://web-[a-z0-9]+\.example\.com$#'], config('cors.allowed_origins_patterns'));
        $this->assertAllowed('https://web-123.example.com');
        $this->assertRejected('https://frontend-abc123-playvo.vercel.app');

        $this->loadCorsConfig(['CORS_ALLOWED_ORIGIN_PATTERNS' => '']);

        $this->assertSame([], config('cors.allowed_origins_patterns'));
        $this->assertRejected('https://frontend-abc123-playvo.vercel.app');
    }
}

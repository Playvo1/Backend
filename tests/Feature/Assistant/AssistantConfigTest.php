<?php

namespace Tests\Feature\Assistant;

use Tests\TestCase;

class AssistantConfigTest extends TestCase
{
    private const VARIABLES = ['GEMINI_MODEL', 'GEMINI_BASE_URL', 'GEMINI_TIMEOUT', 'ASSISTANT_HOURLY_LIMIT'];

    /** @var array<string, array{0: mixed, 1: mixed, 2: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::VARIABLES as $name) {
            $this->saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$server, $env, $putenv]) {
            $server === null ? $this->forget($_SERVER, $name) : $_SERVER[$name] = $server;
            $env === null ? $this->forget($_ENV, $name) : $_ENV[$name] = $env;
            putenv($putenv === false ? $name : "{$name}={$putenv}");
        }

        parent::tearDown();
    }

    private function forget(array &$values, string $name): void
    {
        unset($values[$name]);
    }

    private function setVariable(string $name, string $value): void
    {
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }

    private function services(): array
    {
        return require config_path('services.php');
    }

    public function test_empty_env_values_fall_back_to_the_defaults(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->setVariable($name, '');
        }

        $services = $this->services();

        $this->assertSame(8, $services['gemini']['timeout']);
        $this->assertSame('gemini-2.5-flash', $services['gemini']['model']);
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta', $services['gemini']['base_url']);
        $this->assertSame(20, $services['assistant']['hourly_limit']);
    }

    public function test_set_env_values_are_used(): void
    {
        $this->setVariable('GEMINI_TIMEOUT', '5');
        $this->setVariable('ASSISTANT_HOURLY_LIMIT', '30');
        $this->setVariable('GEMINI_MODEL', 'gemini-2.5-flash-lite');

        $services = $this->services();

        $this->assertSame(5, $services['gemini']['timeout']);
        $this->assertSame(30, $services['assistant']['hourly_limit']);
        $this->assertSame('gemini-2.5-flash-lite', $services['gemini']['model']);
    }

    public function test_tests_never_have_a_gemini_key(): void
    {
        $this->assertFalse(filled(config('services.gemini.key')));
    }
}

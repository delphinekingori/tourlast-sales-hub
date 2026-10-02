<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GenerateTokenTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/env-'.uniqid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_it_writes_a_new_48_character_hex_token_and_prints_it_once(): void
    {
        $path = $this->envFile('APP_NAME=Tourlast'.PHP_EOL.'TOURLAST_API_TOKEN='.PHP_EOL);

        $exit = Artisan::call('hub:generate-token', ['--path' => $path]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/^TOURLAST_API_TOKEN=[0-9a-f]{48}\r?$/m', File::get($path));

        $token = $this->tokenFrom($path);
        $this->assertSame(1, substr_count($output, $token));
        $this->assertStringContainsString("Copy the same value into each source app's .env as TOURLAST_HUB_TOKEN.", $output);
        $this->assertStringNotContainsString('Replaces the previous token', $output);
    }

    public function test_it_appends_the_key_when_the_env_file_does_not_have_it(): void
    {
        $path = $this->envFile('APP_NAME=Tourlast'.PHP_EOL);

        Artisan::call('hub:generate-token', ['--path' => $path]);

        $this->assertMatchesRegularExpression('/^TOURLAST_API_TOKEN=[0-9a-f]{48}\r?$/m', File::get($path));
        $this->assertSame(1, substr_count(File::get($path), 'TOURLAST_API_TOKEN='));
    }

    public function test_it_replaces_the_existing_token_and_warns_that_every_app_must_be_updated(): void
    {
        $path = $this->envFile('APP_NAME=Tourlast'.PHP_EOL.'TOURLAST_API_TOKEN=ol'.PHP_EOL.'TOURLAST_WEBHOOK_SECRET='.PHP_EOL);

        Artisan::call('hub:generate-token', ['--path' => $path]);
        $output = Artisan::output();

        $this->assertSame(1, substr_count(File::get($path), 'TOURLAST_API_TOKEN='));
        $this->assertStringNotContainsString('ol'.PHP_EOL, File::get($path));
        $this->assertStringContainsString('Replaces the previous token', $output);
        $this->assertSame('TOURLAST_WEBHOOK_SECRET='.PHP_EOL, substr(File::get($path), -strlen('TOURLAST_WEBHOOK_SECRET='.PHP_EOL)));
    }

    public function test_show_prints_the_current_token_without_changing_the_file(): void
    {
        $path = $this->envFile('TOURLAST_API_TOKEN=abc123'.PHP_EOL);

        $exit = Artisan::call('hub:generate-token', ['--path' => $path, '--show' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('abc123', trim(Artisan::output()));
        $this->assertSame('TOURLAST_API_TOKEN=abc123'.PHP_EOL, File::get($path));
    }

    public function test_show_fails_when_no_token_is_set(): void
    {
        $path = $this->envFile('TOURLAST_API_TOKEN='.PHP_EOL);

        $exit = Artisan::call('hub:generate-token', ['--path' => $path, '--show' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not set', Artisan::output());
    }

    public function test_it_fails_when_the_env_file_does_not_exist(): void
    {
        $exit = Artisan::call('hub:generate-token', ['--path' => $this->dir.'/nope.env']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No env file found', Artisan::output());
    }

    public function test_it_never_touches_the_real_env_file(): void
    {
        $real = base_path('.env');
        $before = File::exists($real) ? hash_file('sha256', $real) : null;
        $path = $this->envFile('TOURLAST_API_TOKEN='.PHP_EOL);

        Artisan::call('hub:generate-token', ['--path' => $path]);

        $this->assertSame($before, File::exists($real) ? hash_file('sha256', $real) : null);
    }

    private function envFile(string $contents): string
    {
        $path = $this->dir.'/test.env';
        File::put($path, $contents);

        return $path;
    }

    private function tokenFrom(string $path): string
    {
        preg_match('/^TOURLAST_API_TOKEN=(.*)$/m', File::get($path), $matches);

        return trim($matches[1]);
    }
}

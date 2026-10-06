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
        $this->assertStringNotContainsString('Replaces the previous TOURLAST_API_TOKEN', $output);
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

        Artisan::call('hub:generate-token', ['--token' => true, '--path' => $path]);
        $output = Artisan::output();

        $this->assertSame(1, substr_count(File::get($path), 'TOURLAST_API_TOKEN='));
        $this->assertStringNotContainsString('ol'.PHP_EOL, File::get($path));
        $this->assertStringContainsString('Replaces the previous TOURLAST_API_TOKEN', $output);
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

    public function test_webhook_mode_writes_a_64_character_secret_and_leaves_the_sync_token_alone(): void
    {
        $path = $this->envFile('TOURLAST_API_TOKEN=keep-me'.PHP_EOL.'TOURLAST_WEBHOOK_SECRET='.PHP_EOL);

        $exit = Artisan::call('hub:generate-token', ['--webhook' => true, '--path' => $path]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/^TOURLAST_WEBHOOK_SECRET=[0-9a-f]{64}?$/m', File::get($path));
        $this->assertStringContainsString('TOURLAST_API_TOKEN=keep-me', File::get($path));
        $this->assertStringContainsString('TOURLAST_HUB_WEBHOOK_SECRET', $output);
    }

    public function test_webhook_mode_can_show_the_current_secret_and_warns_when_replacing_it(): void
    {
        $path = $this->envFile('TOURLAST_WEBHOOK_SECRET=abc123'.PHP_EOL);

        Artisan::call('hub:generate-token', ['--webhook' => true, '--show' => true, '--path' => $path]);
        $this->assertSame('abc123', trim(Artisan::output()));

        Artisan::call('hub:generate-token', ['--webhook' => true, '--path' => $path]);
        $this->assertStringContainsString('Replaces the previous TOURLAST_WEBHOOK_SECRET', Artisan::output());
    }

    public function test_without_a_flag_it_generates_both_the_sync_token_and_the_webhook_secret(): void
    {
        $path = $this->envFile('APP_NAME=Tourlast'.PHP_EOL);

        $exit = Artisan::call('hub:generate-token', ['--path' => $path]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/^TOURLAST_API_TOKEN=[0-9a-f]{48}?$/m', File::get($path));
        $this->assertMatchesRegularExpression('/^TOURLAST_WEBHOOK_SECRET=[0-9a-f]{64}?$/m', File::get($path));
        $this->assertStringContainsString('TOURLAST_HUB_TOKEN', $output);
        $this->assertStringContainsString('TOURLAST_HUB_WEBHOOK_SECRET', $output);
    }

    public function test_show_prints_both_values_by_name(): void
    {
        $path = $this->envFile('TOURLAST_API_TOKEN=tok'.PHP_EOL.'TOURLAST_WEBHOOK_SECRET=sec'.PHP_EOL);

        $exit = Artisan::call('hub:generate-token', ['--show' => true, '--path' => $path]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('TOURLAST_API_TOKEN=tok', $output);
        $this->assertStringContainsString('TOURLAST_WEBHOOK_SECRET=sec', $output);
    }

    public function test_show_still_prints_the_one_it_has_and_warns_about_the_other(): void
    {
        $path = $this->envFile('TOURLAST_API_TOKEN=tok'.PHP_EOL);

        $exit = Artisan::call('hub:generate-token', ['--show' => true, '--path' => $path]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('TOURLAST_API_TOKEN=tok', $output);
        $this->assertStringContainsString('TOURLAST_WEBHOOK_SECRET is not set', $output);
    }

    public function test_token_only_leaves_the_webhook_secret_alone(): void
    {
        $path = $this->envFile('TOURLAST_WEBHOOK_SECRET=keep-me'.PHP_EOL);

        Artisan::call('hub:generate-token', ['--token' => true, '--path' => $path]);

        $this->assertMatchesRegularExpression('/^TOURLAST_API_TOKEN=[0-9a-f]{48}?$/m', File::get($path));
        $this->assertStringContainsString('TOURLAST_WEBHOOK_SECRET=keep-me', File::get($path));
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

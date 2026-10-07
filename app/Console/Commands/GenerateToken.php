<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('hub:generate-token {--token : Only the shared sync token} {--webhook : Only the webhook signing secret} {--show : Print what is already in the env file, without changing it} {--path=.env : Env file to write to}')]
#[Description('Generate the shared sync token and the webhook signing secret the Hub and the source apps use, and write them into .env')]
class GenerateToken extends Command
{
    /**
     * The secrets shared with the source apps, by the key they live under here.
     * Replacing a value here is the only way to revoke it, so every app must be
     * updated with the new one.
     *
     * - TOURLAST_API_TOKEN: the Hub sends it to the source apps and they send it
     *   back as TOURLAST_HUB_TOKEN. No user account or API token is involved.
     * - TOURLAST_WEBHOOK_SECRET: the source apps sign their instant updates with
     *   it, as TOURLAST_HUB_WEBHOOK_SECRET.
     *
     * @var array<string, array{label: string, bytes: int, copyAs: string, failure: string}>
     */
    private const Secrets = [
        'TOURLAST_API_TOKEN' => [
            'label' => 'Shared sync token',
            'bytes' => 24,
            'copyAs' => 'TOURLAST_HUB_TOKEN',
            'failure' => 'their calls start failing with 401',
        ],
        'TOURLAST_WEBHOOK_SECRET' => [
            'label' => 'Webhook secret',
            'bytes' => 32,
            'copyAs' => 'TOURLAST_HUB_WEBHOOK_SECRET',
            'failure' => 'their instant updates are refused with 401',
        ],
    ];

    public function handle(): int
    {
        $path = (string) $this->option('path');

        if (! $this->isAbsolute($path)) {
            $path = base_path($path);
        }

        if (! File::exists($path)) {
            $this->components->error("No env file found at {$path}.");

            return self::FAILURE;
        }

        $keys = $this->selectedKeys();

        return $this->option('show') ? $this->show($path, $keys) : $this->generate($path, $keys);
    }

    /**
     * Both by default; one when only --token or --webhook is given.
     *
     * @return list<string>
     */
    private function selectedKeys(): array
    {
        $keys = array_keys(self::Secrets);

        if ($this->option('token') xor $this->option('webhook')) {
            return [$this->option('token') ? $keys[0] : $keys[1]];
        }

        return $keys;
    }

    /**
     * @param  list<string>  $keys
     */
    private function show(string $path, array $keys): int
    {
        $contents = File::get($path);
        $found = 0;

        foreach ($keys as $key) {
            $current = $this->valueIn($contents, $key);

            if ($current === null) {
                $this->components->warn($key.' is not set in '.$this->option('path').'.');

                continue;
            }

            $found++;
            $this->line(count($keys) === 1 ? $current : $key.'='.$current);
        }

        return $found > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $keys
     */
    private function generate(string $path, array $keys): int
    {
        $contents = File::get($path);
        $made = [];

        foreach ($keys as $key) {
            $previous = $this->valueIn($contents, $key) !== null;
            $value = bin2hex(random_bytes(self::Secrets[$key]['bytes']));
            $line = '/^'.$key.'=.*?\r?$/m';

            $contents = preg_match($line, $contents)
                ? preg_replace($line, $key.'='.$value, $contents, 1)
                : rtrim($contents).PHP_EOL.$key.'='.$value.PHP_EOL;

            $made[$key] = ['value' => $value, 'replaced' => $previous];
        }

        File::put($path, $contents);

        if (app()->configurationIsCached()) {
            File::delete(base_path('bootstrap/cache/config.php'));
            $this->components->info('Cleared the cached configuration so the new values are live.');
        }

        foreach ($made as $key => $secret) {
            $details = self::Secrets[$key];

            $this->components->info($details['label'].' written to '.$this->option('path').' (shown once):');
            $this->newLine();
            $this->line('  '.$secret['value']);
            $this->newLine();
            $this->line("  Copy the same value into each source app's .env as {$details['copyAs']}.");

            if ($secret['replaced']) {
                $this->newLine();
                $this->components->warn("Replaces the previous {$key}: update every app or {$details['failure']}.");
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function valueIn(string $contents, string $key): ?string
    {
        if (! preg_match('/^'.$key.'=(.*)$/m', $contents, $matches)) {
            return null;
        }

        $value = trim($matches[1], " \t\r\n\"'");

        return $value === '' ? null : $value;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}

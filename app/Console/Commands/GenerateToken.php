<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('hub:generate-token {--show : Print the token already in the env file, without changing it} {--path=.env : Env file to write to}')]
#[Description('Generate the one shared token the Hub and the source apps use for every sync call, and write it into .env')]
class GenerateToken extends Command
{
    /**
     * One secret, both directions: the Hub sends it to the source apps as
     * TOURLAST_API_TOKEN and they send it back as TOURLAST_HUB_TOKEN. No user
     * account or API token is involved, so replacing the value here is the
     * only way to revoke it — every app must be updated with the new one.
     */
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

        $contents = File::get($path);

        if ($this->option('show')) {
            $current = $this->tokenIn($contents);

            if ($current === null) {
                $this->components->warn('TOURLAST_API_TOKEN is not set in '.$this->option('path').'.');

                return self::FAILURE;
            }

            $this->line($current);

            return self::SUCCESS;
        }

        $previous = $this->tokenIn($contents);
        $token = bin2hex(random_bytes(24));

        $contents = preg_match('/^TOURLAST_API_TOKEN=.*?\r?$/m', $contents)
            ? preg_replace('/^TOURLAST_API_TOKEN=.*?\r?$/m', 'TOURLAST_API_TOKEN='.$token, $contents, 1)
            : rtrim($contents).PHP_EOL.'TOURLAST_API_TOKEN='.$token.PHP_EOL;

        File::put($path, $contents);

        if (app()->configurationIsCached()) {
            File::delete(base_path('bootstrap/cache/config.php'));
            $this->components->info('Cleared the cached configuration so the new token is live.');
        }

        $this->components->info('Shared token written to '.$this->option('path').' (shown once):');
        $this->newLine();
        $this->line('  '.$token);
        $this->newLine();
        $this->line('  Copy the same value into each source app\'s .env as TOURLAST_HUB_TOKEN.');

        if ($previous !== null) {
            $this->newLine();
            $this->components->warn('Replaces the previous token: update every app or their calls start failing with 401.');
        }

        return self::SUCCESS;
    }

    private function tokenIn(string $contents): ?string
    {
        if (! preg_match('/^TOURLAST_API_TOKEN=(.*)$/m', $contents, $matches)) {
            return null;
        }

        $token = trim($matches[1], " \t\r\n\"'");

        return $token === '' ? null : $token;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}

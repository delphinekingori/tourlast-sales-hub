<?php

namespace App\Console\Commands;

use App\Actions\IssueApiToken;
use App\Enums\ApiScope;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('hub:create-integration-account {--email=integration@tourlast.com : Account email} {--name=tourlast.com integration : Account name} {--rotate : Revoke the account\'s existing tokens first}')]
#[Description('Create the least-privilege account tourlast.com uses to push provider records, and print its API token once')]
class CreateIntegrationAccount extends Command
{
    /**
     * The account has no role and an unknown random password: it cannot use
     * the web app, and its only permission is pushing provider records.
     */
    public function handle(IssueApiToken $issueApiToken): int
    {
        $email = strtolower((string) $this->option('email'));

        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => (string) $this->option('name'),
            'password' => Str::password(48),
            'is_active' => true,
            'account_status' => 'active',
        ]);

        $user->syncRoles([]);
        $user->syncPermissions([Permission::PushProviderRecords->value]);

        if ($this->option('rotate')) {
            $revoked = $user->tokens()->delete();
            $this->components->warn("Revoked {$revoked} existing token(s).");
        }

        $token = $issueApiToken->handle($user, 'tourlast.com push', [ApiScope::IntegrationPush->value]);

        $this->components->info("Integration account ready: {$user->name} <{$user->email}>");
        $this->newLine();
        $this->line('  API token (shown once; store it as a secret on tourlast.com):');
        $this->newLine();
        $this->line('  '.$token->plainTextToken);
        $this->newLine();
        $this->line('  Send it as "Authorization: Bearer <token>" to '.rtrim((string) config('app.url'), '/').'/api/v1/integrations/tourlast/providers');

        return self::SUCCESS;
    }
}

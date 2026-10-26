<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('api-keys
    {action : issue | list | revoke}
    {--email= : Business (employer) email, required for "issue"}
    {--name= : Optional friendly label for the new key}
    {--key= : Key prefix or full token, required for "revoke"}')]
#[Description('Issue, list, or revoke external API keys for a business')]
class ManageApiKeys extends Command
{
    public function handle(): int
    {
        return match ($this->argument('action')) {
            'issue' => $this->issue(),
            'list' => $this->list(),
            'revoke' => $this->revoke(),
            default => $this->invalid(),
        };
    }

    protected function issue(): int
    {
        $email = $this->option('email');
        if (! $email) {
            $this->error('The --email option is required to issue a key.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->where('type', User::TYPE_EMPLOYEE)->first();
        if (! $user) {
            $this->error("No business account found for email {$email}.");

            return self::FAILURE;
        }

        [$key, $plain] = ApiKey::generate($user, $this->option('name'));

        $this->info('API key issued. Store it now — it cannot be shown again.');
        $this->newLine();
        $this->line('Business : ' . ($user->company_name ?? $user->name) . " (id {$user->id})");
        $this->line('Prefix   : ' . $key->key_prefix);
        $this->line('Token    : ' . $plain);

        return self::SUCCESS;
    }

    protected function list(): int
    {
        $keys = ApiKey::with('user:id,company_name,name,email')->latest()->get();

        if ($keys->isEmpty()) {
            $this->info('No API keys have been issued.');

            return self::SUCCESS;
        }

        $this->table(
            ['Prefix', 'Business', 'Name', 'Status', 'Last used'],
            $keys->map(fn (ApiKey $k) => [
                $k->key_prefix,
                $k->user->company_name ?? $k->user->name ?? $k->user->email,
                $k->name ?? '—',
                $k->isActive() ? 'active' : 'inactive',
                $k->last_used_at?->diffForHumans() ?? 'never',
            ])->all(),
        );

        return self::SUCCESS;
    }

    protected function revoke(): int
    {
        $value = trim((string) $this->option('key'));
        if ($value === '') {
            $this->error('The --key option is required to revoke a key.');

            return self::FAILURE;
        }

        $prefix = explode('_', $value)[1] ?? $value;
        $key = ApiKey::where('key_prefix', $prefix)->first();

        if (! $key) {
            $this->error("No API key found for prefix {$prefix}.");

            return self::FAILURE;
        }

        if ($key->revoked_at !== null) {
            $this->info("API key {$key->key_prefix} is already revoked.");

            return self::SUCCESS;
        }

        $key->forceFill(['revoked_at' => now()])->save();
        $this->info("API key {$key->key_prefix} revoked.");

        return self::SUCCESS;
    }

    protected function invalid(): int
    {
        $this->error('Unknown action. Use "issue", "list", or "revoke".');

        return self::FAILURE;
    }
}

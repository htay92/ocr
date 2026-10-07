<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserTokenCommand extends Command
{
    protected $signature = 'user:token
        {email : The owner account; created when it does not exist}
        {--password= : Password for a new account (asked, or generated when not interactive)}';

    protected $description = 'Create the owner account if needed and print a new API token';

    public function handle(): int
    {
        $user = User::firstWhere('email', $this->argument('email'));

        if ($user === null) {
            $password = $this->option('password')
                ?? ($this->input->isInteractive() ? $this->secret('Password for the new account') : null);

            if (! $password) {
                $password = Str::password(16);
                $this->warn("Generated password: {$password}");
            }

            $user = User::create([
                'name' => Str::before($this->argument('email'), '@'),
                'email' => $this->argument('email'),
                'password' => Hash::make($password),
            ]);
            $this->info("Created user {$user->email}.");
        }

        $this->line($user->createToken('api')->plainTextToken);

        return self::SUCCESS;
    }
}

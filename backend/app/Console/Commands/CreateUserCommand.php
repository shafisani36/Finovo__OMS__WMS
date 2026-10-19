<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create
                            {email? : The user email address}
                            {--role=operator : The user role (admin or operator)}
                            {--name= : The user full name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision a user account for Finovo OMS / WMS';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== Finovo OMS / WMS Account Provisioning ===');

        $email = $this->argument('email') ?: $this->ask('Enter user work email address');
        $name = $this->option('name') ?: $this->ask('Enter user full name', 'Finovo User');
        $roleInput = strtolower((string) ($this->option('role') ?: $this->choice('Select user role', ['operator', 'admin'], 0)));

        $role = $roleInput === 'admin' ? UserRole::ADMIN : UserRole::OPERATOR;

        // Prompt for password securely
        $password = $this->secret('Enter account password (minimum 12 characters)');
        $passwordConfirmation = $this->secret('Confirm account password');

        if ($password !== $passwordConfirmation) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
        ], [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:128'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error("- {$error}");
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => strtolower(trim($email)),
            'password' => Hash::make($password),
            'role' => $role,
            'status' => 'active',
            'otp_enabled' => true,
        ]);

        $this->info("User [{$user->email}] successfully created with role [{$user->role->label()}].");

        return self::SUCCESS;
    }
}

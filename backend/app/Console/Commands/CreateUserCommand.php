<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Organization;
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
                            {--role=operator : The user role (admin, manager, sales or operator)}
                            {--organization= : ID of the company the user joins (default: the only company, if there is just one)}
                            {--name= : The user full name}
                            {--password= : The user password (minimum 12 characters)}';

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
        $roleInput = strtolower((string) ($this->option('role') ?: $this->choice('Select user role', array_column(UserRole::cases(), 'value'), 0)));
        $role = UserRole::tryFrom($roleInput);

        if (! $role) {
            $this->error('Unknown role. Use one of: '.implode(', ', array_column(UserRole::cases(), 'value')).'.');

            return self::FAILURE;
        }

        // Join an existing company instead of getting an empty one of their own on first sign-in.
        $organization = $this->option('organization')
            ? Organization::find($this->option('organization'))
            : (Organization::count() === 1 ? Organization::first() : null);

        if ($this->option('organization') && ! $organization) {
            $this->error('No company has that ID.');

            return self::FAILURE;
        }

        // Prompt for password securely or use provided option
        $password = (string) ($this->option('password') ?: $this->secret('Enter account password (minimum 12 characters)'));
        if (! $this->option('password')) {
            $passwordConfirmation = (string) $this->secret('Confirm account password');

            if ($password !== $passwordConfirmation) {
                $this->error('Password confirmation does not match.');

                return self::FAILURE;
            }
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
            'current_organization_id' => $organization?->id,
        ]);

        if ($organization) {
            $user->memberships()->create(['organization_id' => $organization->id, 'role' => $role->value]);
        }

        $this->info("User [{$user->email}] successfully created with role [{$user->role->label()}].");

        return self::SUCCESS;
    }
}

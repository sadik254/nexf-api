<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;

class CreateSuperAdmin extends Command
{
    protected $signature = 'admin:create-super
                            {--name= : Name of the super admin}
                            {--email= : Email address}
                            {--password= : Password}
                            {--phone= : Phone number}
                            {--address= : Address}
                            {--image= : Image URL}';

    protected $description = 'Create the initial super admin account (one-time).';

    public function handle(): int
    {
        if (Admin::query()->where('role', 'super_admin')->exists()) {
            $this->error('A super_admin already exists.');
            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password') ?: $this->secret('Password');
        $phone = $this->option('phone') ?: $this->ask('Phone (optional)', '');
        $address = $this->option('address') ?: $this->ask('Address (optional)', '');
        $image = $this->option('image') ?: $this->ask('Image URL (optional)', '');

        if (!$name || !$email || !$password) {
            $this->error('Name, email, and password are required.');
            return self::FAILURE;
        }

        if (Admin::query()->where('email', $email)->exists()) {
            $this->error('Email already in use.');
            return self::FAILURE;
        }

        if ($phone !== '' && Admin::query()->where('phone', $phone)->exists()) {
            $this->error('Phone already in use.');
            return self::FAILURE;
        }

        Admin::create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'password' => $password,
            'address' => $address !== '' ? $address : null,
            'image' => $image !== '' ? $image : null,
            'role' => 'super_admin',
        ]);

        $this->info('Super admin created successfully.');

        return self::SUCCESS;
    }
}

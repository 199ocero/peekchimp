<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return Cache::lock('first-admin-registration', 10)->block(5, function () use ($input): User {
            if (User::query()->where('is_admin', true)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'Registration is available by invitation only.',
                ]);
            }

            return User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'is_admin' => true,
            ]);
        });
    }
}

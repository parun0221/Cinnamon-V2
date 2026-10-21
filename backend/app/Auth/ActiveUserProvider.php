<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Resolves the authenticated user from a session or a remember cookie.
 *
 * The stock Eloquent provider looks the user up by id only, so a user whose
 * account was deactivated keeps working on the session they already hold.
 * Blocking the login is not enough — deactivating an account has to end the
 * access it already granted.
 */
class ActiveUserProvider extends EloquentUserProvider
{
    public function __construct(Hasher $hasher, string $model)
    {
        parent::__construct($hasher, $model);
    }

    /**
     * @param  mixed  $identifier
     */
    public function retrieveById($identifier): ?User
    {
        $model = $this->createModel();

        $user = $this->newModelQuery($model)
            ->where($model->getAuthIdentifierName(), $identifier)
            ->where('is_active', true)
            ->first();

        return $user instanceof User ? $user : null;
    }

    /**
     * @param  mixed  $identifier
     */
    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?User
    {
        $model = $this->createModel();

        $user = $this->newModelQuery($model)
            ->where($model->getAuthIdentifierName(), $identifier)
            ->where('is_active', true)
            ->first();

        if (! $user) {
            return null;
        }

        $rememberToken = $user->getRememberToken();

        return $rememberToken && hash_equals($rememberToken, $token) ? $user : null;
    }
}

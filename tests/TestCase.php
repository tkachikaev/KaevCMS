<?php

namespace Tests;

use App\Models\Admin;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(UserContract $user, $guard = null)
    {
        if ($guard === 'admin' && $user instanceof Admin && $user->exists) {
            $user->refresh();
        }

        parent::actingAs($user, $guard);

        if ($guard === 'admin' && $user instanceof Admin) {
            $this->withSession(['admin_session_version' => $user->session_version]);
        }

        return $this;
    }
}

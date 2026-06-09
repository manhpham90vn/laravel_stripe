<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    public function test_is_admin_reflects_the_role_column(): void
    {
        $this->assertTrue((new User(['role' => User::ROLE_ADMIN]))->isAdmin());
        $this->assertFalse((new User(['role' => User::ROLE_USER]))->isAdmin());
        $this->assertFalse((new User)->isAdmin());
    }
}

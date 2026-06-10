<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * Controller cơ sở. Nạp sẵn 2 trait Laravel: AuthorizesRequests (cho
 * $this->authorize()) và ValidatesRequests (cho $this->validate()).
 */
abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;
}

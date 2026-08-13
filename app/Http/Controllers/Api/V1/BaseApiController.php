<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponseTrait;

abstract class BaseApiController extends Controller
{
    use ApiResponseTrait;
}

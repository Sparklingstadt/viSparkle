<?php

namespace App\Http\Requests\Creator;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    use ProductRules;
}

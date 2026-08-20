<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'location', 'email', 'phone', 'tech_number', 'momo_number', 'momo_name', 'logo'])]
#[RouteKey('uuid')]
class Company extends Model
{
    use HasUuid;
}

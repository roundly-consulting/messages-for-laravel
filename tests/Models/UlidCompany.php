<?php

declare(strict_types=1);

namespace RoundlyConsulting\Messages\Tests\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class UlidCompany extends Model
{
    use HasUlids;

    protected $guarded = [];

    public $timestamps = false;
}

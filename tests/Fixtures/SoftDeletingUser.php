<?php

namespace Pr4w\Telemetry\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SoftDeletingUser extends Model
{
    use SoftDeletes;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

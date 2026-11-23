<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SoftPost extends Model
{
    use SoftDeletes;

    protected $table = 'posts';

    protected $guarded = [];
}

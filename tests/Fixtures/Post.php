<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $table = 'posts';

    protected $guarded = [];
}

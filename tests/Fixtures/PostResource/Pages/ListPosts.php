<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Tests\Fixtures\PostResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\PostResource;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;
}

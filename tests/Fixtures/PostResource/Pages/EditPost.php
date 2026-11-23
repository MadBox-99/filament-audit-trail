<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Tests\Fixtures\PostResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\PostResource;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

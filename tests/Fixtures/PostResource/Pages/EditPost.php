<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Tests\Fixtures\PostResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\Post;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\PostResource;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            // Modal-less: Filament runs it inside mountAction().
            Action::make('approve')
                ->action(fn (Post $record) => $record->update(['title' => 'Approved'])),
            // Modal-less, but fails validation while running.
            Action::make('reject')
                ->action(fn () => throw ValidationException::withMessages(['reason' => 'No.'])),
            Action::make('locked')
                ->disabled()
                ->action(fn (Post $record) => $record->update(['title' => 'Locked'])),
            Action::make('rename')
                ->schema([TextInput::make('new_title')->required()])
                ->action(fn (array $data, Post $record) => $record->update(['title' => $data['new_title']])),
        ];
    }
}

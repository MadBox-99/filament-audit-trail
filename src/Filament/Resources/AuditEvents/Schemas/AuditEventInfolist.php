<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Filament\Resources\AuditEvents\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class AuditEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('occurred_at')->label(__('audit-trail::audit-trail.columns.occurred_at'))->dateTime('Y-m-d H:i:s'),
                TextEntry::make('event')->label(__('audit-trail::audit-trail.columns.event'))->badge(),
                TextEntry::make('user_email')->label(__('audit-trail::audit-trail.columns.user'))->placeholder('-'),
                TextEntry::make('team_id')->label(__('audit-trail::audit-trail.columns.team'))->placeholder('-'),
                TextEntry::make('subject_type')->label(__('audit-trail::audit-trail.columns.subject_type'))->placeholder('-'),
                TextEntry::make('subject_id')->label(__('audit-trail::audit-trail.columns.subject_id'))->placeholder('-'),
                TextEntry::make('action')->label(__('audit-trail::audit-trail.columns.action'))->placeholder('-'),
                TextEntry::make('url')->label(__('audit-trail::audit-trail.columns.url'))->placeholder('-'),
                TextEntry::make('ip')->label(__('audit-trail::audit-trail.columns.ip'))->placeholder('-'),
                TextEntry::make('source')->label(__('audit-trail::audit-trail.columns.source'))->badge(),
            ]),
            Section::make(__('audit-trail::audit-trail.columns.properties'))->schema([
                KeyValueEntry::make('properties')->hiddenLabel()->placeholder('-'),
            ]),
            Section::make(__('audit-trail::audit-trail.columns.evidence'))->schema([
                KeyValueEntry::make('evidence')->hiddenLabel()->placeholder('-'),
            ]),
        ]);
    }
}

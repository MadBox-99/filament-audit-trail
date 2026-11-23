<?php

declare(strict_types=1);

use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Models\AuditEvent;
use Madbox99\FilamentAuditTrail\Reconstruction\Rules\ImportExportRule;
use Madbox99\FilamentAuditTrail\Tests\Fixtures\User;

it('reconstructs imports and exports with their user and time', function (): void {
    $user = insertUser(['email' => 'a@corp.test']);
    $import = (int) DB::table('imports')->insertGetId([
        'file_name' => 'a.csv', 'file_path' => 'x/a.csv', 'importer' => 'App\\Imp', 'total_rows' => 3,
        'user_id' => $user, 'created_at' => '2026-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:05',
    ]);
    $export = (int) DB::table('exports')->insertGetId([
        'file_disk' => 'local', 'exporter' => 'App\\Exp', 'total_rows' => 9,
        'user_id' => $user, 'created_at' => '2026-03-02 11:00:00', 'updated_at' => '2026-03-02 11:00:00',
    ]);

    $report = reconstruct([new ImportExportRule]);

    $imp = AuditEvent::query()->where('evidence_key', 'imports:'.$import)->sole();
    expect($imp->event)->toBe(AuditEventType::Import)
        ->and($imp->user_id)->toBe($user)
        ->and($imp->user_email)->toBe('a@corp.test')
        ->and($imp->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-03-01 10:00:00')
        ->and($imp->subject_type)->toBe(Import::class)
        ->and($imp->subject_id)->toBe((string) $import)
        ->and($imp->properties)->toBe(['file_name' => 'a.csv', 'total_rows' => 3, 'importer' => 'App\\Imp'])
        ->and($imp->evidence)->toMatchArray(['rule' => 'imports_exports', 'table' => 'imports', 'id' => $import]);

    $exp = AuditEvent::query()->where('evidence_key', 'exports:'.$export)->sole();
    expect($exp->event)->toBe(AuditEventType::Export)
        ->and($exp->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-03-02 11:00:00')
        ->and($exp->subject_type)->toBe(Export::class)
        ->and($exp->properties)->toBe(['exporter' => 'App\\Exp', 'total_rows' => 9])
        ->and($report->rule('imports_exports')['inserted'])->toBe(2);
});

it('skips missing import and export tables', function (): void {
    Schema::drop('imports');
    Schema::drop('exports');

    expect(reconstruct([new ImportExportRule])->rule('imports_exports')['found'])->toBe(0);
});

it('attributes polymorphic import and export users only to the user model', function (): void {
    Schema::table('imports', fn (Blueprint $t) => $t->string('user_type')->nullable());
    Schema::table('exports', fn (Blueprint $t) => $t->string('user_type')->nullable());
    $user = insertUser();
    $row = ['file_name' => 'a.csv', 'file_path' => 'x', 'importer' => 'I', 'total_rows' => 1, 'user_id' => $user,
        'created_at' => '2026-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00'];
    $mine = (int) DB::table('imports')->insertGetId([...$row, 'user_type' => User::class]);
    DB::table('imports')->insert([...$row, 'user_type' => 'App\\Models\\Admin']);
    DB::table('exports')->insert(['file_disk' => 'l', 'exporter' => 'E', 'total_rows' => 1, 'user_id' => $user,
        'user_type' => 'App\\Models\\Admin', 'created_at' => '2026-03-01 10:00:00', 'updated_at' => '2026-03-01 10:00:00']);

    $report = reconstruct([new ImportExportRule]);

    expect($report->rule('imports_exports')['found'])->toBe(1)
        ->and(AuditEvent::query()->sole()->evidence_key)->toBe('imports:'.$mine);
});

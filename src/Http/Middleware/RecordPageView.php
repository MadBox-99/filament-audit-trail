<?php

declare(strict_types=1);

namespace Madbox99\FilamentAuditTrail\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Madbox99\FilamentAuditTrail\Enums\AuditEventType;
use Madbox99\FilamentAuditTrail\Support\AuditRecorder;
use Symfony\Component\HttpFoundation\Response;

final readonly class RecordPageView
{
    public function __construct(private AuditRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldRecord($request, $response)) {
            $this->recorder->record(AuditEventType::PageView);
        }

        return $response;
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $request->hasHeader('X-Livewire')) {
            return false;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }

        if (! auth()->guard((string) config('audit-trail.guard', 'web'))->check()) {
            return false;
        }

        /** @var list<string> $patterns */
        $patterns = (array) config('audit-trail.page_views.exclude_paths', []);

        return ! $request->is(...$patterns);
    }
}

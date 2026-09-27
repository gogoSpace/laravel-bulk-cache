<?php

namespace GogoSpace\BulkCache\Http;

use Closure;
use GogoSpace\BulkCache\RefreshScheduler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RefreshMiddleware
{
    public function __construct(private RefreshScheduler $scheduler) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->scheduler->beginRequest();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->scheduler->finish($response->getStatusCode());
    }
}

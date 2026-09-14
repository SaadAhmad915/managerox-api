<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects webhook posts that were not signed by Meta.
 *
 * Meta signs the raw request body with the app secret and sends the digest as
 * X-Hub-Signature-256. Without this check the endpoint is an open door: anyone
 * who discovers the URL could inject leads into the CRM.
 *
 * The comparison must use hash_equals — a plain === leaks timing information
 * that can be used to forge a signature byte by byte.
 */
class VerifyMetaSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.meta.app_secret');

        if (blank($secret)) {
            // Fail closed. A missing secret must never mean "accept everything".
            return response()->json(
                ['message' => 'Meta app secret is not configured.'],
                500,
            );
        }

        $header = $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($header, 'sha256=')) {
            return response()->json(['message' => 'Missing signature.'], 401);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, substr($header, 7))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return $next($request);
    }
}

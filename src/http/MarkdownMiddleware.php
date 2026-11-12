<?php

declare(strict_types=1);

namespace johnfmorton\llmready\http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use johnfmorton\llmready\LlmReady;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Content negotiation and the discovery Link header on Craft CMS 6.
 *
 * Craft 6 serves entry pages from Laravel routes, which never fire Yii's
 * EVENT_BEFORE_ACTION (the Craft 5 hook for serving Markdown on the canonical
 * URL) and send a Laravel response, so headers added to Yii's response object
 * are lost. This middleware runs on Craft's site route group instead:
 *
 * - before the page renders, it serves Markdown when
 *   LlmReady::negotiatedMarkdown() says the request wants it (shared with
 *   the Craft 5 path);
 * - after it renders, it adds the `Link: <…md>; rel="alternate"` header that
 *   the discovery handler computed while the template rendered.
 *
 * Only loaded on Craft 6: it depends on Laravel classes that Craft 5 doesn't
 * have, so it's excluded from PHPStan's Craft 5 analysis (see phpstan.neon).
 */
class MarkdownMiddleware
{
    public static function register(): void
    {
        app(Router::class)->pushMiddlewareToGroup('craft.web', self::class);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $plugin = LlmReady::getInstance();

        if ($request->isMethod('GET')) {
            $markdown = $plugin->negotiatedMarkdown();
            if ($markdown !== null) {
                return new Response($markdown['content'], 200, $markdown['headers']);
            }
        }

        $plugin->pendingLinkHeader = null;
        $response = $next($request);

        if ($plugin->pendingLinkHeader !== null && $response instanceof SymfonyResponse) {
            // Append, so any other Link values on the response are kept.
            $response->headers->set('Link', $plugin->pendingLinkHeader, false);
        }
        $plugin->pendingLinkHeader = null;

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base class for every controller.
 *
 * Controllers stay thin: read input from $this->request, call a model or
 * service, return a Response. Multi-table logic lives in app/Services.
 */
abstract class Controller
{
    public function __construct(protected readonly Request $request)
    {
    }

    /**
     * Renders a view inside a layout, adding the data every layout needs.
     *
     * @param array<string, mixed> $data
     */
    protected function view(string $template, array $data = [], int $status = 200, string $layout = 'layouts/app'): Response
    {
        $user = Auth::user();
        $data += [
            'title'           => 'Koinon',
            'activeNav'       => '',
            'scripts'         => [],
            'currentUserName' => $user['full_name'] ?? null,
            'currentRole'     => Auth::roleLabel(),
            'isSuperAdmin'    => Auth::isSuperAdmin(),
            'tenantName'      => TenantContext::name(),
            'flashes'         => Session::pullFlashes(['success', 'warning', 'error']),
        ];

        return Response::html(View::render($template, $data, $layout), $status);
    }

    /** JSON response for fetch() endpoints. */
    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /** Redirect to a path inside the application. */
    protected function redirect(string $path): Response
    {
        return Response::redirect($path);
    }

    /**
     * Aborts with 403 unless the user has one of the roles. Use it inside
     * actions as defence in depth, or when the rule depends on the record.
     *
     * @param list<string> $roles
     * @throws HttpException
     */
    protected function requireRole(array $roles): void
    {
        if (!Auth::hasRole($roles)) {
            throw new HttpException(403);
        }
    }

    /**
     * The authenticated user (routes using this are behind the "auth" middleware).
     *
     * @return array<string, mixed>
     * @throws HttpException
     */
    protected function user(): array
    {
        return Auth::user() ?? throw new HttpException(401);
    }
}

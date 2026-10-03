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
            'navigation'      => $user === null ? [] : Navigation::items(),
            'flashes'         => Session::pullFlashes(['success', 'warning', 'error']),
            // Field errors and previous input after a failed form POST (see invalid()).
            'errors'          => Session::pullFlashData('errors'),
            'old'             => Session::pullFlashData('old'),
        ];

        return Response::html(View::render($template, $data, $layout), $status);
    }

    /**
     * Answers a request whose input failed validation or a business rule.
     *
     * - fetch()/API clients get JSON {"error": ..., "errors": {field: message}}
     *   with the given status (422 invalid data, 409 conflict with current state).
     * - HTML forms are redirected back with the errors and the submitted input
     *   flashed, so the form is redisplayed filled in (Post/Redirect/Get).
     *
     * @param array<string, string> $errors field => message
     * @param list<string>          $keepInput fields to re-fill (never passwords or documents)
     */
    protected function invalid(array $errors, string $redirectTo, int $status = 422, array $keepInput = []): Response
    {
        $summary = $status === 409
            ? (string) (reset($errors) ?: 'A operação conflita com o estado atual.')
            : 'Corrija os campos destacados e tente novamente.';

        if ($this->request->wantsJson()) {
            return $this->json(['error' => $summary, 'errors' => $errors], $status);
        }

        $old = [];
        foreach ($keepInput as $field) {
            $old[$field] = $this->request->string($field);
        }
        Session::flashData('errors', $errors);
        Session::flashData('old', $old);
        Session::flash('error', $summary);

        return $this->redirect($redirectTo);
    }

    /**
     * Success answer for both kinds of client: JSON payload, or flash + redirect.
     *
     * @param array<string, mixed> $payload
     */
    protected function done(string $message, string $redirectTo, array $payload = [], int $status = 200): Response
    {
        if ($this->request->wantsJson()) {
            return $this->json(['message' => $message] + $payload, $status);
        }
        Session::flash('success', $message);

        return $this->redirect($redirectTo);
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

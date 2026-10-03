<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;
use Throwable;

/**
 * Renders PHP templates from app/Views, optionally inside a layout.
 *
 * Templates must print every dynamic value through e(). The only exception is
 * $content inside a layout: it is the HTML produced by an already-escaped
 * template, so escaping it again would print tags as text.
 */
final class View
{
    /**
     * @param string               $template e.g. "dashboard/index" (app/Views/dashboard/index.php)
     * @param array<string, mixed> $data     Variables available inside the template.
     * @param string|null          $layout   Layout template, or null for a bare fragment (e-mails).
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }

        return self::renderFile($layout, ['content' => $content] + $data);
    }

    /** @param array<string, mixed> $data */
    private static function renderFile(string $template, array $data): string
    {
        // Template names come from code, but the whitelist also rules out "../" tricks.
        if (preg_match('#^[a-z0-9_\-/]+$#i', $template) !== 1 || str_contains($template, '..')) {
            throw new LogicException(sprintf('Invalid view name "%s".', $template));
        }

        $file = BASE_PATH . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new LogicException(sprintf('View "%s" not found.', $template));
        }

        // A static closure gives the template its own scope: it sees only $data,
        // never $this or the renderer's local variables.
        $render = static function (string $__file, array $__data): void {
            extract($__data, EXTR_SKIP);
            require $__file;
        };

        ob_start();
        try {
            $render($file, $data);
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}

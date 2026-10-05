<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/**
 * Shared input validation for controllers.
 *
 * Each method reads one field from the request, records a user-facing error
 * when the value is invalid, and returns the normalised value (or null). The
 * controller then checks fails() once:
 *
 *   $v = new Validator($this->request);
 *   $unitId = $v->id('unit_id', 'Unidade');
 *   $amount = $v->money('amount', 'Valor');
 *   if ($v->fails()) {
 *       return $this->invalid($v->errors(), '/finance');
 *   }
 *
 * Validation produces typed, canonical values (int, 'Y-m-d', '1234.50').
 * It does not HTML-escape anything: escaping happens at output time with e(),
 * so data is stored as the user typed it and rendered safely everywhere.
 */
final class Validator
{
    /** @var array<string, string> field => message */
    private array $errors = [];

    public function __construct(private readonly Request $request)
    {
    }

    /** Trimmed text with a length range (in characters, not bytes). */
    public function string(string $field, string $label, int $min = 1, int $max = 255, bool $required = true): ?string
    {
        $value = trim($this->request->string($field));
        if ($value === '') {
            if ($required) {
                $this->errors[$field] = "{$label} é obrigatório.";
            }

            return null;
        }
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            $this->errors[$field] = "{$label} deve ter entre {$min} e {$max} caracteres.";

            return null;
        }

        return $value;
    }

    /** A positive integer id (e.g. a unit or area chosen in a <select>). */
    public function id(string $field, string $label): ?int
    {
        $value = $this->request->string($field);
        if (preg_match('/^[1-9]\d{0,9}$/', $value) !== 1) {
            $this->errors[$field] = "Selecione {$label}.";

            return null;
        }

        return (int) $value;
    }

    /** An integer within a range. */
    public function integer(string $field, string $label, int $min, int $max, ?int $default = null): ?int
    {
        $value = $this->request->string($field);
        if ($value === '' && $default !== null) {
            return $default;
        }
        if (preg_match('/^-?\d{1,9}$/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            $this->errors[$field] = "{$label} deve ser um número entre {$min} e {$max}.";

            return null;
        }

        return (int) $value;
    }

    /**
     * One of a fixed list of values (whitelist).
     *
     * @param list<string> $allowed
     */
    public function enum(string $field, string $label, array $allowed): ?string
    {
        $value = $this->request->string($field);
        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] = "{$label} inválido.";

            return null;
        }

        return $value;
    }

    /** A calendar date in Y-m-d (the format of <input type="date">). */
    public function date(string $field, string $label): ?string
    {
        return $this->dateTimeFormat($field, $label, 'Y-m-d');
    }

    /** A time in H:i (the format of <input type="time">). */
    public function time(string $field, string $label): ?string
    {
        return $this->dateTimeFormat($field, $label, 'H:i');
    }

    /** A month in Y-m (the format of <input type="month">); returned as the first day, Y-m-01. */
    public function month(string $field, string $label): ?string
    {
        $value = $this->dateTimeFormat($field, $label, 'Y-m');

        return $value === null ? null : $value . '-01';
    }

    /**
     * A money amount, returned as a canonical decimal STRING ("1234.50").
     *
     * Floats are never involved: 0.1 + 0.2 !== 0.3 in binary floating point,
     * which is unacceptable for money. The string is bound to the DECIMAL(12,2)
     * column as-is. Accepts "1234.5", "1234,50" and "1.234,50".
     */
    public function money(string $field, string $label, string $min = '0.01', string $max = '9999999999.99'): ?string
    {
        $raw = str_replace([' ', "\u{00A0}", 'R$'], '', $this->request->string($field));
        if (str_contains($raw, ',')) {
            $raw = str_replace(['.', ','], ['', '.'], $raw); // pt-BR: "." thousands, "," decimals
        }

        if (preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $raw, $m) !== 1) {
            $this->errors[$field] = "{$label} inválido. Use o formato 1234,56.";

            return null;
        }

        $normalized = ltrim($m[1], '0') ?: '0';
        $normalized .= '.' . str_pad($m[2] ?? '', 2, '0');

        if (self::cents($normalized) < self::cents($min) || self::cents($normalized) > self::cents($max)) {
            $this->errors[$field] = "{$label} deve estar entre R$ " . self::formatBr($min) . ' e R$ ' . self::formatBr($max) . '.';

            return null;
        }

        return $normalized;
    }

    /**
     * An e-mail address, returned trimmed and lower-cased (the form users.email
     * is stored in). filter_var() also rejects header-injection characters
     * such as CR/LF, which matters because the address is used by the mailer.
     */
    public function email(string $field, string $label = 'E-mail'): ?string
    {
        $value = mb_strtolower(trim($this->request->string($field)));
        if ($value === '' || mb_strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = "{$label} inválido.";

            return null;
        }

        return $value;
    }

    /**
     * A new password and its confirmation (config/security.php limits).
     *
     * Returns the raw password for the caller to hash; it is never trimmed,
     * because spaces are legitimate password characters. The comparison uses
     * hash_equals() so its timing does not depend on where the strings differ.
     */
    public function newPassword(string $field = 'password', string $confirmation = 'password_confirmation'): ?string
    {
        $password = $this->request->string($field);
        $min = (int) Config::get('security.password_min', 10);
        $max = (int) Config::get('security.password_max', 128);

        if (mb_strlen($password) < $min || mb_strlen($password) > $max) {
            $this->errors[$field] = "A senha deve ter entre {$min} e {$max} caracteres.";

            return null;
        }
        if (!hash_equals($password, $this->request->string($confirmation))) {
            $this->errors[$confirmation] = 'As senhas não conferem.';

            return null;
        }

        return $password;
    }

    /** Optional phone number: digits, spaces and + ( ) - only, 8 to 30 characters. */
    public function phone(string $field, string $label = 'Telefone'): ?string
    {
        $value = trim($this->request->string($field));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[0-9+()\s-]{8,30}$/', $value) !== 1) {
            $this->errors[$field] = "{$label} inválido.";

            return null;
        }

        return $value;
    }

    /** A local date and time in Y-m-d\TH:i (the format of <input type="datetime-local">). */
    public function dateTimeLocal(string $field, string $label): ?string
    {
        return $this->dateTimeFormat($field, $label, 'Y-m-d\TH:i');
    }

    /** True when the field was sent with a non-blank value (for optional fields). */
    public function filled(string $field): bool
    {
        return trim($this->request->string($field)) !== '';
    }

    /** Adds an error found by a later business check (e.g. "date in the past"). */
    public function addError(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Integer cents of a canonical decimal string; exact, no float. */
    private static function cents(string $decimal): int
    {
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function formatBr(string $decimal): string
    {
        [$whole, $fraction] = explode('.', $decimal);

        return number_format((int) $whole, 0, ',', '.') . ',' . $fraction;
    }

    private function dateTimeFormat(string $field, string $label, string $format): ?string
    {
        $value = $this->request->string($field);
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value);
        // Round-trip check rejects overflow such as 2026-02-31 (which PHP would roll into March).
        if ($parsed === false || $parsed->format($format) !== $value) {
            $this->errors[$field] = "{$label} inválido.";

            return null;
        }

        return $value;
    }
}

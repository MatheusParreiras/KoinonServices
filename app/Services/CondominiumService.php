<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Models\Condominium;
use PDOException;
use Throwable;

/**
 * Tenant registry operations of the Super Admin (Phase 5).
 *
 * Every method here is reached only through the "platform" middleware
 * (Super Admin). The condominium id comes from the URL; each method loads the
 * row first (404 when missing) and every change is written to the audit log
 * with that condominium id, so platform actions on a tenant are traceable.
 */
final class CondominiumService
{
    /** Signup-code alphabet without look-alikes (0/O, 1/I/L). */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly Request $request,
        private readonly Condominium $condominiums = new Condominium()
    ) {
    }

    /**
     * @param array<string, mixed> $data Validated fields (see Platform\CondominiumController::validated()).
     * @throws BusinessRuleException 409 on a duplicate CNPJ.
     */
    public function create(array $data): int
    {
        if ($data['legal_id'] !== null && $this->condominiums->isTaken('legal_id', $data['legal_id'])) {
            throw new BusinessRuleException('Já existe um condomínio com este CNPJ.', 409, 'legal_id');
        }

        try {
            return Database::transaction(function () use ($data): int {
                $data['slug'] = $this->uniqueSlug((string) $data['name']);
                $data['signup_code'] = $this->uniqueSignupCode();
                $id = $this->condominiums->insert($data);
                (new AuditLogger($this->request))->platform('condominium.created', $id, 'condominium', $id, [
                    'name' => $data['name'],
                    'plan' => $data['plan'],
                ]);

                return $id;
            });
        } catch (PDOException $e) {
            throw self::duplicateOr($e);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @throws BusinessRuleException
     */
    public function update(int $id, array $data): void
    {
        if ($data['legal_id'] !== null && $this->condominiums->isTaken('legal_id', $data['legal_id'], $id)) {
            throw new BusinessRuleException('Já existe um condomínio com este CNPJ.', 409, 'legal_id');
        }

        try {
            Database::transaction(function () use ($id, $data): void {
                $before = $this->condominiums->lockForUpdate($id) ?? throw new BusinessRuleException('Condomínio não encontrado.', 404);
                $this->condominiums->update($id, $data);
                $changed = array_keys(array_filter(
                    $data,
                    static fn (mixed $value, string $column): bool => (string) $before[$column] !== (string) $value,
                    ARRAY_FILTER_USE_BOTH
                ));
                if ($changed !== []) {
                    (new AuditLogger($this->request))->platform('condominium.updated', $id, 'condominium', $id, ['fields' => $changed]);
                }
            });
        } catch (PDOException $e) {
            throw self::duplicateOr($e);
        }
    }

    /**
     * Suspends a tenant. Data is kept; its users are logged out on their next
     * request (Membership queries require c.status = 'active') and cannot log in.
     *
     * @throws BusinessRuleException
     */
    public function suspend(int $id, string $reason): void
    {
        Database::transaction(function () use ($id, $reason): void {
            $condominium = $this->condominiums->lockForUpdate($id) ?? throw new BusinessRuleException('Condomínio não encontrado.', 404);
            if ($condominium['status'] !== 'active') {
                throw new BusinessRuleException('Somente condomínios ativos podem ser suspensos.', 409);
            }
            $this->condominiums->suspend($id, $reason);
            (new AuditLogger($this->request))->platform('condominium.suspended', $id, 'condominium', $id, ['reason' => $reason]);
        });
    }

    /** @throws BusinessRuleException */
    public function reactivate(int $id): void
    {
        Database::transaction(function () use ($id): void {
            $condominium = $this->condominiums->lockForUpdate($id) ?? throw new BusinessRuleException('Condomínio não encontrado.', 404);
            if ($condominium['status'] !== 'suspended') {
                throw new BusinessRuleException('Este condomínio não está suspenso.', 409);
            }
            $this->condominiums->reactivate($id);
            (new AuditLogger($this->request))->platform('condominium.reactivated', $id, 'condominium', $id);
        });
    }

    /**
     * Checks a CNPJ (14 digits, two check digits). Returns the digits only, or
     * null when invalid. Formatting characters (. / -) are accepted on input.
     */
    public static function normalizeCnpj(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits) === 1) {
            return null;
        }
        foreach ([12, 13] as $length) {
            $weights = $length === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $digits[$i] * $weights[$i];
            }
            $check = $sum % 11 < 2 ? 0 : 11 - $sum % 11;
            if ((int) $digits[$length] !== $check) {
                return null;
            }
        }

        return $digits;
    }

    /** "Residencial Aurora" → "residencial-aurora" (then "-2", "-3"... when taken). */
    private function uniqueSlug(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii === false ? '' : $ascii)), '-');
        $base = substr($base !== '' ? $base : 'condominio', 0, 70);

        $slug = $base;
        for ($n = 2; $this->condominiums->isTaken('slug', $slug); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    private function uniqueSignupCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while ($this->condominiums->isTaken('signup_code', $code));

        return $code;
    }

    private static function duplicateOr(PDOException $e): Throwable
    {
        if (($e->errorInfo[1] ?? null) === 1062) {
            return new BusinessRuleException('Já existe um condomínio com estes dados (nome curto ou CNPJ).', 409, 'legal_id');
        }

        return $e;
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Income/expense categories of the current condominium (`financial_categories`).
 */
final class FinancialCategory extends TenantModel
{
    private const DEFAULT_INCOME = ['Taxa condominial', 'Taxa extra', 'Multa', 'Taxa de reserva', 'Outras receitas'];

    protected string $table = 'financial_categories';

    /**
     * Active income categories, for the charge form.
     *
     * @return list<array{id: int, name: string}>
     */
    public function activeIncome(): array
    {
        return $this->fetchAll(
            "SELECT id, name FROM financial_categories
              WHERE condominium_id = :tenant AND kind = 'income' AND is_active = 1
              ORDER BY name",
            $this->scoped()
        );
    }

    /** An active income category of this tenant, or null (a tampered id is simply not found). */
    public function findActiveIncome(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT id, name FROM financial_categories
              WHERE id = :id AND condominium_id = :tenant AND kind = 'income' AND is_active = 1",
            $this->scoped(['id' => $id])
        );
    }

    /**
     * Seeds the default income categories (Phase 1, FR-TEN-01) when missing.
     * Idempotent thanks to the unique key (condominium_id, kind, name).
     */
    public function ensureDefaults(): void
    {
        foreach (self::DEFAULT_INCOME as $name) {
            $this->execute(
                "INSERT INTO financial_categories (condominium_id, name, kind)
                 VALUES (:tenant, :name, 'income')
                 ON DUPLICATE KEY UPDATE id = id",
                $this->scoped(['name' => $name])
            );
        }
    }
}

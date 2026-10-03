<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Social network categories (`social_categories`): a GLOBAL allowlist shared by
 * every condominium (Phase 1 seed: classifieds, lost_found, neighborhood_tips, pets).
 *
 * Posts may only use an active category from this table. The client sends a
 * code, and the server looks it up here; ids are never taken from the request.
 */
final class SocialCategory extends Model
{
    protected string $table = 'social_categories';

    /** @var list<array{id: int, code: string, name: string}>|null Per-request cache. */
    private static ?array $cache = null;

    /**
     * Active categories in display order.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function active(): array
    {
        return self::$cache ??= array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'code' => $row['code'], 'name' => $row['name']],
            $this->fetchAll('SELECT id, code, name FROM social_categories WHERE is_active = 1 ORDER BY sort_order, name')
        );
    }

    /** The active category with this code, or null (the allowlist check). */
    public function findByCode(string $code): ?array
    {
        foreach ($this->active() as $category) {
            if ($category['code'] === $code) {
                return $category;
            }
        }

        return null;
    }
}

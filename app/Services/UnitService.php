<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Models\Unit;
use PDOException;
use Throwable;

/**
 * Unit registry of the current condominium (Phase 5). Units are never deleted:
 * invoices, visits and reservations reference them (FK RESTRICT), so a unit that
 * no longer exists is deactivated instead.
 */
final class UnitService
{
    /** Upper bound for one bulk run, so a typo (floors 1-1000) cannot create thousands of rows. */
    public const BULK_MAX = 1000;

    public function __construct(
        private readonly Request $request,
        private readonly Unit $units = new Unit()
    ) {
    }

    /**
     * @param array{building: string, unit_number: string, floor_number: ?int, unit_type: string} $data
     * @throws BusinessRuleException 409 when the label already exists in this condominium.
     */
    public function create(array $data): int
    {
        try {
            return Database::transaction(function () use ($data): int {
                $id = $this->units->insert($data + ['is_active' => 1]);
                (new AuditLogger($this->request))->tenant('unit.created', 'unit', $id, [
                    'building'    => $data['building'],
                    'unit_number' => $data['unit_number'],
                ]);

                return $id;
            });
        } catch (PDOException $e) {
            throw self::duplicateOr($e);
        }
    }

    /**
     * @param array{building: string, unit_number: string, floor_number: ?int, unit_type: string, is_active: int} $data
     * @throws BusinessRuleException
     */
    public function update(int $id, array $data): void
    {
        try {
            Database::transaction(function () use ($id, $data): void {
                $before = $this->units->find($id) ?? throw new BusinessRuleException('Unidade não encontrada.', 404);
                $this->units->update($id, $data);   // tenant-scoped UPDATE
                $changes = [];
                foreach ($data as $column => $value) {
                    if ((string) $before[$column] !== (string) $value) {
                        $changes[$column] = ['from' => $before[$column], 'to' => $value];
                    }
                }
                if ($changes !== []) {
                    (new AuditLogger($this->request))->tenant('unit.updated', 'unit', $id, $changes);
                }
            });
        } catch (PDOException $e) {
            throw self::duplicateOr($e);
        }
    }

    /**
     * Creates "floors × units per floor" units in one building, numbered
     * floor * 100 + n (floor 3, unit 2 → "302"); floor 0 gives "1", "2"...
     *
     * One transaction: either the whole batch is written or nothing is.
     * Labels that already exist are skipped (and counted), so running the same
     * batch twice is harmless.
     *
     * @return array{created: int, skipped: int}
     * @throws BusinessRuleException
     */
    public function bulkCreate(string $building, int $firstFloor, int $lastFloor, int $perFloor, string $type): array
    {
        $total = ($lastFloor - $firstFloor + 1) * $perFloor;
        if ($lastFloor < $firstFloor) {
            throw new BusinessRuleException('O andar final deve ser maior ou igual ao inicial.', 422, 'last_floor');
        }
        if ($total > self::BULK_MAX) {
            throw new BusinessRuleException('No máximo ' . self::BULK_MAX . " unidades por vez (pedido: {$total}).", 422, 'units_per_floor');
        }

        return Database::transaction(function () use ($building, $firstFloor, $lastFloor, $perFloor, $type): array {
            $created = 0;
            $skipped = 0;
            for ($floor = $firstFloor; $floor <= $lastFloor; $floor++) {
                for ($n = 1; $n <= $perFloor; $n++) {
                    $number = (string) ($floor * 100 + $n);
                    $this->units->insertIfMissing($building, $number, $floor, $type) ? $created++ : $skipped++;
                }
            }
            (new AuditLogger($this->request))->tenant('unit.bulk_created', 'unit', null, [
                'building'   => $building,
                'floors'     => [$firstFloor, $lastFloor],
                'per_floor'  => $perFloor,
                'created'    => $created,
                'skipped'    => $skipped,
            ]);

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    private static function duplicateOr(PDOException $e): Throwable
    {
        // 1062: uq_units_tenant_label (condominium_id, building, unit_number).
        if (($e->errorInfo[1] ?? null) === 1062) {
            return new BusinessRuleException('Já existe uma unidade com este bloco e número.', 409, 'unit_number');
        }

        return $e;
    }
}

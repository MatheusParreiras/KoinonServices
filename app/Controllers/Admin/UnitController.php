<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Unit;
use App\Services\BusinessRuleException;
use App\Services\UnitService;

/**
 * Units of the current condominium (Property Manager, Phase 5): list, create,
 * bulk create, edit, activate/deactivate. All reads and writes go through the
 * tenant-scoped Unit model.
 */
final class UnitController extends AdminController
{
    private const KEEP = ['building', 'unit_number', 'floor_number', 'unit_type'];
    private const KEEP_BULK = ['bulk_building', 'first_floor', 'last_floor', 'units_per_floor', 'bulk_unit_type'];

    /** GET /admin/units?q=&building=&status=&page= */
    public function index(): Response
    {
        $this->requireRole(self::MANAGERS);

        $units = new Unit();
        $buildings = $units->buildings();
        $filters = [];
        $query = [];
        $q = mb_substr($this->request->queryString('q'), 0, 20);
        if ($q !== '') {
            $filters['q'] = $query['q'] = $q;
        }
        $building = $this->request->queryString('building');
        if (in_array($building, $buildings, true)) {   // allowlist: existing buildings only
            $filters['building'] = $query['building'] = $building;
        }
        $status = $this->request->queryString('status');
        if (in_array($status, ['active', 'inactive'], true)) {
            $filters['active'] = $status === 'active';
            $query['status'] = $status;
        }

        $pagination = $this->pagination(30)->withTotal($units->count($filters));

        return $this->view('admin/units/index', [
            'title'      => 'Unidades',
            'activeNav'  => 'admin-units',
            'units'      => $units->search($filters, $pagination),
            'buildings'  => $buildings,
            'types'      => Unit::TYPES,
            'query'      => $query,
            'pagination' => $pagination->toArray(),
            'bulkMax'    => UnitService::BULK_MAX,
        ]);
    }

    /** POST /admin/units */
    public function store(): Response
    {
        $this->requireRole(self::MANAGERS);
        [$data, $v] = $this->validated();
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/admin/units', 422, self::KEEP);
        }

        try {
            (new UnitService($this->request))->create($data);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, '/admin/units', self::KEEP);
        }

        return $this->done('Unidade cadastrada.', '/admin/units', [], 201);
    }

    /** POST /admin/units/bulk: e.g. Tower A, floors 1-10, 4 units per floor. */
    public function bulk(): Response
    {
        $this->requireRole(self::MANAGERS);

        $v = new Validator($this->request);
        $building = (string) $v->string('bulk_building', 'Bloco/Torre', 1, 30);
        $first = $v->integer('first_floor', 'Andar inicial', 0, 200);
        $last = $v->integer('last_floor', 'Andar final', 0, 200);
        $perFloor = $v->integer('units_per_floor', 'Unidades por andar', 1, 50);
        $type = $v->enum('bulk_unit_type', 'Tipo', array_keys(Unit::TYPES));
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/admin/units', 422, self::KEEP_BULK);
        }

        try {
            $result = (new UnitService($this->request))->bulkCreate($building, (int) $first, (int) $last, (int) $perFloor, (string) $type);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, '/admin/units', self::KEEP_BULK);
        }

        $message = "{$result['created']} unidade(s) criada(s)"
            . ($result['skipped'] > 0 ? "; {$result['skipped']} já existia(m) e foi(ram) mantida(s)." : '.');

        return $this->done($message, '/admin/units', $result, 201);
    }

    /** GET /admin/units/{id}/edit */
    public function edit(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $unit = (new Unit())->find((int) $id) ?? throw new HttpException(404);   // tenant-scoped find

        return $this->view('admin/units/edit', [
            'title'     => 'Editar unidade',
            'activeNav' => 'admin-units',
            'unit'      => $unit,
            'types'     => Unit::TYPES,
        ]);
    }

    /** POST /admin/units/{id} */
    public function update(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        (new Unit())->find((int) $id) ?? throw new HttpException(404);
        $back = "/admin/units/{$id}/edit";

        [$data, $v] = $this->validated();
        $data['is_active'] = $this->request->boolean('is_active') ? 1 : 0;
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            (new UnitService($this->request))->update((int) $id, $data);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e, $back);
        }

        return $this->done('Unidade atualizada.', '/admin/units');
    }

    /** @return array{0: array{building: string, unit_number: string, floor_number: ?int, unit_type: string}, 1: Validator} */
    private function validated(): array
    {
        $v = new Validator($this->request);
        $building = $v->string('building', 'Bloco/Torre', 1, 30, required: false) ?? '';
        $number = $v->string('unit_number', 'Número', 1, 20);
        $floor = $v->filled('floor_number') ? $v->integer('floor_number', 'Andar', -5, 200) : null;
        $type = $v->enum('unit_type', 'Tipo', array_keys(Unit::TYPES));

        return [[
            'building'     => $building,
            'unit_number'  => (string) $number,
            'floor_number' => $floor,
            'unit_type'    => (string) $type,
        ], $v];
    }
}

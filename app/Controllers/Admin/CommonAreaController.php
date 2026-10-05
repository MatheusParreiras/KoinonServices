<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Validator;
use App\Models\CommonArea;
use App\Services\AuditLogger;
use PDOException;

/**
 * Common areas used by Reservations (Property Manager, Phase 5).
 *
 * Deactivating an area blocks NEW bookings (ReservationService checks
 * is_active under the area's row lock) but keeps every existing reservation:
 * nothing is deleted, the reservations' FK to the area is RESTRICT anyway.
 */
final class CommonAreaController extends AdminController
{
    private const KEEP = [
        'name', 'area_type', 'max_people', 'bookings_per_slot', 'opens_at', 'closes_at',
        'max_duration_minutes', 'min_advance_hours', 'max_advance_days', 'rules',
    ];

    /** GET /admin/common-areas */
    public function index(): Response
    {
        $this->requireRole(self::MANAGERS);

        return $this->view('admin/common-areas/index', [
            'title'     => 'Áreas comuns',
            'activeNav' => 'admin-areas',
            'areas'     => (new CommonArea())->all(),
            'types'     => CommonArea::TYPES,
        ]);
    }

    /** GET /admin/common-areas/new */
    public function create(): Response
    {
        $this->requireRole(self::MANAGERS);

        return $this->form(null);
    }

    /** POST /admin/common-areas */
    public function store(): Response
    {
        $this->requireRole(self::MANAGERS);
        [$data, $v] = $this->validated();
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/admin/common-areas/new', 422, self::KEEP);
        }

        try {
            Database::transaction(function () use ($data): void {
                $id = (new CommonArea())->insert($data + ['is_active' => 1]);
                (new AuditLogger($this->request))->tenant('common_area.created', 'common_area', $id, ['name' => $data['name']]);
            });
        } catch (PDOException $e) {
            return $this->duplicate($e, '/admin/common-areas/new');
        }

        return $this->done('Área comum cadastrada.', '/admin/common-areas', [], 201);
    }

    /** GET /admin/common-areas/{id}/edit */
    public function edit(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $area = (new CommonArea())->find((int) $id) ?? throw new HttpException(404);   // tenant-scoped

        return $this->form($area);
    }

    /** POST /admin/common-areas/{id} */
    public function update(string $id): Response
    {
        $this->requireRole(self::MANAGERS);
        $areas = new CommonArea();
        $before = $areas->find((int) $id) ?? throw new HttpException(404);
        $back = "/admin/common-areas/{$id}/edit";

        [$data, $v] = $this->validated();
        $data['is_active'] = $this->request->boolean('is_active') ? 1 : 0;
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            Database::transaction(function () use ($areas, $id, $data, $before): void {
                $areas->update((int) $id, $data);   // tenant-scoped UPDATE
                $audit = new AuditLogger($this->request);
                $action = match (true) {
                    (int) $before['is_active'] === 1 && $data['is_active'] === 0 => 'common_area.deactivated',
                    (int) $before['is_active'] === 0 && $data['is_active'] === 1 => 'common_area.reactivated',
                    default                                                      => 'common_area.updated',
                };
                $audit->tenant($action, 'common_area', (int) $id, ['name' => $data['name']]);
            });
        } catch (PDOException $e) {
            return $this->duplicate($e, $back);
        }

        return $this->done('Área comum atualizada.', '/admin/common-areas');
    }

    /** @param array<string, mixed>|null $area */
    private function form(?array $area): Response
    {
        return $this->view('admin/common-areas/form', [
            'title'     => $area === null ? 'Nova área comum' : 'Editar área comum',
            'activeNav' => 'admin-areas',
            'area'      => $area,
            'types'     => CommonArea::TYPES,
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: Validator} */
    private function validated(): array
    {
        $v = new Validator($this->request);
        $data = [
            'name'                 => $v->string('name', 'Nome', 2, 80),
            'area_type'            => $v->enum('area_type', 'Tipo', array_keys(CommonArea::TYPES)),
            'max_people'           => $v->filled('max_people') ? $v->integer('max_people', 'Capacidade', 1, 2000) : null,
            'bookings_per_slot'    => $v->integer('bookings_per_slot', 'Reservas simultâneas', 1, 50, 1),
            'requires_approval'    => $this->request->boolean('requires_approval') ? 1 : 0,
            'opens_at'             => null,
            'closes_at'            => null,
            'max_duration_minutes' => $v->filled('max_duration_minutes')
                ? $v->integer('max_duration_minutes', 'Duração máxima', 30, 1440)
                : null,
            'min_advance_hours'    => $v->integer('min_advance_hours', 'Antecedência mínima', 0, 720, 24),
            'max_advance_days'     => $v->integer('max_advance_days', 'Antecedência máxima', 1, 365, 90),
            'rules'                => $v->string('rules', 'Regras', 1, 2000, required: false),
        ];

        // Opening hours: both or neither (schema CHECK ck_common_areas_hours).
        if ($v->filled('opens_at') || $v->filled('closes_at')) {
            $opens = $v->time('opens_at', 'Abertura');
            $closes = $v->time('closes_at', 'Fechamento');
            if ($opens !== null && $closes !== null) {
                if ($closes <= $opens) {
                    $v->addError('closes_at', 'O fechamento deve ser depois da abertura.');
                }
                $data['opens_at'] = $opens . ':00';
                $data['closes_at'] = $closes . ':00';
            }
        }

        return [$data, $v];
    }

    private function duplicate(PDOException $e, string $back): Response
    {
        if (($e->errorInfo[1] ?? null) === 1062) {   // uq_common_areas_name (condominium_id, name)
            return $this->invalid(['name' => 'Já existe uma área com este nome.'], $back, 409, self::KEEP);
        }
        throw $e;
    }
}

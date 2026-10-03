<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Models\CommonArea;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\UnitResident;
use App\Services\BusinessRuleException;
use App\Services\ReservationService;

/**
 * Common-area reservations (Reservas).
 */
final class ReservationController extends Controller
{
    /** May see the reservations screen (concierge: to hand over keys; Super Admin: read-only). */
    public const VIEWERS = [Auth::SUPER_ADMIN, 'manager', 'concierge', 'resident'];

    /** May create bookings: residents for their own units, managers for any unit. */
    public const BOOKERS = ['manager', 'resident'];

    /** May approve/reject and cancel any booking. */
    public const MANAGERS = ['manager'];

    /** Roles that see every unit's bookings instead of only their own. */
    private const SEE_ALL = [Auth::SUPER_ADMIN, 'manager', 'concierge'];

    /** Input fields re-filled after a failed submission. */
    private const KEEP = ['common_area_id', 'unit_id', 'reservation_date', 'start_time', 'end_time', 'guest_count', 'notes'];

    /** GET /reservations: booking form + upcoming reservations. */
    public function index(): Response
    {
        $this->requireRole(self::VIEWERS);

        $areaModel = new CommonArea();
        $areas = $areaModel->active();
        if ($areas === []) {
            $areaModel->ensureDefaults(); // first visit of a new condominium
            $areas = $areaModel->active();
        }

        $seeAll = Auth::hasRole(self::SEE_ALL);
        $myUnitIds = $seeAll ? null : (new UnitResident())->activeUnitIds((int) Auth::id());

        return $this->view('reservations/index', [
            'title'        => 'Reservas',
            'activeNav'    => 'reservations',
            'areas'        => $areas,
            'units'        => $this->bookableUnits(),
            'reservations' => (new Reservation())->upcoming(TenantContext::today(), $myUnitIds),
            'canBook'      => Auth::hasRole(self::BOOKERS),
            'canManage'    => Auth::hasRole(self::MANAGERS),
            'myUnitIds'    => $myUnitIds ?? [],
            'today'        => TenantContext::today(),
            'statusLabels' => Reservation::STATUS_LABELS,
            'scripts'      => ['js/reservations.js'],
        ]);
    }

    /**
     * POST /reservations (HTML form, also validated client-side by reservations.js).
     *
     * Order of checks: CSRF (middleware) → role → input validation → unit
     * ownership → business rules and the locked overlap check (service).
     */
    public function store(): Response
    {
        $this->requireRole(self::BOOKERS);

        $v = new Validator($this->request);
        $areaId = $v->id('common_area_id', 'a área comum');
        $unitId = $v->id('unit_id', 'a unidade');
        $date = $v->date('reservation_date', 'Data');
        $start = $v->time('start_time', 'Horário de início');
        $end = $v->time('end_time', 'Horário de término');
        $guests = $v->integer('guest_count', 'Número de convidados', 0, 500, 0);
        $notes = $v->string('notes', 'Observações', 1, 500, required: false);

        if ($date !== null && $date < TenantContext::today()) {
            $v->addError('reservation_date', 'Não é possível reservar uma data no passado.');
        }
        if ($start !== null && $end !== null && $end <= $start) {
            $v->addError('end_time', 'O horário de término deve ser depois do início.');
        }
        // Ownership: the unit must be one the user may book for. For a resident it must
        // be their own unit; an id typed into the form for someone else's unit fails here.
        if ($unitId !== null && !$this->canBookForUnit($unitId)) {
            $v->addError('unit_id', 'Você só pode reservar para a sua unidade.');
        }
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/reservations', 422, self::KEEP);
        }

        try {
            $result = (new ReservationService())->book(
                (int) $areaId,
                (int) $unitId,
                (int) Auth::id(),
                (string) $date,
                (string) $start,
                (string) $end,
                (int) $guests,
                $notes
            );
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/reservations', $e->status(), self::KEEP);
        }

        $message = $result['status'] === 'approved'
            ? 'Reserva confirmada!'
            : 'Reserva solicitada. Ela ficará pendente até a aprovação da administração.';

        return $this->done($message, '/reservations', ['reservation' => $result], 201);
    }

    /**
     * POST /reservations/{id}/cancel
     *
     * Residents may cancel only bookings of their own units, before the area's
     * deadline; managers may cancel any booking of the condominium.
     */
    public function cancel(string $id): Response
    {
        $this->requireRole(self::BOOKERS);

        $reservation = (new Reservation())->find((int) $id) ?? throw new HttpException(404);
        $isManager = Auth::hasRole(self::MANAGERS);

        // IDOR check: someone else's booking answers 404, exactly like a non-existent id.
        if (!$isManager && !in_array((int) $reservation['unit_id'], (new UnitResident())->activeUnitIds((int) Auth::id()), true)) {
            throw new HttpException(404);
        }

        $reason = (new Validator($this->request))->string('reason', 'Motivo', 1, 255, required: false);
        try {
            (new ReservationService())->cancel((int) $id, (int) Auth::id(), $reason, enforceDeadline: !$isManager);
        } catch (BusinessRuleException $e) {
            return $this->invalid(['general' => $e->getMessage()], '/reservations', $e->status());
        }

        return $this->done('Reserva cancelada.', '/reservations');
    }

    /** POST /reservations/{id}/decision: manager approves or rejects a pending booking. */
    public function decide(string $id): Response
    {
        $this->requireRole(self::MANAGERS);

        $v = new Validator($this->request);
        $decision = $v->enum('decision', 'Decisão', ['approved', 'rejected']);
        $note = $v->string('decision_note', 'Observação', 1, 255, required: false);
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/reservations');
        }

        // Tenant-scoped UPDATE: an id from another condominium changes nothing.
        if (!(new Reservation())->decide((int) $id, (string) $decision, (int) Auth::id(), $note)) {
            return $this->invalid(['general' => 'Esta reserva não está mais pendente.'], '/reservations', 409);
        }

        return $this->done($decision === 'approved' ? 'Reserva aprovada.' : 'Reserva recusada.', '/reservations');
    }

    /**
     * Units offered in the form: all active units for managers, the user's own
     * units for residents.
     *
     * @return list<array{id: int, label: string}>
     */
    private function bookableUnits(): array
    {
        $units = (new Unit())->active();
        if (Auth::hasRole(self::MANAGERS)) {
            return $units;
        }
        if (!Auth::hasRole(self::BOOKERS)) {
            return [];
        }
        $mine = (new UnitResident())->activeUnitIds((int) Auth::id());

        return array_values(array_filter($units, static fn (array $u): bool => in_array((int) $u['id'], $mine, true)));
    }

    /** Server-side ownership rule behind the unit <select>. */
    private function canBookForUnit(int $unitId): bool
    {
        if (Auth::hasRole(self::MANAGERS)) {
            return (new Unit())->findActive($unitId) !== null; // any unit, but of THIS condominium
        }

        return in_array($unitId, (new UnitResident())->activeUnitIds((int) Auth::id()), true);
    }
}

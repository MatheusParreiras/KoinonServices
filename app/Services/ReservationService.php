<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TenantContext;
use App\Models\CommonArea;
use App\Models\Reservation;
use DateTimeImmutable;

/**
 * Booking rules for common areas, including the double-booking guarantee.
 */
final class ReservationService
{
    private const MIN_MINUTES = 30;

    public function __construct(
        private readonly CommonArea $areas = new CommonArea(),
        private readonly Reservation $reservations = new Reservation()
    ) {
    }

    /**
     * Books an area for a unit.
     *
     * Concurrency: the overlap check and the insert happen in ONE transaction
     * that first locks the area row (CommonArea::lockForBooking). Two
     * simultaneous requests for the same area therefore run one after the
     * other: the second waits for the first to commit, then its overlap query
     * sees the new reservation and is rejected. MySQL cannot express "no
     * overlapping ranges" as a constraint, so this lock is what guarantees it.
     *
     * @param string $date  Y-m-d (local date of the condominium)
     * @param string $start H:i
     * @param string $end   H:i
     * @return array{id: int, status: string}
     * @throws BusinessRuleException
     */
    public function book(
        int $areaId,
        int $unitId,
        int $userId,
        string $date,
        string $start,
        string $end,
        int $guests,
        ?string $notes
    ): array {
        $timezone = TenantContext::timezone();
        $startsAt = new DateTimeImmutable("{$date} {$start}:00", $timezone);
        $endsAt = new DateTimeImmutable("{$date} {$end}:00", $timezone);
        $now = TenantContext::now();

        // Same calendar day is implied: both times are on $date.
        if ($endsAt <= $startsAt) {
            throw new BusinessRuleException('O horário de término deve ser depois do início.', 422, 'end_time');
        }
        if (($endsAt->getTimestamp() - $startsAt->getTimestamp()) < self::MIN_MINUTES * 60) {
            throw new BusinessRuleException('A reserva deve durar pelo menos ' . self::MIN_MINUTES . ' minutos.', 422, 'end_time');
        }
        if ($startsAt <= $now) {
            throw new BusinessRuleException('Não é possível reservar uma data ou horário no passado.', 422, 'reservation_date');
        }

        return Database::transaction(function () use ($areaId, $unitId, $userId, $date, $startsAt, $endsAt, $now, $guests, $notes): array {
            // 1. Serialise all bookings of this area (and confirm it is ours and active).
            $area = $this->areas->lockForBooking($areaId);
            if ($area === null || !(bool) $area['is_active']) {
                throw new BusinessRuleException('Área comum não encontrada.', 422, 'common_area_id');
            }

            // 2. Area rules.
            $hoursAhead = ($startsAt->getTimestamp() - $now->getTimestamp()) / 3600;
            if ($hoursAhead < (int) $area['min_advance_hours']) {
                throw new BusinessRuleException(
                    "Esta área exige reserva com pelo menos {$area['min_advance_hours']} hora(s) de antecedência.",
                    422,
                    'start_time'
                );
            }
            if ($startsAt > $now->modify('+' . (int) $area['max_advance_days'] . ' days')) {
                throw new BusinessRuleException(
                    "Esta área aceita reservas com até {$area['max_advance_days']} dias de antecedência.",
                    422,
                    'reservation_date'
                );
            }
            // Phase 5: opening hours and maximum duration configured by the manager.
            // Times are local wall-clock values, compared as "HH:MM:SS" strings.
            if ($area['opens_at'] !== null && $area['closes_at'] !== null) {
                $opens = (string) $area['opens_at'];
                $closes = (string) $area['closes_at'];
                if ($startsAt->format('H:i:s') < $opens || $endsAt->format('H:i:s') > $closes) {
                    throw new BusinessRuleException(
                        'Esta área funciona das ' . substr($opens, 0, 5) . ' às ' . substr($closes, 0, 5) . '.',
                        422,
                        'start_time'
                    );
                }
            }
            if ($area['max_duration_minutes'] !== null
                && ($endsAt->getTimestamp() - $startsAt->getTimestamp()) > (int) $area['max_duration_minutes'] * 60
            ) {
                throw new BusinessRuleException(
                    "Cada reserva desta área pode durar no máximo {$area['max_duration_minutes']} minutos.",
                    422,
                    'end_time'
                );
            }
            if ($area['max_people'] !== null && $guests > (int) $area['max_people']) {
                throw new BusinessRuleException("Capacidade máxima: {$area['max_people']} pessoas.", 422, 'guest_count');
            }
            $nowLocal = $now->format('Y-m-d H:i:s');
            if ($this->reservations->countUpcomingForUnit($areaId, $unitId, $nowLocal) >= (int) $area['max_active_per_unit']) {
                throw new BusinessRuleException(
                    "Sua unidade já tem o máximo de {$area['max_active_per_unit']} reserva(s) futura(s) nesta área.",
                    409
                );
            }

            // 3. Overlap check (rows locked; the area lock above prevents phantoms).
            $startsSql = $startsAt->format('Y-m-d H:i:s');
            $endsSql = $endsAt->format('Y-m-d H:i:s');
            $overlapping = $this->reservations->lockOverlapping($areaId, $date, $startsSql, $endsSql);

            // Exclusive areas (BBQ, party room) allow 1; shared areas (gym) up to bookings_per_slot.
            // Counting every overlapping booking is deliberately conservative for shared areas.
            if (count($overlapping) >= (int) $area['bookings_per_slot']) {
                throw new BusinessRuleException('Este horário já está reservado. Escolha outro horário.', 409, 'start_time');
            }

            // 4. Insert. Approval-free areas are confirmed immediately.
            $status = (bool) $area['requires_approval'] ? 'pending' : 'approved';
            $id = $this->reservations->insert([
                'common_area_id'       => $areaId,
                'reservation_date'     => $date,
                'starts_at'            => $startsSql,
                'ends_at'              => $endsSql,
                'seat_number'          => count($overlapping) + 1,
                'unit_id'              => $unitId,
                'requested_by_user_id' => $userId,
                'guest_count'          => $guests,
                'status'               => $status,
                'decided_at'           => $status === 'approved' ? gmdate('Y-m-d H:i:s') : null,
                'notes'                => $notes,
            ]);

            return ['id' => $id, 'status' => $status];
        });
    }

    /**
     * Cancels a reservation. Ownership has already been checked by the caller;
     * $enforceDeadline applies the area's cancellation deadline (residents only).
     *
     * @throws BusinessRuleException
     */
    public function cancel(int $reservationId, int $userId, ?string $reason, bool $enforceDeadline): void
    {
        Database::transaction(function () use ($reservationId, $userId, $reason, $enforceDeadline): void {
            $reservation = $this->reservations->lockWithArea($reservationId)
                ?? throw new BusinessRuleException('Reserva não encontrada.', 404);

            if (!in_array($reservation['status'], Reservation::ACTIVE_STATUSES, true)) {
                throw new BusinessRuleException('Esta reserva não está mais ativa.', 409);
            }

            if ($enforceDeadline) {
                $startsAt = new DateTimeImmutable((string) $reservation['starts_at'], TenantContext::timezone());
                $deadline = $startsAt->modify('-' . (int) $reservation['cancel_deadline_hours'] . ' hours');
                if (TenantContext::now() > $deadline) {
                    throw new BusinessRuleException(
                        "O cancelamento só é permitido até {$reservation['cancel_deadline_hours']} horas antes do início. Fale com a administração.",
                        409
                    );
                }
            }

            $this->reservations->cancel($reservationId, $userId, $reason);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Mail\Mailer;
use App\Models\Package;
use App\Models\Unit;
use App\Models\UnitResident;
use App\Models\Visit;
use App\Models\Visitor;

/**
 * Business rules of the concierge desk: visitor entries/exits and packages.
 *
 * Every model used here is a TenantModel, so all reads and writes are scoped
 * to TenantContext::id(); this class never handles a condominium id itself.
 */
final class ConciergeService
{
    public function __construct(
        private readonly Visitor $visitors = new Visitor(),
        private readonly Visit $visits = new Visit(),
        private readonly Package $packages = new Package(),
        private readonly Unit $units = new Unit(),
        private readonly UnitResident $residents = new UnitResident(),
        private readonly Mailer $mailer = new Mailer()
    ) {
    }

    /**
     * Registers a visitor entering now. Reuses the visitor record when the same
     * RG is already known in this condominium, and refuses blocked visitors.
     *
     * @param array{full_name: string, document_number: string, unit_id: int, visit_type: string,
     *              vehicle_plate: ?string, notes: ?string} $data
     * @return int The visit id.
     * @throws BusinessRuleException
     */
    public function registerEntry(array $data, int $staffUserId): int
    {
        // Ownership of the destination: the unit must belong to THIS condominium.
        if ($this->units->findActive($data['unit_id']) === null) {
            throw new BusinessRuleException('Unidade não encontrada.', 422, 'unit_id');
        }

        return Database::transaction(function () use ($data, $staffUserId): int {
            $visitor = $this->visitors->findByDocument('national_id', $data['document_number']);

            if ($visitor !== null && (bool) $visitor['is_blocked']) {
                throw new BusinessRuleException(
                    'Entrada não permitida: visitante bloqueado. Motivo: ' . $visitor['block_reason'],
                    409,
                    'document_number'
                );
            }

            $visitorId = $visitor !== null
                ? (int) $visitor['id']
                : $this->visitors->insert([
                    'full_name'          => $data['full_name'],
                    'document_type'      => 'national_id',
                    'document_number'    => $data['document_number'],
                    'created_by_user_id' => $staffUserId,
                ]);

            return $this->visits->insert([
                'visitor_id'                  => $visitorId,
                'unit_id'                     => $data['unit_id'],
                'visit_type'                  => $data['visit_type'],
                'vehicle_plate'               => $data['vehicle_plate'],
                'notes'                       => $data['notes'],
                'status'                      => 'inside',
                'entry_at'                    => gmdate('Y-m-d H:i:s'),
                'created_by_user_id'          => $staffUserId,
                'entry_registered_by_user_id' => $staffUserId,
            ]);
        });
    }

    /**
     * Registers the exit of a visitor who is inside.
     *
     * @throws BusinessRuleException 404 when the visit is not in this condominium, 409 when it is not "inside".
     */
    public function registerExit(int $visitId, int $staffUserId): void
    {
        if ($this->visits->registerExit($visitId, $staffUserId)) {
            return;
        }
        // Zero rows changed: tell "unknown here" apart from "already out".
        $visit = $this->visits->find($visitId);
        if ($visit === null) {
            throw new BusinessRuleException('Registro de visita não encontrado.', 404);
        }
        throw new BusinessRuleException('A saída deste visitante já foi registrada.', 409);
    }

    /**
     * Logs a delivery and e-mails the unit's residents their pickup code.
     *
     * @param array{unit_id: int, carrier: ?string, tracking_code: ?string, description: ?string,
     *              package_size: string, storage_location: ?string} $data
     * @return array{id: int, notified: int} Package id and number of e-mails sent.
     * @throws BusinessRuleException
     */
    public function registerPackage(array $data, int $staffUserId): array
    {
        $unit = $this->units->findActive($data['unit_id'])
            ?? throw new BusinessRuleException('Unidade não encontrada.', 422, 'unit_id');

        // 6 random digits from a CSPRNG. The code proves at the desk that the
        // person collecting received the notification.
        $pickupCode = sprintf('%06d', random_int(0, 999999));

        $packageId = $this->packages->insert($data + [
            'pickup_code'         => $pickupCode,
            'received_by_user_id' => $staffUserId,
        ]);

        // Mail goes out after the insert has committed (it is a single statement),
        // and a mail failure never undoes the registration: the package IS at the desk.
        $sent = 0;
        foreach ($this->residents->contactsForUnit($data['unit_id']) as $contact) {
            $ok = $this->mailer->sendPackageNotice(
                $contact['email'],
                $contact['full_name'],
                (string) TenantContext::name(),
                (string) $unit['label'],
                $data['carrier'],
                $pickupCode
            );
            $sent += $ok ? 1 : 0;
        }
        if ($sent > 0) {
            $this->packages->markNotified($packageId);
        } else {
            Logger::warning('Package registered without resident notification', ['package_id' => $packageId]);
        }

        return ['id' => $packageId, 'notified' => $sent];
    }

    /**
     * Hands a package over, after checking the pickup code.
     *
     * The package row is locked (FOR UPDATE) while status and code are checked
     * and the update is written, so a double click or two desks acting at once
     * cannot both succeed.
     *
     * @return array<string, mixed> The updated package summary.
     * @throws BusinessRuleException 404 unknown here, 409 already handled, 422 wrong code
     */
    public function confirmPickup(int $packageId, string $pickupCode, string $pickedUpByName, int $staffUserId): array
    {
        return Database::transaction(function () use ($packageId, $pickupCode, $pickedUpByName, $staffUserId): array {
            $package = $this->packages->lockForPickup($packageId)
                ?? throw new BusinessRuleException('Encomenda não encontrada.', 404);

            if ($package['status'] !== 'awaiting_pickup') {
                throw new BusinessRuleException('Esta encomenda já foi retirada ou devolvida.', 409);
            }
            // Constant-time comparison, like any secret.
            if (!hash_equals((string) $package['pickup_code'], $pickupCode)) {
                throw new BusinessRuleException('Código de retirada incorreto.', 422, 'pickup_code');
            }

            $this->packages->markPickedUp($packageId, $pickedUpByName, $staffUserId);

            return (array) $this->packages->pickupSummary($packageId);
        });
    }
}

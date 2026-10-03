<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Core\TenantContext;
use App\Core\Validator;
use App\Models\Package;
use App\Models\Unit;
use App\Models\Visit;
use App\Services\BusinessRuleException;
use App\Services\ConciergeService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Concierge desk (Portaria): visitor entries/exits and packages.
 *
 * Residents have no access at all: the desk shows visitors' personal data
 * (names, masked RG) that residents do not need (LGPD).
 */
final class ConciergeController extends Controller
{
    /** May open the desk screen. The Super Admin only reads (see Phase 3 assumptions). */
    public const VIEWERS = [Auth::SUPER_ADMIN, 'manager', 'concierge'];

    /** May register entries, exits, packages and pickups. */
    public const OPERATORS = ['manager', 'concierge'];

    /** GET /concierge */
    public function index(): Response
    {
        $this->requireRole(self::VIEWERS);

        return $this->view('concierge/index', [
            'title'       => 'Portaria',
            'activeNav'   => 'concierge',
            'canOperate'  => Auth::hasRole(self::OPERATORS),
            'units'       => (new Unit())->active(),
            'inside'      => (new Visit())->currentlyInside(),
            'recentExits' => (new Visit())->recentExits(),
            'packages'    => (new Package())->awaitingPickup(),
            'visitTypes'  => Visit::TYPES,
            'sizes'       => Package::SIZES,
            'scripts'     => ['js/concierge.js'],
        ]);
    }

    /**
     * POST /concierge/visits (HTML form; CSRF checked by the route middleware).
     * Registers a visitor entering now.
     */
    public function storeVisit(): Response
    {
        $this->requireRole(self::OPERATORS);

        $v = new Validator($this->request);
        $name = $v->string('full_name', 'Nome', 3, 150);
        $document = self::normalizeDocument($this->request->string('document_number'));
        if ($document === null) {
            $v->addError('document_number', 'RG inválido: use de 5 a 20 dígitos (pode terminar em X).');
        }
        $unitId = $v->id('unit_id', 'a unidade visitada');
        $type = $v->enum('visit_type', 'Tipo de visita', array_keys(Visit::TYPES));
        $plate = self::normalizePlate($this->request->string('vehicle_plate'));
        $notes = $v->string('notes', 'Observações', 1, 500, required: false);

        // The RG is deliberately NOT kept for re-display (personal data stays out of the session).
        $keep = ['full_name', 'unit_id', 'visit_type', 'vehicle_plate', 'notes'];
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/concierge', 422, $keep);
        }

        try {
            (new ConciergeService())->registerEntry([
                'full_name'       => $name,
                'document_number' => $document,
                'unit_id'         => $unitId,
                'visit_type'      => $type,
                'vehicle_plate'   => $plate,
                'notes'           => $notes,
            ], (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/concierge', $e->status(), $keep);
        }

        return $this->done('Entrada registrada.', '/concierge');
    }

    /** POST /api/concierge/visits/{id}/exit (fetch, JSON). */
    public function exitVisit(string $id): Response
    {
        $this->requireRole(self::OPERATORS);

        try {
            (new ConciergeService())->registerExit((int) $id, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->json(['error' => $e->getMessage()], $e->status());
        }

        return $this->json([
            'message' => 'Saída registrada.',
            'visit'   => ['id' => (int) $id, 'status' => 'exited', 'exit_time' => TenantContext::now()->format('H:i')],
        ]);
    }

    /** POST /concierge/packages (HTML form): logs a delivery and notifies the unit. */
    public function storePackage(): Response
    {
        $this->requireRole(self::OPERATORS);

        $v = new Validator($this->request);
        $unitId = $v->id('unit_id', 'a unidade de destino');
        $carrier = $v->string('carrier', 'Transportadora', 2, 80, required: false);
        $tracking = $v->string('tracking_code', 'Código de rastreio', 3, 60, required: false);
        $description = $v->string('description', 'Descrição', 2, 255, required: false);
        $size = $v->enum('package_size', 'Tamanho', array_keys(Package::SIZES));
        $location = $v->string('storage_location', 'Local de armazenamento', 1, 60, required: false);

        $keep = ['unit_id', 'carrier', 'tracking_code', 'description', 'package_size', 'storage_location'];
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/concierge', 422, $keep);
        }

        try {
            $result = (new ConciergeService())->registerPackage([
                'unit_id'          => $unitId,
                'carrier'          => $carrier,
                'tracking_code'    => $tracking,
                'description'      => $description,
                'package_size'     => $size,
                'storage_location' => $location,
            ], (int) Auth::id());
        } catch (BusinessRuleException $e) {
            return $this->invalid([$e->field() ?? 'general' => $e->getMessage()], '/concierge', $e->status(), $keep);
        }

        $message = $result['notified'] > 0
            ? "Encomenda registrada. {$result['notified']} morador(es) avisado(s) por e-mail."
            : 'Encomenda registrada. Nenhum morador com e-mail ativo foi encontrado para esta unidade: avise pelo interfone.';

        return $this->done($message, '/concierge');
    }

    /**
     * POST /api/concierge/packages/{id}/pickup (fetch, JSON).
     * Body: {"pickup_code": "123456", "picked_up_by_name": "Maria Silva"}
     *
     * Status codes: 200 done, 404 not in this condominium, 409 already picked
     * up, 422 invalid input or wrong code. 401/403/419 come from middleware.
     */
    public function pickupPackage(string $id): Response
    {
        $this->requireRole(self::OPERATORS);

        $v = new Validator($this->request);
        $name = $v->string('picked_up_by_name', 'Nome de quem retirou', 3, 150);
        $code = $this->request->string('pickup_code');
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            $v->addError('pickup_code', 'Informe o código de 6 dígitos.');
        }
        if ($v->fails()) {
            return $this->json(['error' => 'Verifique os campos destacados.', 'errors' => $v->errors()], 422);
        }

        try {
            $package = (new ConciergeService())->confirmPickup((int) $id, $code, (string) $name, (int) Auth::id());
        } catch (BusinessRuleException $e) {
            $errors = $e->field() !== null ? [$e->field() => $e->getMessage()] : [];

            return $this->json(['error' => $e->getMessage(), 'errors' => $errors], $e->status());
        }

        $pickedUpAt = (new DateTimeImmutable((string) $package['picked_up_at'], new DateTimeZone('UTC')))
            ->setTimezone(TenantContext::timezone());

        return $this->json([
            'message' => 'Retirada registrada.',
            'package' => [
                'id'                => (int) $package['id'],
                'status'            => (string) $package['status'],
                'picked_up_by_name' => (string) $package['picked_up_by_name'],
                'picked_up_at'      => $pickedUpAt->format('d/m/Y H:i'),
            ],
        ]);
    }

    /**
     * Normalises an RG: keeps digits and a final X (check digit), so "12.345.678-x"
     * and "12345678X" are the same visitor. Returns null when invalid.
     */
    private static function normalizeDocument(string $raw): ?string
    {
        $value = strtoupper(preg_replace('/[^0-9Xx]/', '', $raw) ?? '');

        return preg_match('/^\d{4,19}[0-9X]$/', $value) === 1 ? $value : null;
    }

    /** Uppercase letters and digits only (Mercosul and old formats); null when empty or invalid. */
    private static function normalizePlate(string $raw): ?string
    {
        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');

        return preg_match('/^[A-Z0-9]{5,8}$/', $value) === 1 ? $value : null;
    }
}

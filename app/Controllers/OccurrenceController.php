<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Occurrence;
use App\Models\OccurrenceUpdate;
use App\Models\Unit;
use App\Models\UnitResident;
use App\Services\BusinessRuleException;
use App\Services\OccurrenceService;

/**
 * Digital incident book (Ocorrências): tickets, replies and status changes.
 */
final class OccurrenceController extends Controller
{
    public const VIEWERS = [Auth::SUPER_ADMIN, 'manager', 'concierge', 'resident'];

    /** May open tickets and reply to their own. */
    public const REPORTERS = ['manager', 'concierge', 'resident'];

    /** May reply to any ticket, write internal notes and change status. */
    public const HANDLERS = ['manager'];

    /** See every ticket of the condominium; everyone else sees only their own. */
    public const SEE_ALL = [Auth::SUPER_ADMIN, 'manager'];

    /** GET /occurrences?status=open */
    public function index(): Response
    {
        $this->requireRole(self::VIEWERS);

        // The filter is whitelisted before it reaches the model.
        $status = (string) $this->request->query('status', '');
        $status = array_key_exists($status, Occurrence::STATUSES) ? $status : null;

        return $this->view('occurrences/index', [
            'title'       => 'Ocorrências',
            'activeNav'   => 'occurrences',
            'occurrences' => (new Occurrence())->listVisible((int) Auth::id(), Auth::hasRole(self::SEE_ALL), $status),
            'filter'      => $status,
            'seeAll'      => Auth::hasRole(self::SEE_ALL),
            'canCreate'   => Auth::hasRole(self::REPORTERS),
            'types'       => Occurrence::TYPES,
            'statuses'    => Occurrence::STATUSES,
        ]);
    }

    /** GET /occurrences/new */
    public function create(): Response
    {
        $this->requireRole(self::REPORTERS);

        return $this->view('occurrences/create', [
            'title'      => 'Nova ocorrência',
            'activeNav'  => 'occurrences',
            'types'      => Occurrence::TYPES,
            'categories' => Occurrence::CATEGORIES,
            'units'      => $this->ownUnits(),
        ]);
    }

    /** POST /occurrences */
    public function store(): Response
    {
        $this->requireRole(self::REPORTERS);

        $v = new Validator($this->request);
        $type = $v->enum('occurrence_type', 'Tipo', array_keys(Occurrence::TYPES));
        $category = $v->enum('category', 'Categoria', array_keys(Occurrence::CATEGORIES));
        $title = $v->string('title', 'Título', 5, 150);
        $description = $v->string('description', 'Descrição', 10, 5000);
        $location = $v->string('location', 'Local', 2, 120, required: false);

        // Optional unit: only one of the reporter's own units is accepted.
        $unitId = null;
        if ($this->request->string('unit_id') !== '') {
            $unitId = $v->id('unit_id', 'a unidade');
            $ownIds = array_map(static fn (array $u): int => (int) $u['id'], $this->ownUnits());
            if ($unitId !== null && !in_array($unitId, $ownIds, true)) {
                $v->addError('unit_id', 'Unidade inválida.');
            }
        }

        $keep = ['occurrence_type', 'category', 'title', 'description', 'location', 'unit_id'];
        if ($v->fails()) {
            return $this->invalid($v->errors(), '/occurrences/new', 422, $keep);
        }

        $result = (new OccurrenceService())->open([
            'occurrence_type' => $type,
            'category'        => $category,
            'title'           => $title,
            'description'     => $description,
            'location'        => $location,
            'unit_id'         => $unitId,
        ], (int) Auth::id());

        return $this->done("Ocorrência nº {$result['protocol_number']} registrada.", '/occurrences/' . $result['id']);
    }

    /** GET /occurrences/{id}: a ticket with its replies, after the visibility check. */
    public function show(string $id): Response
    {
        $this->requireRole(self::VIEWERS);
        $occurrence = $this->findVisibleOr404((int) $id);
        $isHandler = Auth::hasRole(self::HANDLERS);

        return $this->view('occurrences/show', [
            'title'      => 'Ocorrência nº ' . $occurrence['protocol_number'],
            'activeNav'  => 'occurrences',
            'occurrence' => $occurrence,
            // Internal notes are filtered in SQL for non-staff, not just hidden in HTML.
            'updates'    => (new OccurrenceUpdate())->forOccurrence((int) $occurrence['id'], Auth::hasRole(self::SEE_ALL)),
            'canReply'   => Auth::hasRole(self::REPORTERS),
            'isHandler'  => $isHandler,
            'types'      => Occurrence::TYPES,
            'categories' => Occurrence::CATEGORIES,
            'statuses'   => Occurrence::STATUSES,
        ]);
    }

    /** POST /occurrences/{id}/replies */
    public function reply(string $id): Response
    {
        $this->requireRole(self::REPORTERS);
        // Ownership: a resident can only reply to a ticket they can see (their own).
        $occurrence = $this->findVisibleOr404((int) $id);
        $back = '/occurrences/' . $occurrence['id'];

        $v = new Validator($this->request);
        $message = $v->string('message', 'Resposta', 2, 5000);
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back, 422, ['message']);
        }

        // Only handlers can mark a note as internal; for anyone else the flag is ignored.
        $internal = Auth::hasRole(self::HANDLERS) && $this->request->boolean('is_internal');
        (new OccurrenceService())->reply((int) $occurrence['id'], (int) Auth::id(), (string) $message, $internal);

        return $this->done('Resposta enviada.', $back);
    }

    /** POST /occurrences/{id}/status: managers only. */
    public function updateStatus(string $id): Response
    {
        $this->requireRole(self::HANDLERS);
        $occurrence = $this->findVisibleOr404((int) $id);
        $back = '/occurrences/' . $occurrence['id'];

        $v = new Validator($this->request);
        $status = $v->enum('status', 'Status', array_keys(Occurrence::STATUSES));
        $message = $v->string('message', 'Mensagem', 2, 5000, required: false);
        if ($v->fails()) {
            return $this->invalid($v->errors(), $back);
        }

        try {
            (new OccurrenceService())->changeStatus(
                (int) $occurrence['id'],
                (string) $occurrence['status'],
                (string) $status,
                (int) Auth::id(),
                $message
            );
        } catch (BusinessRuleException $e) {
            return $this->invalid(['status' => $e->getMessage()], $back, $e->status());
        }

        return $this->done('Status atualizado para "' . Occurrence::STATUSES[$status] . '".', $back);
    }

    /**
     * Loads a ticket the current user may see, or answers 404.
     *
     * 404 (not 403) for other people's tickets: a 403 would confirm that the id exists.
     *
     * @return array<string, mixed>
     */
    private function findVisibleOr404(int $id): array
    {
        return (new Occurrence())->findVisible($id, (int) Auth::id(), Auth::hasRole(self::SEE_ALL))
            ?? throw new HttpException(404);
    }

    /** @return list<array{id: int, label: string}> Units the user lives in. */
    private function ownUnits(): array
    {
        $mine = (new UnitResident())->activeUnitIds((int) Auth::id());

        return array_values(array_filter(
            (new Unit())->active(),
            static fn (array $u): bool => in_array((int) $u['id'], $mine, true)
        ));
    }
}

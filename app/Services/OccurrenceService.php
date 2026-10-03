<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Occurrence;
use App\Models\OccurrenceUpdate;
use App\Models\TenantCounter;

/**
 * Ticket lifecycle: opening (with protocol number), replies and status changes.
 */
final class OccurrenceService
{
    public function __construct(
        private readonly Occurrence $occurrences = new Occurrence(),
        private readonly OccurrenceUpdate $updates = new OccurrenceUpdate(),
        private readonly TenantCounter $counters = new TenantCounter()
    ) {
    }

    /**
     * Opens a ticket. The protocol number is taken from the tenant's counter
     * inside the same transaction, so numbers are unique and gap-free.
     *
     * @param array{occurrence_type: string, category: string, title: string, description: string,
     *              location: ?string, unit_id: ?int} $data
     * @return array{id: int, protocol_number: int}
     */
    public function open(array $data, int $reporterUserId): array
    {
        return Database::transaction(function () use ($data, $reporterUserId): array {
            $protocol = $this->counters->next('occurrence');
            $id = $this->occurrences->insert($data + [
                'protocol_number'     => $protocol,
                'reported_by_user_id' => $reporterUserId,
                'status'              => 'open',
            ]);

            return ['id' => $id, 'protocol_number' => $protocol];
        });
    }

    /** Appends a reply. Visibility of the ticket must already be checked by the caller. */
    public function reply(int $occurrenceId, int $authorUserId, string $message, bool $internal): void
    {
        $this->updates->insert([
            'occurrence_id'  => $occurrenceId,
            'author_user_id' => $authorUserId,
            'message'        => $message,
            'is_internal'    => $internal ? 1 : 0,
        ]);
    }

    /**
     * Changes the status and records the change in the timeline, atomically.
     *
     * @throws BusinessRuleException 409 if someone changed the ticket meanwhile.
     */
    public function changeStatus(int $occurrenceId, string $from, string $to, int $authorUserId, ?string $message): void
    {
        if ($from === $to) {
            throw new BusinessRuleException('A ocorrência já está com este status.', 409, 'status');
        }

        Database::transaction(function () use ($occurrenceId, $from, $to, $authorUserId, $message): void {
            if (!$this->occurrences->changeStatus($occurrenceId, $from, $to)) {
                throw new BusinessRuleException('A ocorrência foi alterada por outra pessoa. Recarregue a página.', 409);
            }
            $this->updates->insert([
                'occurrence_id'  => $occurrenceId,
                'author_user_id' => $authorUserId,
                'message'        => $message,
                'status_from'    => $from,
                'status_to'      => $to,
                'is_internal'    => 0,
            ]);
        });
    }
}

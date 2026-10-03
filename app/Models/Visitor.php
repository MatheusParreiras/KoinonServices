<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Registry of people who visited the current condominium (`visitors`).
 *
 * LGPD: document_number (RG) is personal data. It is stored because the desk
 * must identify returning and blocked visitors, but it is never sent to the
 * browser in full: views show mask_document(), and no JSON payload includes it.
 * Each condominium has its own registry; a visitor known in one condominium is
 * unknown in another.
 */
final class Visitor extends TenantModel
{
    protected string $table = 'visitors';

    protected array $fillable = [
        'full_name',
        'document_type',
        'document_number',
        'phone',
        'created_by_user_id',
    ];

    /**
     * The visitor of THIS condominium with this document, or null.
     * Matches the unique key uq_visitors_document (condominium_id, document_type, document_number).
     */
    public function findByDocument(string $documentType, string $documentNumber): ?array
    {
        return $this->fetchOne(
            'SELECT id, full_name, is_blocked, block_reason
               FROM visitors
              WHERE condominium_id = :tenant
                AND document_type = :document_type
                AND document_number = :document_number',
            $this->scoped(['document_type' => $documentType, 'document_number' => $documentNumber])
        );
    }
}

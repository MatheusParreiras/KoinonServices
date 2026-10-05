<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Money received against an invoice (`payments`, Phase 1 table). Recorded
 * manually by a Property Manager in Phase 5; rows are never edited or deleted
 * (a mistake is corrected by reversing, which is outside this phase).
 */
final class Payment extends TenantModel
{
    protected string $table = 'payments';

    // condominium_id is intentionally absent: TenantModel::insert() sets it.
    protected array $fillable = [
        'invoice_id',
        'amount',
        'paid_at',
        'payment_method',
        'external_reference',
        'recorded_by_user_id',
        'notes',
    ];
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Lines of an invoice (`invoice_items`).
 */
final class InvoiceItem extends TenantModel
{
    protected string $table = 'invoice_items';

    protected array $fillable = [
        'invoice_id',
        'category_id',
        'description',
        'amount',
    ];
}

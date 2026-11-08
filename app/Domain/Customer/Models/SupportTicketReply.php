<?php

namespace App\Domain\Customer\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['support_ticket_id', 'admin_id', 'author', 'body'])]
class SupportTicketReply extends Model
{
    public const AUTHOR_CUSTOMER = 'customer';

    public const AUTHOR_STAFF = 'staff';

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}

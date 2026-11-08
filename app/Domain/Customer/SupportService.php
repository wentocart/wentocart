<?php

namespace App\Domain\Customer;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\SupportTicket;
use App\Domain\Customer\Models\SupportTicketReply;
use App\Domain\Notify\AdminInbox;
use App\Domain\Settings\ShopMailer;
use App\Domain\Store\StoreContext;
use App\Models\Admin;

class SupportService
{
    public function __construct(protected ShopMailer $mailer) {}

    public function openFromContact(string $name, string $email, string $message, ?string $orderNumber = null, ?Customer $customer = null): SupportTicket
    {
        if (! $customer) {
            $customer = Customer::query()->where('email', $email)->first();
        }

        $ticket = SupportTicket::query()->create([
            'store_id' => app(StoreContext::class)->id() ?: null,
            'customer_id' => $customer?->id,
            'name' => $name,
            'email' => $email,
            'order_number' => $orderNumber !== null && $orderNumber !== '' ? $orderNumber : null,
            'body' => $message,
            'status' => SupportTicket::STATUS_OPEN,
        ]);

        AdminInbox::contactReceived($name, $email, $message, $orderNumber, $ticket);
        $this->notifyStaff($ticket);

        return $ticket;
    }

    public function reply(SupportTicket $ticket, string $body, ?Admin $admin = null, bool $emailCustomer = true): SupportTicketReply
    {
        $reply = $ticket->replies()->create([
            'admin_id' => $admin?->id,
            'author' => SupportTicketReply::AUTHOR_STAFF,
            'body' => $body,
        ]);

        $ticket->status = SupportTicket::STATUS_REPLIED;
        $ticket->replied_at = now();
        $ticket->save();

        if ($emailCustomer && SupportSettings::replyEmailEnabled()) {
            $this->notifyCustomer($ticket, $body);
        }

        return $reply;
    }

    public function close(SupportTicket $ticket): void
    {
        $ticket->status = SupportTicket::STATUS_CLOSED;
        $ticket->save();
    }

    public function reopen(SupportTicket $ticket): void
    {
        $ticket->status = $ticket->replies()->exists()
            ? SupportTicket::STATUS_REPLIED
            : SupportTicket::STATUS_OPEN;
        $ticket->save();
    }

    protected function notifyStaff(SupportTicket $ticket): void
    {
        if (! SupportSettings::notifyEnabled()) {
            return;
        }

        $recipients = SupportSettings::notifyEmails();
        if ($recipients === []) {
            return;
        }

        $order = $ticket->order_number ? '<p>Order: '.e($ticket->order_number).'</p>' : '';
        $html = '<p>From '.e($ticket->name).' &lt;'.e($ticket->email).'&gt;</p>'
            .$order
            .'<p>'.nl2br(e($ticket->body)).'</p>'
            .'<p><a href="'.e(url('/admin/support/'.$ticket->id)).'">Open in Support</a></p>';
        $subject = 'Support message from '.$ticket->name;

        foreach ($recipients as $to) {
            $this->mailer->sendHtml($to, $subject, $html, 'support_contact');
        }
    }

    protected function notifyCustomer(SupportTicket $ticket, string $replyBody): void
    {
        $html = '<p>Hi '.e($ticket->name).',</p>'
            .'<p>We replied to your message:</p>'
            .'<p>'.nl2br(e($replyBody)).'</p>';
        $this->mailer->sendHtml($ticket->email, 'Re: your message to the shop', $html, 'support_reply');
    }
}

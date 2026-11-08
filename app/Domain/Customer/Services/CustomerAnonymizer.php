<?php

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CustomerAnonymizer
{
    /**
     * Blank personal fields that must survive because orders are kept.
     * The customer row itself is left for the caller to anonymize or delete.
     */
    public function scrubPersonalRecords(Customer $customer): void
    {
        $id = (int) $customer->id;
        $email = 'deleted-'.$id.'@anonymized.invalid';

        Order::query()->where('customer_id', $id)->orderBy('id')->each(function (Order $order) use ($email) {
            $order->forceFill([
                'email' => $email,
                'customer_note' => null,
                'billing_address' => $this->blankAddress($order->billing_address),
                'shipping_address' => $this->blankAddress($order->shipping_address),
            ])->save();
        });

        $this->rewriteEmail('checkout_groups', $id, $email);
        if (Schema::hasTable('checkout_groups') && Schema::hasColumn('checkout_groups', 'payment_details')) {
            DB::table('checkout_groups')->where('customer_id', $id)->update(['payment_details' => null]);
        }
        $this->rewriteEmail('discount_redemptions', $id, $email);
        $this->rewriteEmail('price_alerts', $id, $email);

        if (Schema::hasTable('customer_stock_alerts')) {
            DB::table('customer_stock_alerts')->where('customer_id', $id)->update([
                'email' => $email,
                'first_name' => null,
                'last_name' => null,
            ]);
        }
    }

    /**
     * Remove personal rows that have no foreign key to customers and would
     * otherwise survive as orphans: AI chats, support threads, passkeys,
     * shopper answers and gift-card messages. Shared by erasure (row kept)
     * and admin hard-delete (row removed, cascades handle the rest).
     */
    public function purgeUnlinkedPersonalData(Customer $customer): void
    {
        $id = (int) $customer->id;
        $originalEmail = strtolower(trim((string) $customer->email));
        $email = 'deleted-'.$id.'@anonymized.invalid';

        if (Schema::hasTable('ai_customer_chats')) {
            DB::table('ai_customer_chats')
                ->where('customer_id', $id)
                ->when($originalEmail !== '', fn ($q) => $q->orWhere('email', $originalEmail))
                ->delete();
        }

        if (Schema::hasTable('support_tickets')) {
            $ticketIds = DB::table('support_tickets')
                ->where('customer_id', $id)
                ->when($originalEmail !== '', fn ($q) => $q->orWhere('email', $originalEmail))
                ->pluck('id');
            if (Schema::hasTable('support_ticket_replies')) {
                DB::table('support_ticket_replies')->whereIn('support_ticket_id', $ticketIds)->delete();
            }
            DB::table('support_tickets')->whereIn('id', $ticketIds)->delete();
        }

        if (method_exists($customer, 'webauthnCredentials')) {
            $customer->webauthnCredentials()->delete();
        }

        if (Schema::hasTable('product_answers')) {
            DB::table('product_answers')->where('customer_id', $id)->delete();
        }

        if (Schema::hasTable('gift_cards')) {
            $cards = DB::table('gift_cards')->where('customer_id', $id);
            $cards->update(['sender_name' => 'Deleted customer', 'gift_message' => null]);
            if ($originalEmail !== '') {
                DB::table('gift_cards')
                    ->where('customer_id', $id)
                    ->where('recipient_email', $originalEmail)
                    ->update(['recipient_email' => $email]);
            }
        }
    }

    public function erase(Customer $customer, ?int $keepRequestId = null): void
    {
        DB::transaction(function () use ($customer, $keepRequestId) {
            $id = (int) $customer->id;
            $originalEmail = strtolower(trim((string) $customer->email));
            $email = 'deleted-'.$id.'@anonymized.invalid';
            $this->scrubPersonalRecords($customer);
            $this->purgeUnlinkedPersonalData($customer);

            $customer->addresses()->delete();
            $customer->wishlist()->detach();
            $customer->reviews()->delete();
            if (method_exists($customer, 'reviewVotes')) {
                $customer->reviewVotes()->delete();
            } elseif (Schema::hasTable('review_votes')) {
                DB::table('review_votes')->where('customer_id', $id)->delete();
            }
            if (Schema::hasTable('wishlists')) {
                DB::table('wishlists')->where('customer_id', $id)->delete();
            }
            if (Schema::hasTable('wishlist_shares')) {
                DB::table('wishlist_shares')->where('customer_id', $id)->delete();
            }
            if (Schema::hasTable('product_questions')) {
                DB::table('product_questions')->where('customer_id', $id)->delete();
            }
            if (Schema::hasTable('saved_payment_methods')) {
                DB::table('saved_payment_methods')->where('customer_id', $id)->delete();
            }
            // Alert and notify lists belong to the erased identity: remove,
            // don't re-target.
            foreach (['price_alerts', 'customer_stock_alerts', 'preorder_notify_subscriptions'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('customer_id', $id)->delete();
                }
            }
            if ($originalEmail !== '' && Schema::hasTable('stock_alerts')) {
                DB::table('stock_alerts')->where('email', $originalEmail)->delete();
            }
            if (Schema::hasTable('reviews') && $originalEmail !== '') {
                DB::table('reviews')
                    ->whereNull('customer_id')
                    ->where('guest_email', $originalEmail)
                    ->update(['guest_name' => 'Deleted customer', 'guest_email' => $email]);
            }
            // Abandoned carts and unfinished payment intents die with the account.
            if (Schema::hasTable('carts')) {
                DB::table('carts')->where('customer_id', $id)->delete();
            }
            if (Schema::hasTable('payment_checkouts')) {
                DB::table('payment_checkouts')->where('customer_id', $id)->delete();
                if ($originalEmail !== '') {
                    $scrub = ['email' => $email];
                    foreach (['checkout_snapshot', 'payment_proof', 'meta'] as $column) {
                        if (Schema::hasColumn('payment_checkouts', $column)) {
                            $scrub[$column] = null;
                        }
                    }
                    DB::table('payment_checkouts')->where('email', $originalEmail)->update($scrub);
                }
            }
            // Quotes stay as commercial rows; the personal note goes.
            if (Schema::hasTable('b2b_quotes')) {
                DB::table('b2b_quotes')->where('customer_id', $id)->update(['note' => null]);
            }
            // Active subscriptions cannot renew against a deleted account.
            if (Schema::hasTable('customer_subscriptions')) {
                DB::table('customer_subscriptions')
                    ->where('customer_id', $id)
                    ->whereIn('status', ['active', 'trialing', 'pending', 'paused', 'past_due'])
                    ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'ended_at' => now()]);
            }
            if (Schema::hasTable('gdpr_requests')) {
                $requests = DB::table('gdpr_requests')->where('customer_id', $id);
                if ($keepRequestId) {
                    $requests->where('id', '!=', $keepRequestId);
                }
                $requests->delete();
                if ($keepRequestId) {
                    // The approved request stays as the audit trail, without
                    // the customer's own words.
                    DB::table('gdpr_requests')->where('id', $keepRequestId)->update([
                        'message' => '[removed after account deletion]',
                        'requested_changes' => null,
                    ]);
                }
            }
            if (Schema::hasTable('gdpr_cookie_consents')) {
                DB::table('gdpr_cookie_consents')->where('customer_id', $id)->delete();
            }

            $customer->forceFill([
                'name' => 'Deleted customer',
                'first_name' => null,
                'last_name' => null,
                'email' => $email,
                'email_verified_at' => null,
                'phone' => null,
                'date_of_birth' => null,
                'gender' => null,
                'tax_vat' => null,
                'company_name' => null,
                'company_website' => null,
                'gst_tax_id' => null,
                'notes' => null,
                'profile' => null,
                'avatar_path' => null,
                'newsletter' => false,
                'password' => Hash::make(Str::random(40)),
                'remember_token' => null,
                'totp_secret' => null,
                'totp_confirmed_at' => null,
                'sandbox_payment_token' => null,
                'google_id' => null,
                'facebook_id' => null,
                'linkedin_id' => null,
                'is_enabled' => false,
            ])->save();
        });
    }

    /**
     * @return array{country_code: string}
     */
    private function blankAddress(mixed $address): array
    {
        $address = is_array($address) ? $address : [];
        $country = $address['country_code'] ?? $address['country'] ?? '';

        return [
            'country_code' => is_string($country) ? $country : '',
        ];
    }

    private function rewriteEmail(string $table, int $customerId, string $email): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'email')) {
            return;
        }
        DB::table($table)->where('customer_id', $customerId)->update(['email' => $email]);
    }
}

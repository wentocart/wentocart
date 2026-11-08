<?php

namespace App\Domain\Customer\Services;

use App\Domain\Customer\GdprSchema;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\GdprCookieConsent;
use App\Domain\Customer\Models\GdprRequest;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\DocumentPdf;
use App\Domain\Settings\GdprSettings;
use App\Domain\Settings\ShopMailer;
use App\Domain\Store\ShopIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GdprService
{
    public function __construct(
        private ShopMailer $mailer,
        private CustomerAnonymizer $anonymizer,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     */
    public function create(Customer $customer, string $type, string $message, array $fields = []): GdprRequest
    {
        $this->guardEnabled();
        GdprSchema::ensure();
        $type = strtolower(trim($type));
        $allowed = array_values(array_unique([...GdprSettings::TYPES, ...GdprSettings::GRAPHQL_TYPES]));
        if (! in_array($type, $allowed, true)) {
            throw ValidationException::withMessages([
                'type' => 'Type must be delete or update.',
            ]);
        }
        // Storefront REST remains delete-only; GraphQL may raise update requests.
        if ($type !== 'delete' && ! in_array($type, GdprSettings::GRAPHQL_TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => 'Only deletion requests can be sent. Change your details in your Profile.',
            ]);
        }
        if (trim($message) === '') {
            throw ValidationException::withMessages([
                'message' => $type === 'delete'
                    ? 'Tell the shop why the account should be deleted.'
                    : 'Tell the shop what personal data should be updated.',
            ]);
        }
        $open = GdprRequest::query()
            ->where('customer_id', $customer->id)
            ->where('type', $type)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();
        if ($open) {
            throw ValidationException::withMessages([
                'type' => 'You already have an open '.$type.' request. Revoke it before sending a new one.',
            ]);
        }
        $request = GdprRequest::query()->create([
            'customer_id' => $customer->id,
            'reference' => GdprSchema::newReference(),
            'type' => $type,
            'status' => 'pending',
            'message' => trim($message),
            'requested_changes' => $fields !== [] ? $fields : null,
        ]);
        $this->notify($request, 'received');

        return $request;
    }

    public function revoke(Customer $customer, GdprRequest $request): void
    {
        $this->guardEnabled();
        $this->owns($customer, $request);
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only a pending GDPR request can be revoked.',
            ]);
        }
        $request->delete();
        $this->notify($request, 'revoked');
    }

    public function deleteRequest(Customer $customer, GdprRequest $request): void
    {
        $this->guardEnabled();
        $this->owns($customer, $request);
        $request->delete();
    }

    public function updateStatus(GdprRequest $request, string $status): GdprRequest
    {
        GdprSchema::ensure();
        if (! in_array($status, GdprSettings::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Choose a GDPR request status.']);
        }
        $previous = (string) $request->status;
        $mailTo = (string) ($request->customer?->email ?? '');
        $alreadyDone = in_array($previous, ['completed', 'approved'], true);
        DB::transaction(function () use ($request, $status, $alreadyDone) {
            $request->forceFill(['status' => $status])->save();
            if ($alreadyDone || ! $request->customer) {
                return;
            }
            if ($status === 'completed') {
                // Wento admin UI "complete" — execute delete or apply update fields.
                if ($request->type === 'delete') {
                    $this->anonymizer->erase($request->customer, $request->id);
                } else {
                    $this->applyUpdate($request);
                }

                return;
            }
            if ($status === 'approved' && $request->type === 'delete') {
                $this->anonymizer->erase($request->customer, $request->id);
            }
        });
        $fresh = $request->fresh() ?? $request;
        if ($mailTo !== '') {
            $this->notify($fresh, 'status', $mailTo);
        }

        return $fresh;
    }

    /**
     *
     * @return array{request: GdprRequest, customerDeleted: bool}
     */
    public function process(GdprRequest $request): array
    {
        GdprSchema::ensure();
        $status = (string) $request->status;
        if (in_array($status, GdprSettings::TERMINAL_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'This GDPR request was already finalized and cannot be processed again.',
            ]);
        }
        $customerId = (int) $request->customer_id;
        $isDelete = $request->type === 'delete';
        $updated = $this->updateStatus($request, 'approved');
        // Wento erase() anonymizes in place (hard-deletes). Treat scrubbed
        // accounts as deleted for the customerDeleted response flag.
        $customer = Customer::query()->find($customerId);
        $customerDeleted = $isDelete && (
            $customer === null
            || str_ends_with((string) $customer->email, '@anonymized.invalid')
            || ! (bool) $customer->is_enabled
        );

        return ['request' => $updated, 'customerDeleted' => $customerDeleted];
    }

    /**
     * @param  array<string, bool>  $choices
     */
    public function saveCookies(Customer $customer, array $choices): GdprCookieConsent
    {
        $this->guardEnabled();
        GdprSchema::ensure();

        return GdprCookieConsent::query()->updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'strictly_necessary' => true,
                'basic' => ! empty($choices['basic']),
                'experience' => ! empty($choices['experience']),
                'measurements' => ! empty($choices['measurements']),
                'targeting' => ! empty($choices['targeting']),
            ],
        );
    }

    public function exportHtml(Customer $customer): string
    {
        $this->guardEnabled();
        $customer->load(['addresses', 'orders.items']);

        return View::make('gdpr.export', [
            'customer' => $customer,
            'exportedAt' => now()->toIso8601String(),
        ])->render();
    }

    public function exportPdf(Customer $customer): string
    {
        return app(DocumentPdf::class)->output($this->exportHtml($customer));
    }

    private function guardEnabled(): void
    {
        if (! GdprSettings::enabled()) {
            abort(404);
        }
    }

    private function owns(Customer $customer, GdprRequest $request): void
    {
        if ((int) $request->customer_id !== (int) $customer->id) {
            abort(404);
        }
    }

    /**
     * @param  array<string, array{from: string, to: string}>  $changes
     */
    private function assertChangesAreValid(Customer $customer, array $changes): void
    {
        $rules = [];
        $data = [];
        foreach ($changes as $key => $pair) {
            $data[$key] = $pair['to'] === '' ? null : $pair['to'];
            $rules[$key] = match ($key) {
                'first_name', 'last_name' => ['required', 'string', 'max:80'],
                'email' => ['required', 'email', 'max:255', Rule::unique('customers', 'email')->where(fn ($query) => $query->where('store_id', $customer->store_id))->ignore($customer->id)],
                'phone' => CustomerSettings::phoneRules(),
                'date_of_birth' => ['nullable', 'date'],
                'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
                default => ['nullable', 'string'],
            };
        }
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }
    }

    private function applyUpdate(GdprRequest $request): void
    {
        $customer = $request->customer;
        if (! $customer) {
            return;
        }
        $changes = is_array($request->requested_changes) ? $request->requested_changes : [];
        if ($changes === []) {
            return;
        }
        $this->assertChangesAreValid($customer, $changes);
        $next = [];
        foreach ($changes as $key => $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $value = (string) ($pair['to'] ?? '');
            $next[$key] = $value === '' ? null : $value;
        }
        if (isset($next['first_name']) || isset($next['last_name'])) {
            $first = (string) ($next['first_name'] ?? $customer->first_name ?? '');
            $last = (string) ($next['last_name'] ?? $customer->last_name ?? '');
            $next['name'] = trim($first.' '.$last);
        }
        $customer->forceFill($next)->save();
    }

    private function notify(GdprRequest $request, string $event, ?string $mailTo = null): void
    {
        $request->loadMissing('customer');
        $customer = $request->customer;
        $address = trim($mailTo ?? (string) ($customer->email ?? ''));
        if ($address === '') {
            return;
        }
        $type = $request->type === 'delete' ? 'Delete' : 'Update';
        $status = ucfirst((string) $request->status);
        $subject = match ($event) {
            'revoked' => 'GDPR request revoked',
            'status' => 'GDPR request '.$status,
            default => 'GDPR request received',
        };
        $lines = '';
        foreach ($request->changeRows() as $row) {
            $from = $row['from'] !== '' ? $row['from'] : 'empty';
            $to = $row['to'] !== '' ? $row['to'] : 'empty';
            $lines .= '<p>'.e($row['label']).': '.e($from).' → '.e($to).'</p>';
        }
        if ($request->type === 'delete') {
            $lines .= '<p>Delete the account.</p>';
        }
        $html = '<p>'.e($subject).'.</p>'
            .'<p>Request '.e((string) ($request->reference ?: '#'.$request->id)).' · '.e($type).' · '.e($status).'</p>'
            .$lines
            .($request->message !== '' ? '<p>'.e((string) $request->message).'</p>' : '');

        $this->mailer->sendHtml($address, $subject, $html, 'gdpr');
        $admin = trim((string) (ShopIdentity::get()['email'] ?? ''));
        if ($admin !== '' && strcasecmp($admin, $address) !== 0) {
            $this->mailer->sendHtml($admin, $subject, $html, 'gdpr');
        }
    }
}

<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\CustomerAnonymizer;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Order\Models\Order;
use App\Domain\Security\CustomerPassword;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Security\SessionRevoker;
use App\Domain\Store\StoreContext;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Shop REST customer account surface: profile, logout, verify, orders.
 */
final class GraphqlCustomerAccount
{
    /**
     * @return array<string, mixed>
     */
    public static function readProfile(): array
    {
        $user = self::requireCustomer();

        return self::mapProfile($user, true, null);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{customerProfileUpdate: array<string, mixed>}
     */
    public static function updateProfile(array $args): array
    {
        $user = self::requireCustomer();
        $home = app(StoreContext::class)->customerHomeId() ?: (int) $user->store_id;

        $changingPassword = array_key_exists('password', $args) && trim((string) $args['password']) !== '';
        if ($changingPassword) {
            $current = (string) ($args['currentPassword'] ?? '');
            $password = (string) $args['password'];
            $confirm = (string) ($args['confirmPassword'] ?? $args['passwordConfirmation'] ?? '');
            if ($current === '' || ! Hash::check($current, (string) $user->password)) {
                throw new UserError('Current password is incorrect.');
            }
            try {
                validator(
                    [
                        'password' => $password,
                        'password_confirmation' => $confirm,
                    ],
                    ['password' => CustomerPassword::rules()],
                    CustomerPassword::messages()
                )->validate();
            } catch (ValidationException $e) {
                $messages = collect($e->errors())->flatten()->filter()->values()->all();
                throw new UserError($messages[0] ?? 'Password change failed.');
            }
            $user->forceFill(['password' => $password])->save();
            SessionRevoker::revokeOthers('customer', $user->id);
        }

        $payload = [];
        if (array_key_exists('firstName', $args) && $args['firstName'] !== null) {
            $payload['first_name'] = trim((string) $args['firstName']);
        }
        if (array_key_exists('lastName', $args) && $args['lastName'] !== null) {
            $payload['last_name'] = trim((string) $args['lastName']);
        }
        if (array_key_exists('phone', $args) && $args['phone'] !== null) {
            $payload['phone'] = trim((string) $args['phone']) ?: null;
        }
        if (array_key_exists('gender', $args) && $args['gender'] !== null) {
            $gender = strtolower(trim((string) $args['gender']));
            if (! in_array($gender, ['male', 'female', 'other', ''], true)) {
                throw new UserError('Gender must be male, female, or other.');
            }
            $payload['gender'] = $gender !== '' ? $gender : null;
        }
        if (array_key_exists('dateOfBirth', $args) && $args['dateOfBirth'] !== null) {
            $dob = trim((string) $args['dateOfBirth']);
            if ($dob !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
                throw new UserError('Date of birth must be YYYY-MM-DD.');
            }
            $payload['date_of_birth'] = $dob !== '' ? $dob : null;
        }
        if (array_key_exists('email', $args) && $args['email'] !== null) {
            $email = strtolower(trim((string) $args['email']));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new UserError('A valid email is required.');
            }
            $exists = Customer::query()
                ->where('email', $email)
                ->where('store_id', $home)
                ->where('id', '!=', $user->id)
                ->exists();
            if ($exists) {
                throw new UserError('The email has already been taken.');
            }
            $payload['email'] = $email;
        }
        if (array_key_exists('subscribedToNewsLetter', $args) && $args['subscribedToNewsLetter'] !== null) {
            $sub = (bool) $args['subscribedToNewsLetter'];
            $payload['newsletter'] = $sub;
            $email = strtolower((string) ($payload['email'] ?? $user->email));
            if ($sub) {
                NewsletterSubscriber::query()->firstOrCreate(
                    ['email' => $email],
                    [
                        'ip' => mb_substr((string) request()->ip(), 0, 45),
                        'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
                        'is_subscribed' => true,
                    ]
                );
            } else {
                NewsletterSubscriber::query()->where('email', $email)->update(['is_subscribed' => false]);
            }
        }

        if ($payload !== []) {
            if (isset($payload['first_name']) || isset($payload['last_name'])) {
                $first = $payload['first_name'] ?? $user->first_name;
                $last = $payload['last_name'] ?? $user->last_name;
                $payload['name'] = trim(($first ?? '').' '.($last ?? '')) ?: $user->name;
            }
            $user->fill($payload)->save();
        }

        $user->refresh();

        return [
            'customerProfileUpdate' => self::mapProfile($user, true, 'Profile updated.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{success: bool, message: string}
     */
    public static function deleteProfile(array $args): array
    {
        if (! CustomerSettings::allowAccountDeletion()) {
            throw new UserError('Account deletion is disabled.');
        }
        $user = self::requireCustomer();
        $password = (string) ($args['password'] ?? '');
        if ($password === '' || ! Hash::check($password, (string) $user->password)) {
            throw new UserError('Current password is incorrect.');
        }

        Auth::guard('customer')->logout();
        GraphqlCustomerAuth::revokeBearer(request());
        app(CustomerAnonymizer::class)->erase($user);

        return [
            'success' => true,
            'message' => 'Account deleted.',
        ];
    }

    /**
     * @return array{success: bool, message: string, status: bool}
     */
    public static function logout(): array
    {
        $user = auth('customer')->user();
        GraphqlCustomerAuth::revokeBearer(request());
        Auth::guard('customer')->logout();
        if ($user instanceof Customer) {
            // Intentionally no-op beyond bearer revoke when session-less.
        }

        return [
            'success' => true,
            'status' => true,
            'message' => 'Logged out successfully.',
        ];
    }

    /**
     * @return array{success: bool, valid: bool, message: string, customer: ?array<string, mixed>}
     */
    public static function verifyToken(): array
    {
        $user = auth('customer')->user();
        if (! $user instanceof Customer) {
            return [
                'success' => false,
                'valid' => false,
                'message' => 'Invalid or expired token.',
                'customer' => null,
            ];
        }

        return [
            'success' => true,
            'valid' => true,
            'message' => 'Token is valid.',
            'customer' => [
                'id' => (string) $user->id,
                'email' => $user->email,
                'name' => $user->displayName(),
                'phone' => $user->phone,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>}
     */
    public static function customerOrders(array $args): array
    {
        $user = self::requireCustomer();
        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 20)));
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^order:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $q = $user->orders()->orderByDesc('id');
        $status = trim((string) ($args['status'] ?? ''));
        if ($status !== '') {
            $q->where('status', $status);
        }
        if ($afterId > 0) {
            $q->where('id', '<', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $edges = $slice->map(function (Order $o) {
            return [
                'cursor' => base64_encode('order:'.$o->id),
                'node' => self::mapOrder($o),
            ];
        })->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'endCursor' => $end ? base64_encode('order:'.$end->id) : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function customerOrder(array $args): array
    {
        $user = self::requireCustomer();
        $id = (int) ($args['id'] ?? 0);
        $number = trim((string) ($args['incrementId'] ?? $args['number'] ?? ''));
        $q = $user->orders();
        if ($id > 0) {
            $q->whereKey($id);
        } elseif ($number !== '') {
            $q->where('number', $number);
        } else {
            throw new UserError('Order not found.');
        }
        $order = $q->first();
        if (! $order) {
            throw new UserError('Order not found.');
        }

        return self::mapOrder($order);
    }

    protected static function requireCustomer(): Customer
    {
        $user = auth('customer')->user();
        if (! $user instanceof Customer) {
            throw new UserError('Authentication required.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapProfile(Customer $user, bool $success, ?string $message): array
    {
        return [
            'id' => (string) $user->id,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'gender' => $user->gender,
            'dateOfBirth' => optional($user->date_of_birth)?->toDateString(),
            'status' => $user->is_enabled ? 'active' : 'inactive',
            'subscribedToNewsLetter' => (bool) $user->newsletter,
            'isVerified' => $user->email_verified_at ? '1' : '0',
            'isSuspended' => $user->is_enabled ? '0' : '1',
            'image' => $user->avatar_path ? '/storage/'.ltrim((string) $user->avatar_path, '/') : null,
            'name' => $user->displayName(),
            'success' => $success,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    public static function mapOrderPublic(Order $o): array
    {
        return self::mapOrder($o);
    }

    protected static function mapOrder(Order $o): array
    {
        $grand = $o->grand_total !== null ? (float) $o->grand_total : null;
        $o->loadMissing(['shippingMethod', 'paymentMethod']);

        return [
            'id' => (string) $o->id,

            'number' => $o->number,
            'incrementId' => $o->number,
            'status' => $o->status,
            'payment_status' => $o->payment_status,
            'grand_total' => $grand,
            'grandTotal' => $grand,
            'subtotal' => $o->subtotal !== null ? (float) $o->subtotal : null,
            'taxTotal' => $o->tax_total !== null ? (float) $o->tax_total : null,
            'shippingTotal' => $o->shipping_total !== null ? (float) $o->shipping_total : null,
            'discountAmount' => $o->discount_total !== null ? (float) $o->discount_total : null,
            'couponCode' => is_array($o->payment_details ?? null)
                ? ($o->payment_details['coupon_code'] ?? null)
                : null,
            'currency_code' => $o->currency_code,
            'orderCurrencyCode' => $o->currency_code,
            'shippingMethod' => $o->shippingMethod?->code ?? $o->shippingMethod?->name,
            'paymentMethod' => $o->paymentMethod?->code ?? $o->paymentMethod?->name,
            'itemsCount' => (int) $o->items()->count(),
            'billingAddress' => GraphqlCustomerAccountExtras::orderAddress($o, 'billing'),
            'shippingAddress' => GraphqlCustomerAccountExtras::orderAddress($o, 'shipping'),
            'created_at' => optional($o->created_at)?->toIso8601String(),
            'createdAt' => optional($o->created_at)?->toIso8601String(),
        ];
    }
}

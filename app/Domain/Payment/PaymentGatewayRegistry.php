<?php

namespace App\Domain\Payment;

use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Drivers\OfflinePaymentGateway;
use App\Domain\Payment\Drivers\PaypalGateway;
use App\Domain\Payment\Drivers\RazorpayGateway;
use App\Domain\Payment\Drivers\StripeGateway;
use App\Domain\Payment\Drivers\UpiGateway;
use InvalidArgumentException;

class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    protected array $drivers = [];

    public function __construct()
    {
        $this->bootCore();
    }

    protected function bootCore(): void
    {
        $this->register(new OfflinePaymentGateway);
        $this->register(new PaypalGateway);
        $this->register(new RazorpayGateway);
        $this->register(new StripeGateway);
        $this->register(new UpiGateway);
    }

    public function register(PaymentGateway $gateway): void
    {
        $id = strtolower(trim($gateway->driver()));
        if ($id === '') {
            throw new InvalidArgumentException('Payment driver id is required.');
        }
        $this->drivers[$id] = $gateway;
    }

    public function get(string $driver): PaymentGateway
    {
        $driver = strtolower(trim($driver)) ?: 'offline';
        if (! isset($this->drivers[$driver])) {
            throw new InvalidArgumentException('Unknown payment driver: '.$driver);
        }

        return $this->drivers[$driver];
    }

    public function has(string $driver): bool
    {
        return isset($this->drivers[strtolower(trim($driver))]);
    }

    /**
     * Gateways that can charge a saved method off-session (subscription renewals).
     *
     * @return list<string>
     */
    public function recurringCapableIds(): array
    {
        return array_values(array_keys(array_filter(
            $this->drivers,
            fn (PaymentGateway $gateway) => $gateway instanceof \App\Domain\Payment\Contracts\ChargesSavedMethods,
        )));
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->drivers);
    }

    public function flush(): void
    {
        $this->drivers = [];
        $this->bootCore();
    }
}

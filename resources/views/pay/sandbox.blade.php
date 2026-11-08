<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $shop }} — Pay {{ $order->number }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 32rem; margin: 4rem auto; padding: 0 1.25rem; color: #171717; }
        .card { border: 1px solid #e5e5e5; border-radius: 12px; padding: 1.5rem; }
        button { background: #171717; color: #fff; border: 0; border-radius: 8px; padding: .7rem 1.1rem; cursor: pointer; }
        a { color: #525252; }
    </style>
</head>
<body>
    <div class="card">
        <p style="font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;color:#737373">Sandbox {{ strtoupper($driver) }}</p>
        <h1>Pay order {{ $order->number }}</h1>
        <p>{{ number_format((float) $order->grand_total, 2) }} {{ $order->currency_code }}</p>
        <p>No live {{ $driver }} keys are configured. Confirm to mark this demo order paid. Turn sandbox pay off in production.</p>
        @if (!empty($order->paymentMethod?->config['vpa']))
            <p>Pay to UPI ID <strong>{{ $order->paymentMethod->config['vpa'] }}</strong> then confirm.</p>
        @endif
        @if ($order->payment_status === 'paid')
            <p>This order is already paid.</p>
            <p><a href="{{ url('/order/'.$order->number) }}">Back to order</a></p>
        @else
            <form method="post" action="{{ $confirmUrl }}">
                @csrf
                <button type="submit">Confirm sandbox payment</button>
            </form>
        @endif
    </div>
</body>
</html>

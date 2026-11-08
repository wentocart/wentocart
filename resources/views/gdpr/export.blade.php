<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>GDPR data export</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 18px; }
        h2 { font-size: 14px; margin-top: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; vertical-align: top; }
    </style>
</head>
<body>
    <h1>GDPR data export</h1>
    <p>Exported {{ $exportedAt }}</p>
    <h2>Account</h2>
    <table>
        <tr><th>Name</th><td>{{ $customer->name }}</td></tr>
        <tr><th>Email</th><td>{{ $customer->email }}</td></tr>
        <tr><th>Phone</th><td>{{ $customer->phone }}</td></tr>
    </table>
    <h2>Addresses</h2>
    <table>
        <tr><th>Name</th><th>Phone</th><th>Address</th><th>City</th><th>Country</th></tr>
        @forelse ($customer->addresses as $address)
            <tr>
                <td>{{ trim(($address->first_name ?? '').' '.($address->last_name ?? '')) }}</td>
                <td>{{ $address->phone }}</td>
                <td>{{ $address->line1 }} {{ $address->line2 }}</td>
                <td>{{ $address->city }}</td>
                <td>{{ $address->country_code }}</td>
            </tr>
        @empty
            <tr><td colspan="5">No addresses.</td></tr>
        @endforelse
    </table>
    <h2>Orders</h2>
    <table>
        <tr><th>Number</th><th>Status</th><th>Total</th><th>Date</th></tr>
        @forelse ($customer->orders as $order)
            <tr>
                <td>{{ $order->number }}</td>
                <td>{{ $order->status }}</td>
                <td>{{ $order->grand_total }}</td>
                <td>{{ optional($order->created_at)->toDateTimeString() }}</td>
            </tr>
        @empty
            <tr><td colspan="4">No orders.</td></tr>
        @endforelse
    </table>
</body>
</html>

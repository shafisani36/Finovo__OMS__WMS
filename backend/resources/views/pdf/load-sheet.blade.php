<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Load sheet</title>
    <style>
        @page { margin: 18mm 14mm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #000; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 2px 0; }
        .muted { color: #333; font-size: 9px; }
        .carrier { page-break-after: always; }
        .carrier:last-child { page-break-after: auto; }
        h2 { font-size: 14px; margin: 16px 0 6px 0; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th, table.list td { border: 1px solid #000; padding: 4px 5px; text-align: left; }
        table.list th { background: #eee; font-size: 9px; text-transform: uppercase; }
        .number { text-align: right !important; }
        .totals td { font-weight: bold; }
        .signatures { width: 100%; margin-top: 36px; }
        .signatures td { width: 50%; padding-top: 28px; }
        .line { border-top: 1px solid #000; width: 85%; padding-top: 3px; }
    </style>
</head>
<body>
@foreach ($groups as $carrier => $rows)
    <div class="carrier">
        <h1>Load Sheet</h1>
        <div class="muted">{{ config('app.name') }} · printed {{ $printedAt->format('d M Y, H:i') }}</div>

        <h2>{{ $carrier }} · {{ $rows->count() }} parcel(s)</h2>
        <table class="list">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Tracking ID</th>
                    <th>Order</th>
                    <th>Consignee</th>
                    <th>City</th>
                    <th class="number">Pieces</th>
                    <th class="number">Weight (kg)</th>
                    <th class="number">COD</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $index => $row)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $row['shipment']->tracking_number }}</td>
                        <td>{{ $row['order']?->order_number }}</td>
                        <td>{{ $row['order']?->customer_name }}</td>
                        <td>{{ $row['city'] }}</td>
                        <td class="number">{{ $row['shipment']->pieces }}</td>
                        <td class="number">{{ $row['shipment']->weight_kg !== null ? number_format((float) $row['shipment']->weight_kg, 2) : '' }}</td>
                        <td class="number">{{ number_format($row['cod'], 0) }}</td>
                    </tr>
                @endforeach
                <tr class="totals">
                    <td colspan="5">Total</td>
                    <td class="number">{{ $rows->sum(fn ($row) => $row['shipment']->pieces) }}</td>
                    <td class="number"></td>
                    <td class="number">{{ number_format($rows->sum('cod'), 0) }}</td>
                </tr>
            </tbody>
        </table>

        <table class="signatures">
            <tr>
                <td><div class="line">Handed over by (warehouse)</div></td>
                <td><div class="line">Received by ({{ $carrier }} rider) · date and time</div></td>
            </tr>
        </table>
    </div>
@endforeach
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shipping labels</title>
    <style>
        @page { margin: 10px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #000; margin: 0; }
        .label { page-break-after: always; border: 1.5pt solid #000; padding: 8pt; height: 390pt; }
        .label:last-child { page-break-after: auto; }
        .row { width: 100%; border-collapse: collapse; }
        .row td { vertical-align: top; padding: 0; }
        .carrier { font-size: 15px; font-weight: bold; }
        .muted { color: #333; font-size: 9px; }
        .section { border-top: 1px solid #000; margin-top: 6px; padding-top: 6px; }
        .heading { font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 2px; }
        .name { font-size: 13px; font-weight: bold; }
        .city { font-size: 14px; font-weight: bold; text-transform: uppercase; }
        .cod { font-size: 16px; font-weight: bold; text-align: right; }
        .barcode { text-align: center; margin-top: 4px; }
        .tracking { font-size: 12px; font-weight: bold; text-align: center; letter-spacing: 1px; }
        .items { font-size: 9px; }
    </style>
</head>
<body>
@foreach ($labels as $label)
    @php($shipment = $label['shipment'])
    @php($order = $label['order'])
    @php($address = $label['address'])
    <div class="label">
        <table class="row">
            <tr>
                <td>
                    <div class="carrier">{{ $shipment->carrier }}</div>
                    <div class="muted">Shipped {{ $shipment->shipped_at?->format('d M Y') }}</div>
                </td>
                <td class="cod">
                    @if ($label['cod'] > 0)
                        COD {{ $order?->currency ?? 'PKR' }} {{ number_format($label['cod'], 0) }}
                    @else
                        PAID
                    @endif
                </td>
            </tr>
        </table>

        <div class="barcode"><img src="{{ $label['barcode'] }}" width="250" height="56" alt=""></div>
        <div class="tracking">{{ $shipment->tracking_number }}</div>

        <div class="section">
            <div class="heading">Deliver to</div>
            <div class="name">{{ $order?->customer_name ?: 'Customer' }}</div>
            <div>{{ implode(', ', array_filter([$address['address1'] ?? null, $address['address2'] ?? null])) }}</div>
            <div class="city">{{ implode(' ', array_filter([$address['city'] ?? null, $address['zip'] ?? null])) }}</div>
            @if ($order?->customer_phone || ($address['phone'] ?? null))
                <div>Phone: {{ $order?->customer_phone ?: $address['phone'] }}</div>
            @endif
        </div>

        <div class="section">
            <table class="row">
                <tr>
                    <td>
                        <div class="heading">From</div>
                        <div>{{ $order?->store?->name ?? config('app.name') }}</div>
                        <div class="muted">{{ $shipment->warehouse?->name }}</div>
                    </td>
                    <td style="text-align: right;">
                        <div class="heading">Order</div>
                        <div><strong>{{ $order?->order_number }}</strong></div>
                        <div class="muted">{{ $shipment->pieces }} piece(s){{ $shipment->weight_kg ? ', '.rtrim(rtrim(number_format((float) $shipment->weight_kg, 2), '0'), '.').' kg' : '' }}{{ $shipment->length_cm ? ', '.(float) $shipment->length_cm.' x '.(float) $shipment->width_cm.' x '.(float) $shipment->height_cm.' cm' : '' }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="section items">
            <div class="heading">Contents</div>
            {{ $order?->items->where('status', 'shipped')->map(fn ($item) => $item->quantity.' x '.$item->product_name)->implode(', ') }}
        </div>
    </div>
@endforeach
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Requisition - {{ $requisition->pr_number }}</title>
    <style>
        @page {
            margin: 25mm 20mm 25mm 20mm;
            size: a4 portrait;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.45;
            color: #1e293b;
            margin: 0;
            padding: 0;
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #0284c7;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .brand-title {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
        }
        .brand-sub {
            font-size: 10px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 2px;
        }
        .doc-title {
            font-size: 18px;
            font-weight: 700;
            color: #0284c7;
            text-align: right;
            text-transform: uppercase;
        }
        .doc-meta {
            font-size: 11px;
            color: #475569;
            text-align: right;
            margin-top: 4px;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            margin-top: 4px;
        }
        .badge-draft { background: #f1f5f9; color: #475569; }
        .badge-submitted { background: #fef3c7; color: #b45309; }
        .badge-approved { background: #dcfce7; color: #15803d; }
        .badge-ordered { background: #e0f2fe; color: #0369a1; }
        .badge-rejected { background: #fee2e2; color: #b91c1c; }

        .info-grid {
            width: 100%;
            margin-bottom: 18px;
            border-collapse: collapse;
        }
        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 12px;
            vertical-align: top;
            width: 48%;
        }
        .info-card h4 {
            margin: 0 0 8px 0;
            font-size: 11px;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
        }
        .info-row {
            margin-bottom: 4px;
        }
        .info-label {
            font-weight: 600;
            color: #475569;
            width: 110px;
            display: inline-block;
        }
        .info-val {
            color: #0f172a;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .items-table th {
            background: #0f172a;
            color: #ffffff;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 8px 10px;
            text-align: left;
        }
        .items-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 11px;
        }
        .items-table tr:nth-child(even) td {
            background: #fcfdfe;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .totals-table {
            width: 260px;
            float: right;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .totals-table td {
            padding: 5px 8px;
            font-size: 11px;
        }
        .totals-table .total-row {
            font-size: 13px;
            font-weight: 700;
            border-top: 2px solid #0f172a;
            color: #0f172a;
        }

        .clear { clear: both; }

        .section-box {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 16px;
        }
        .section-title {
            font-size: 11px;
            font-weight: 700;
            color: #334155;
            margin: 0 0 6px 0;
            text-transform: uppercase;
        }

        .signatures-grid {
            width: 100%;
            margin-top: 30px;
            border-collapse: collapse;
        }
        .sig-block {
            width: 48%;
            border: 1px dashed #cbd5e1;
            padding: 12px;
            border-radius: 4px;
            vertical-align: top;
        }
        .sig-line {
            margin-top: 36px;
            border-top: 1px solid #94a3b8;
            padding-top: 4px;
            font-size: 10px;
            color: #64748b;
        }

        .footer {
            position: fixed;
            bottom: -15mm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 6px;
        }
    </style>
</head>
<body>
    <div class="footer">
        Finovo Enterprise OMS + WMS &bull; Document Reference: {{ $requisition->pr_number }} &bull; Generated: {{ now()->format('Y-m-d H:i:s T') }} &bull; Confidential Internal Business Document
    </div>

    <!-- Header -->
    <table class="header-table">
        <tr>
            <td style="vertical-align: middle;">
                <div class="brand-title">{{ $requisition->organization->name ?? 'FINOVO ENTERPRISE' }}</div>
                <div class="brand-sub">Order &amp; Warehouse Management Suite</div>
            </td>
            <td style="vertical-align: middle;">
                <div class="doc-title">Purchase Requisition</div>
                <div class="doc-meta">
                    <strong>PR #:</strong> {{ $requisition->pr_number }}<br>
                    <strong>Date:</strong> {{ $requisition->created_at->format('M d, Y') }}<br>
                    <span class="status-badge badge-{{ str_replace('_', '-', $requisition->status) }}">
                        {{ strtoupper(str_replace('_', ' ', $requisition->status)) }}
                    </span>
                </div>
            </td>
        </tr>
    </table>

    <!-- Requester & Destination Info -->
    <table class="info-grid">
        <tr>
            <td class="info-card">
                <h4>Requisition Details</h4>
                <div class="info-row"><span class="info-label">Requester:</span> <span class="info-val">{{ $requisition->requester->name ?? 'N/A' }}</span></div>
                <div class="info-row"><span class="info-label">Email:</span> <span class="info-val">{{ $requisition->requester->email ?? 'N/A' }}</span></div>
                <div class="info-row"><span class="info-label">Department:</span> <span class="info-val">{{ $requisition->department ?? 'General' }}</span></div>
                <div class="info-row"><span class="info-label">Priority:</span> <span class="info-val" style="font-weight: 700; color: {{ $requisition->priority === 'urgent' ? '#dc2626' : '#2563eb' }};">{{ strtoupper($requisition->priority) }}</span></div>
                <div class="info-row"><span class="info-label">Required By:</span> <span class="info-val">{{ $requisition->required_by_date ? $requisition->required_by_date->format('M d, Y') : 'Immediate / Flexible' }}</span></div>
            </td>
            <td style="width: 4%;"></td>
            <td class="info-card">
                <h4>Destination Warehouse</h4>
                <div class="info-row"><span class="info-label">Warehouse:</span> <span class="info-val">{{ $requisition->warehouse->name ?? 'Default WH' }} ({{ $requisition->warehouse->code ?? 'WH' }})</span></div>
                <div class="info-row"><span class="info-label">Address:</span> <span class="info-val">{{ $requisition->warehouse->address ?? 'Main Distribution Facility' }}</span></div>
                <div class="info-row"><span class="info-label">Delivery Dock:</span> <span class="info-val">{{ $requisition->deliveryLocation->code ?? 'Receiving Staging / Inbound Dock' }}</span></div>
                <div class="info-row"><span class="info-label">Currency:</span> <span class="info-val">{{ $requisition->currency ?? 'USD' }}</span></div>
            </td>
        </tr>
    </table>

    @if($requisition->purpose || $requisition->justification)
    <div class="section-box">
        @if($requisition->purpose)
            <div style="margin-bottom: 4px;"><strong>Purpose:</strong> {{ $requisition->purpose }}</div>
        @endif
        @if($requisition->justification)
            <div><strong>Business Justification:</strong> {{ $requisition->justification }}</div>
        @endif
    </div>
    @endif

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 5%;">#</th>
                <th style="width: 25%;">SKU &amp; Code</th>
                <th style="width: 35%;">Item Description</th>
                <th class="text-center" style="width: 10%;">Requested</th>
                <th class="text-center" style="width: 10%;">Approved</th>
                <th class="text-right" style="width: 15%;">Est. Price</th>
                <th class="text-right" style="width: 15%;">Est. Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($requisition->items as $idx => $item)
            <tr>
                <td class="text-center">{{ $idx + 1 }}</td>
                <td><strong>{{ $item->sku }}</strong></td>
                <td>
                    <div>{{ $item->product_name }}</div>
                    @if($item->notes)
                        <div style="font-size: 9px; color: #64748b;">Note: {{ $item->notes }}</div>
                    @endif
                </td>
                <td class="text-center"><strong>{{ number_format($item->requested_quantity) }}</strong> {{ $item->uom }}</td>
                <td class="text-center">{{ number_format($item->approved_quantity) }} {{ $item->uom }}</td>
                <td class="text-right">{{ $requisition->currency }} {{ number_format($item->estimated_unit_price, 2) }}</td>
                <td class="text-right"><strong>{{ $requisition->currency }} {{ number_format($item->estimated_subtotal, 2) }}</strong></td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Totals -->
    <table class="totals-table">
        <tr>
            <td style="color: #64748b;">Estimated Subtotal:</td>
            <td class="text-right"><strong>{{ $requisition->currency }} {{ number_format($requisition->subtotal, 2) }}</strong></td>
        </tr>
        <tr>
            <td style="color: #64748b;">Estimated Taxes:</td>
            <td class="text-right">{{ $requisition->currency }} {{ number_format($requisition->tax_amount, 2) }}</td>
        </tr>
        <tr class="total-row">
            <td>Estimated Total:</td>
            <td class="text-right">{{ $requisition->currency }} {{ number_format($requisition->total_amount, 2) }}</td>
        </tr>
    </table>

    <div class="clear"></div>

    <!-- Approvals & Signatures Block -->
    <table class="signatures-grid">
        <tr>
            <td class="sig-block">
                <div style="font-weight: 700; color: #334155;">1. Requester Acknowledgment</div>
                <div style="margin-top: 6px; font-size: 10px; color: #64748b;">
                    Requested by: <strong>{{ $requisition->requester->name ?? 'Requester' }}</strong><br>
                    Submitted: {{ $requisition->submitted_at ? $requisition->submitted_at->format('M d, Y H:i') : 'Draft' }}
                </div>
                <div class="sig-line">Authorized Requester Signature</div>
            </td>
            <td style="width: 4%;"></td>
            <td class="sig-block">
                <div style="font-weight: 700; color: #334155;">2. Management Approval &amp; Authorization</div>
                <div style="margin-top: 6px; font-size: 10px; color: #64748b;">
                    Approver: <strong>{{ $requisition->approver->name ?? 'Pending Authorization' }}</strong><br>
                    Decision Date: {{ $requisition->approved_at ? $requisition->approved_at->format('M d, Y H:i') : ($requisition->rejected_at ? 'Rejected on ' . $requisition->rejected_at->format('M d, Y') : 'Pending') }}
                </div>
                <div class="sig-line">Authorized Management Signature</div>
            </td>
        </tr>
    </table>
</body>
</html>

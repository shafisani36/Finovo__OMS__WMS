<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Goods Received Note - {{ $grn->grn_number }}</title>
    <style>
        @page {
            margin: 20mm 15mm 22mm 15mm;
            size: a4 landscape;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #1e293b;
            margin: 0;
            padding: 0;
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #059669;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .brand-title {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
        }
        .brand-sub {
            font-size: 9px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 2px;
        }
        .doc-title {
            font-size: 17px;
            font-weight: 700;
            color: #059669;
            text-align: right;
            text-transform: uppercase;
        }
        .doc-meta {
            font-size: 10px;
            color: #475569;
            text-align: right;
            margin-top: 4px;
        }
        .status-badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            margin-top: 3px;
        }
        .badge-draft { background: #f1f5f9; color: #475569; }
        .badge-inspected { background: #fef3c7; color: #b45309; }
        .badge-posted { background: #dcfce7; color: #15803d; }
        .badge-cancelled { background: #fee2e2; color: #b91c1c; }

        .info-grid {
            width: 100%;
            margin-bottom: 14px;
            border-collapse: collapse;
        }
        .info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            padding: 8px 10px;
            vertical-align: top;
            width: 32%;
        }
        .info-card h4 {
            margin: 0 0 6px 0;
            font-size: 10px;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 3px;
        }
        .info-row {
            margin-bottom: 3px;
        }
        .info-label {
            font-weight: 600;
            color: #475569;
            width: 100px;
            display: inline-block;
        }
        .info-val {
            color: #0f172a;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .items-table th {
            background: #0f172a;
            color: #ffffff;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 8px;
            text-align: left;
        }
        .items-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 10px;
        }
        .items-table tr:nth-child(even) td {
            background: #fcfdfe;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .qty-good { color: #15803d; font-weight: 700; }
        .qty-damaged { color: #dc2626; font-weight: 700; }
        .qty-quarantine { color: #b45309; font-weight: 700; }
        .qty-rejected { color: #475569; font-weight: 600; }

        .summary-banner {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 8px 12px;
            margin-bottom: 14px;
        }

        .signatures-grid {
            width: 100%;
            margin-top: 18px;
            border-collapse: collapse;
        }
        .sig-block {
            width: 31%;
            border: 1px dashed #cbd5e1;
            padding: 10px;
            border-radius: 4px;
            vertical-align: top;
        }
        .sig-line {
            margin-top: 30px;
            border-top: 1px solid #94a3b8;
            padding-top: 4px;
            font-size: 9px;
            color: #64748b;
        }

        .footer {
            position: fixed;
            bottom: -15mm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 4px;
        }
    </style>
</head>
<body>
    <div class="footer">
        Finovo Enterprise WMS &bull; Goods Received Note: {{ $grn->grn_number }} &bull; Posted to Authoritative Stock Ledger &bull; Generated: {{ now()->format('Y-m-d H:i:s T') }}
    </div>

    <!-- Header -->
    <table class="header-table">
        <tr>
            <td style="vertical-align: middle;">
                <div class="brand-title">{{ $grn->organization->name ?? 'FINOVO ENTERPRISE' }}</div>
                <div class="brand-sub">Warehouse Inbound Dock &amp; Receipt Control</div>
            </td>
            <td style="vertical-align: middle;">
                <div class="doc-title">Goods Received Note (GRN)</div>
                <div class="doc-meta">
                    <strong>GRN #:</strong> {{ $grn->grn_number }}<br>
                    <strong>Receipt Date:</strong> {{ $grn->received_at ? $grn->received_at->format('M d, Y H:i') : now()->format('M d, Y') }}<br>
                    <span class="status-badge badge-{{ str_replace('_', '-', $grn->status) }}">
                        {{ strtoupper(str_replace('_', ' ', $grn->status)) }}
                    </span>
                </div>
            </td>
        </tr>
    </table>

    <!-- Info Grid -->
    <table class="info-grid">
        <tr>
            <td class="info-card">
                <h4>PO &amp; Supplier</h4>
                <div class="info-row"><span class="info-label">PO Reference:</span> <span class="info-val"><strong>{{ $grn->purchaseOrder->po_number ?? 'PO' }}</strong></span></div>
                <div class="info-row"><span class="info-label">Supplier:</span> <span class="info-val">{{ $grn->supplier_name ?? $grn->purchaseOrder->supplier_name }}</span></div>
                <div class="info-row"><span class="info-label">Delivery Note:</span> <span class="info-val">{{ $grn->supplier_delivery_note ?? 'N/A' }}</span></div>
            </td>
            <td style="width: 2%;"></td>
            <td class="info-card">
                <h4>Logistics &amp; Transport</h4>
                <div class="info-row"><span class="info-label">Carrier:</span> <span class="info-val">{{ $grn->carrier ?? 'Standard Freight' }}</span></div>
                <div class="info-row"><span class="info-label">Tracking / Vehicle:</span> <span class="info-val">{{ $grn->tracking_or_vehicle_number ?? 'N/A' }}</span></div>
                <div class="info-row"><span class="info-label">Receiving Dock:</span> <span class="info-val">{{ $grn->receivingLocation->code ?? 'Dock Inbound' }}</span></div>
            </td>
            <td style="width: 2%;"></td>
            <td class="info-card">
                <h4>Warehouse &amp; Personnel</h4>
                <div class="info-row"><span class="info-label">Warehouse:</span> <span class="info-val">{{ $grn->warehouse->name ?? 'Main WH' }} ({{ $grn->warehouse->code ?? 'WH' }})</span></div>
                <div class="info-row"><span class="info-label">Received By:</span> <span class="info-val">{{ $grn->receivedBy->name ?? 'Dock Staff' }}</span></div>
                <div class="info-row"><span class="info-label">Inspected By:</span> <span class="info-val">{{ $grn->inspectedBy->name ?? ($grn->postedBy->name ?? 'Pending') }}</span></div>
            </td>
        </tr>
    </table>

    <!-- Line Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 3%;">#</th>
                <th style="width: 14%;">SKU</th>
                <th style="width: 19%;">Item Description</th>
                <th class="text-center" style="width: 6%;">Ordered</th>
                <th class="text-center" style="width: 6%;">Prior</th>
                <th class="text-center" style="width: 7%;">Received</th>
                <th class="text-center" style="width: 7%;">Accepted</th>
                <th class="text-center" style="width: 6%;">Damaged</th>
                <th class="text-center" style="width: 6%;">Quarantine</th>
                <th class="text-center" style="width: 6%;">Rejected</th>
                <th style="width: 10%;">Lot / Batch</th>
                <th style="width: 10%;">Expiry Date</th>
            </tr>
        </thead>
        <tbody>
            @foreach($grn->items as $idx => $item)
            <tr>
                <td class="text-center">{{ $idx + 1 }}</td>
                <td><strong>{{ $item->sku }}</strong></td>
                <td>
                    <div>{{ $item->product_name }}</div>
                    @if($item->discrepancy_reason)
                        <div style="font-size: 8px; color: #dc2626;">Discrepancy: {{ $item->discrepancy_reason }}</div>
                    @endif
                </td>
                <td class="text-center">{{ number_format($item->ordered_quantity) }}</td>
                <td class="text-center">{{ number_format($item->previously_received_quantity) }}</td>
                <td class="text-center"><strong>{{ number_format($item->received_quantity) }}</strong></td>
                <td class="text-center qty-good">{{ number_format($item->accepted_quantity) }}</td>
                <td class="text-center qty-damaged">{{ number_format($item->damaged_quantity) }}</td>
                <td class="text-center qty-quarantine">{{ number_format($item->quarantined_quantity) }}</td>
                <td class="text-center qty-rejected">{{ number_format($item->rejected_quantity) }}</td>
                <td>{{ $item->lot_number ?? '-' }}</td>
                <td>{{ $item->expiry_date ? $item->expiry_date->format('Y-m-d') : '-' }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr style="background: #f1f5f9; font-weight: 700;">
                <td colspan="5" class="text-right" style="padding: 6px 8px;">CUSTODY TOTALS:</td>
                <td class="text-center">{{ number_format($grn->total_received_units) }}</td>
                <td class="text-center qty-good">{{ number_format($grn->total_accepted_units) }}</td>
                <td class="text-center qty-damaged">{{ number_format($grn->total_damaged_units) }}</td>
                <td class="text-center qty-quarantine">{{ number_format($grn->total_quarantined_units) }}</td>
                <td class="text-center qty-rejected">{{ number_format($grn->total_rejected_units) }}</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>

    @if($grn->notes || $grn->discrepancy_summary)
    <div class="summary-banner">
        @if($grn->notes)
            <div><strong>General Notes:</strong> {{ $grn->notes }}</div>
        @endif
        @if($grn->discrepancy_summary)
            <div style="margin-top: 3px; color: #b91c1c;"><strong>Variance &amp; Discrepancy Reconciliation:</strong> {{ $grn->discrepancy_summary }}</div>
        @endif
    </div>
    @endif

    <!-- Signatures -->
    <table class="signatures-grid">
        <tr>
            <td class="sig-block">
                <div style="font-weight: 700; color: #334155;">1. Receiving Clerk (Physical Count)</div>
                <div style="margin-top: 4px; font-size: 9px; color: #64748b;">
                    Clerk: <strong>{{ $grn->receivedBy->name ?? 'Staff' }}</strong><br>
                    Received: {{ $grn->received_at ? $grn->received_at->format('M d, Y H:i') : now()->format('M d, Y') }}
                </div>
                <div class="sig-line">Physical Receipt Signature</div>
            </td>
            <td style="width: 3%;"></td>
            <td class="sig-block">
                <div style="font-weight: 700; color: #334155;">2. Quality Inspector (Condition QC)</div>
                <div style="margin-top: 4px; font-size: 9px; color: #64748b;">
                    Inspector: <strong>{{ $grn->inspectedBy->name ?? 'QC Officer' }}</strong><br>
                    Inspection: {{ $grn->inspected_at ? $grn->inspected_at->format('M d, Y H:i') : 'Completed upon receipt' }}
                </div>
                <div class="sig-line">Quality Assurance Signature</div>
            </td>
            <td style="width: 3%;"></td>
            <td class="sig-block">
                <div style="font-weight: 700; color: #334155;">3. Inventory Posting Authority</div>
                <div style="margin-top: 4px; font-size: 9px; color: #64748b;">
                    Authorizer: <strong>{{ $grn->postedBy->name ?? ($grn->receivedBy->name ?? 'Manager') }}</strong><br>
                    Posted: {{ $grn->posted_at ? $grn->posted_at->format('M d, Y H:i') : 'Pending Ledger Post' }}
                </div>
                <div class="sig-line">Authoritative Stock Ledger Posting</div>
            </td>
        </tr>
    </table>
</body>
</html>

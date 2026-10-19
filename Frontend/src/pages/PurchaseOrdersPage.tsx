import React from 'react';
import { FileSpreadsheet, Clock } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';

export const PurchaseOrdersPage: React.FC = () => {
  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Purchase Orders</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Purchase Orders</h1>
            <span className="page-count-pill">0</span>
          </div>
          <p className="page-subtitle">Track supplier procurements, inbound shipments, and warehouse receiving.</p>
        </div>

        <InfoCalloutCard
          title="Procurement Invariants"
          description="Incoming stock is never marked available until warehouse receipt and inspection are committed."
          icon={<FileSpreadsheet size={18} />}
        />
      </div>

      <div className="kpi-grid">
        <MetricCard title="Open Orders" value={0} icon={<FileSpreadsheet size={20} />} pillText="Active" />
        <MetricCard title="Expected Deliveries" value={0} icon={<Clock size={20} />} pillText="In Transit" pillVariant="blue" />
      </div>

      <div className="content-card" style={{ padding: 48, textAlign: 'center' }}>
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12 }}>
          <div style={{ width: 56, height: 56, borderRadius: 16, backgroundColor: '#f1f5f9', color: '#64748b', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <FileSpreadsheet size={28} />
          </div>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#0f172a' }}>No Purchase Orders</h3>
          <p style={{ margin: 0, fontSize: 13, color: '#64748b', maxWidth: 400 }}>
            Create purchase orders with suppliers to manage inbound receipts directly into your warehouse locations.
          </p>
        </div>
      </div>
    </div>
  );
};

export default PurchaseOrdersPage;

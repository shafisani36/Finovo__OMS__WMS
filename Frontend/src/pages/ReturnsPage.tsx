import React from 'react';
import { RotateCcw, AlertTriangle } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';

export const ReturnsPage: React.FC = () => {
  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Returns</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Returns & Restocking</h1>
            <span className="page-count-pill">0</span>
          </div>
          <p className="page-subtitle">Inspect returned parcels, determine inventory disposition, and approve restocking.</p>
        </div>

        <InfoCalloutCard
          title="Safe Restocking Engine"
          description="Returned goods are inspected and held in quarantine before becoming sellable inventory."
          icon={<RotateCcw size={18} />}
        />
      </div>

      <div className="kpi-grid">
        <MetricCard title="Requested Returns" value={0} icon={<RotateCcw size={20} />} pillText="Pending RMA" />
        <MetricCard title="Under Inspection" value={0} icon={<AlertTriangle size={20} />} pillText="Quarantine" pillVariant="amber" />
        <MetricCard title="Restocked Units" value={0} icon={<RotateCcw size={20} />} pillText="Completed" pillVariant="green" />
      </div>

      <div className="content-card" style={{ padding: 48, textAlign: 'center' }}>
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12 }}>
          <div style={{ width: 56, height: 56, borderRadius: 16, backgroundColor: '#f1f5f9', color: '#64748b', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <RotateCcw size={28} />
          </div>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#0f172a' }}>No Return Requests</h3>
          <p style={{ margin: 0, fontSize: 13, color: '#64748b', maxWidth: 400 }}>
            Customer RMA requests and return shipments will be cataloged here with inspection results and disposition decisions.
          </p>
        </div>
      </div>
    </div>
  );
};

export default ReturnsPage;

import React from 'react';
import { Truck, CheckCircle2 } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';

export const ShipmentsPage: React.FC = () => {
  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Shipments</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Shipments & Carriers</h1>
            <span className="page-count-pill">0</span>
          </div>
          <p className="page-subtitle">Generate carrier labels, track dispatched parcels, and monitor delivery status.</p>
        </div>

        <InfoCalloutCard
          title="Carrier Dispatch"
          description="Integrated tracking with DHL, FedEx, UPS, and custom regional carriers."
          icon={<Truck size={18} />}
        />
      </div>

      <div className="kpi-grid">
        <MetricCard title="Dispatched Today" value={0} icon={<Truck size={20} />} pillText="On schedule" />
        <MetricCard title="In Transit" value={0} icon={<Truck size={20} />} pillText="Live Tracking" pillVariant="blue" />
        <MetricCard title="Delivered" value={0} icon={<CheckCircle2 size={20} />} pillText="Success" pillVariant="green" />
      </div>

      <div className="content-card" style={{ padding: 48, textAlign: 'center' }}>
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12 }}>
          <div style={{ width: 56, height: 56, borderRadius: 16, backgroundColor: '#eff6ff', color: '#1e56ff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <Truck size={28} />
          </div>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#0f172a' }}>No Outbound Shipments Yet</h3>
          <p style={{ margin: 0, fontSize: 13, color: '#64748b', maxWidth: 400 }}>
            Once orders are picked and packed from the Picking & Packing module, dispatch labels and tracking IDs will appear here.
          </p>
        </div>
      </div>
    </div>
  );
};

export default ShipmentsPage;

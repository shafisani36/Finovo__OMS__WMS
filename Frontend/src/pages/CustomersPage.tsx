import React from 'react';
import { Users, UserPlus } from 'lucide-react';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';

export const CustomersPage: React.FC = () => {
  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Customers</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <div className="page-title-badge-wrap">
            <h1 className="page-title">Customers</h1>
            <span className="page-count-pill">0</span>
          </div>
          <p className="page-subtitle">Unified customer identities across all connected sales channels.</p>
        </div>

        <InfoCalloutCard
          title="Customer Identities"
          description="Automatic deduplication across Shopify, WooCommerce, and custom web stores."
          icon={<Users size={18} />}
        />
      </div>

      <div className="kpi-grid">
        <MetricCard title="Total Customers" value={0} icon={<Users size={20} />} pillText="Unified" />
        <MetricCard title="Repeat Buyers" value={0} icon={<UserPlus size={20} />} pillText="Loyalty" pillVariant="green" />
      </div>

      <div className="content-card" style={{ padding: 48, textAlign: 'center' }}>
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12 }}>
          <div style={{ width: 56, height: 56, borderRadius: 16, backgroundColor: '#f1f5f9', color: '#64748b', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <Users size={28} />
          </div>
          <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#0f172a' }}>No Customers Recorded</h3>
          <p style={{ margin: 0, fontSize: 13, color: '#64748b', maxWidth: 400 }}>
            Customer profiles are compiled as orders are imported through your connected store integrations.
          </p>
        </div>
      </div>
    </div>
  );
};

export default CustomersPage;

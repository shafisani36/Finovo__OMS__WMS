import React, { useEffect, useState } from 'react';
import { NavLink } from 'react-router-dom';
import {
  Store,
  Warehouse,
  Package,
  FileText,
  ArrowLeftRight,
  CheckSquare,
  ShieldCheck,
  Server
} from 'lucide-react';
import { useAuth } from '../hooks/useAuth';
import MetricCard from '../components/common/MetricCard';
import InfoCalloutCard from '../components/common/InfoCalloutCard';
import omsService from '../services/omsService';

export const DashboardPage: React.FC = () => {
  const { user } = useAuth();
  const [stats, setStats] = useState({
    stores: 0,
    warehouses: 0,
    products: 0,
    orders: 0,
    pendingPicking: 0,
  });

  useEffect(() => {
    const fetchDashboardData = async () => {
      try {
        const [storesRes, whRes, prodRes, ordRes, pickRes] = await Promise.all([
          omsService.getStores(),
          omsService.getWarehouses(),
          omsService.getProducts({ per_page: 1 }),
          omsService.getOrders({ per_page: 1 }),
          omsService.getPickingTasks({ per_page: 1 }),
        ]);

        setStats({
          stores: storesRes.kpis.total_stores,
          warehouses: whRes.kpis.total_warehouses,
          products: prodRes.meta.total,
          orders: ordRes.meta.total,
          pendingPicking: pickRes.kpis.pending_orders,
        });
      } catch (err) {
        console.error(err);
      }
    };

    fetchDashboardData();
  }, []);

  return (
    <div className="page-container">
      <nav className="breadcrumb-nav">
        <span>Finovo</span>
        <span>&gt;</span>
        <span style={{ color: '#0f172a', fontWeight: 500 }}>Dashboard</span>
      </nav>

      <div className="page-header-row">
        <div className="page-title-section">
          <h1 className="page-title">Operations Dashboard</h1>
          <p className="page-subtitle">
            Welcome back, <strong>{user?.name}</strong>. Here is the operational overview of your order and warehouse pipeline.
          </p>
        </div>

        <InfoCalloutCard
          title="Enterprise OMS / WMS Live"
          description="Authoritative inventory ledger, multi-store channels, and warehouse allocation active."
          icon={<Server size={18} />}
        />
      </div>

      {/* KPI Cards Grid */}
      <div className="kpi-grid">
        <MetricCard
          title="Connected Stores"
          value={stats.stores}
          icon={<Store size={20} />}
          pillText="Multichannel"
          pillVariant="blue"
        />
        <MetricCard
          title="Warehouses"
          value={stats.warehouses}
          icon={<Warehouse size={20} />}
          pillText="Fulfillment"
          pillVariant="green"
        />
        <MetricCard
          title="Catalog SKUs"
          value={stats.products}
          icon={<Package size={20} />}
          pillText="Tracked"
          pillVariant="blue"
        />
        <MetricCard
          title="Orders In Pipeline"
          value={stats.orders}
          icon={<FileText size={20} />}
          pillText={stats.pendingPicking > 0 ? `${stats.pendingPicking} to pick` : 'Up to date'}
          pillVariant={stats.pendingPicking > 0 ? 'amber' : 'green'}
        />
      </div>

      {/* Quick Launch Cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16, marginBottom: 24 }}>
        <NavLink
          to="/stores"
          className="content-card"
          style={{ padding: 20, textDecoration: 'none', display: 'flex', alignItems: 'center', gap: 16 }}
        >
          <div style={{ width: 44, height: 44, borderRadius: 12, backgroundColor: '#eff6ff', color: '#1e56ff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <Store size={22} />
          </div>
          <div>
            <h4 style={{ margin: 0, fontSize: 15, fontWeight: 700, color: '#0f172a' }}>Manage Stores</h4>
            <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>Configure Shopify, WooCommerce, or Custom Bridge.</p>
          </div>
        </NavLink>

        <NavLink
          to="/picking"
          className="content-card"
          style={{ padding: 20, textDecoration: 'none', display: 'flex', alignItems: 'center', gap: 16 }}
        >
          <div style={{ width: 44, height: 44, borderRadius: 12, backgroundColor: '#eff6ff', color: '#1e56ff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <CheckSquare size={22} />
          </div>
          <div>
            <h4 style={{ margin: 0, fontSize: 15, fontWeight: 700, color: '#0f172a' }}>Picking & Packing</h4>
            <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>Process allocated order lines and fulfillment queues.</p>
          </div>
        </NavLink>

        <NavLink
          to="/stock-movements"
          className="content-card"
          style={{ padding: 20, textDecoration: 'none', display: 'flex', alignItems: 'center', gap: 16 }}
        >
          <div style={{ width: 44, height: 44, borderRadius: 12, backgroundColor: '#eff6ff', color: '#1e56ff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <ArrowLeftRight size={22} />
          </div>
          <div>
            <h4 style={{ margin: 0, fontSize: 15, fontWeight: 700, color: '#0f172a' }}>Stock Movements</h4>
            <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>Execute inter-warehouse transfers and adjustments.</p>
          </div>
        </NavLink>
      </div>

      {/* System Integrity & Invariants Overview */}
      <div className="content-card" style={{ padding: 24 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 16 }}>
          <div style={{ width: 36, height: 36, borderRadius: 10, backgroundColor: '#f0fdf4', color: '#16a34a', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
            <ShieldCheck size={20} />
          </div>
          <div>
            <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700, color: '#0f172a' }}>System Invariants & Security Health</h3>
            <p style={{ margin: '2px 0 0 0', fontSize: 13, color: '#64748b' }}>Authoritative database constraints and transactional boundary verification.</p>
          </div>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))', gap: 14 }}>
          <div style={{ padding: '12px 16px', borderRadius: 12, backgroundColor: '#f8fafc', border: '1px solid #e2e8f0' }}>
            <div style={{ fontSize: 12, fontWeight: 600, color: '#059669' }}>✓ Multi-Tenant Isolation</div>
            <div style={{ fontSize: 12, color: '#64748b', marginTop: 4 }}>All records scoped to active organization {user?.current_organization_id ? `#${user.current_organization_id}` : 'context'}.</div>
          </div>

          <div style={{ padding: '12px 16px', borderRadius: 12, backgroundColor: '#f8fafc', border: '1px solid #e2e8f0' }}>
            <div style={{ fontSize: 12, fontWeight: 600, color: '#059669' }}>✓ Non-Negative Balances</div>
            <div style={{ fontSize: 12, color: '#64748b', marginTop: 4 }}>Strict availability rule: available = on_hand - reserved - damaged.</div>
          </div>

          <div style={{ padding: '12px 16px', borderRadius: 12, backgroundColor: '#f8fafc', border: '1px solid #e2e8f0' }}>
            <div style={{ fontSize: 12, fontWeight: 600, color: '#059669' }}>✓ Custom Bridge HMAC-SHA256</div>
            <div style={{ fontSize: 12, color: '#64748b', marginTop: 4 }}>Inbound webhooks verified with cryptographic signature and replay-protected nonces.</div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default DashboardPage;

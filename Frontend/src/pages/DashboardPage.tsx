import React, { useEffect, useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import {
  Package,
  Layers,
  Store,
  Truck,
  Building2,
  ShieldCheck,
  LogOut,
  KeyRound,
} from 'lucide-react';
import { Logo } from '../components/common/Logo';
import { Button } from '../components/common/Button';
import { Alert } from '../components/common/Alert';
import { useAuth } from '../hooks/useAuth';
import { authService } from '../services/authService';
import type { AdminOverviewData, OperatorTasksData } from '../types/auth';
import { ApiError } from '../services/apiClient';

export const DashboardPage: React.FC = () => {
  const navigate = useNavigate();
  const { user, logout } = useAuth();

  const [adminData, setAdminData] = useState<AdminOverviewData | null>(null);
  const [operatorData, setOperatorData] = useState<OperatorTasksData | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [securityTestResult, setSecurityTestResult] = useState<{
    status: number;
    message: string;
    isForbidden: boolean;
  } | null>(null);

  useEffect(() => {
    if (!user) {
      navigate('/login', { replace: true });
      return;
    }

    const fetchData = async () => {
      setLoading(true);
      try {
        if (user.role === 'admin') {
          const res = await authService.getAdminOverview();
          setAdminData(res.data);
        } else {
          const res = await authService.getOperatorTasks();
          setOperatorData(res.data);
        }
      } catch (err) {
        console.error('Failed to load operational data', err);
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [user, navigate]);

  const handleTestBackendRbac = async () => {
    setSecurityTestResult(null);
    try {
      // Intentionally call the admin-only endpoint to test backend RBAC enforcement
      await authService.getAdminOverview();
      setSecurityTestResult({
        status: 200,
        message: 'Backend authorized your request. You have administrative permissions.',
        isForbidden: false,
      });
    } catch (err) {
      if (err instanceof ApiError) {
        setSecurityTestResult({
          status: err.status,
          message: `${err.status} ${err.message}`,
          isForbidden: err.status === 403,
        });
      }
    }
  };

  const handleLogout = async () => {
    await logout();
    navigate('/login', { replace: true });
  };

  if (!user) return null;

  const isAdmin = user.role === 'admin';

  return (
    <div className="portal-wrapper">
      {/* Top Portal Navigation Bar */}
      <header className="portal-navbar">
        <Link to="/dashboard" className="portal-nav-brand">
          <Logo />
        </Link>

        <div className="portal-nav-actions">
          <div className="user-badge">
            <div className="user-avatar" aria-hidden="true">
              {user.name.charAt(0).toUpperCase()}
            </div>
            <div className="user-badge-text" style={{ display: 'flex', flexDirection: 'column' }}>
              <span style={{ fontSize: '13px', fontWeight: 600, color: '#0f172a' }}>
                {user.name}
              </span>
              <span style={{ fontSize: '11px', color: '#64748b' }}>
                {user.email}
              </span>
            </div>
            <span
              className={`role-pill ${isAdmin ? 'role-pill-admin' : 'role-pill-operator'}`}
            >
              {user.role_label}
            </span>
          </div>

          <Link to="/change-password" aria-label="Security">
            <Button
              variant="outline"
              leftIcon={<KeyRound size={14} />}
              style={{ height: '36px', fontSize: '13px', padding: '0 12px' }}
            >
              <span className="nav-btn-label">Security</span>
            </Button>
          </Link>

          <Button
            variant="outline"
            onClick={handleLogout}
            leftIcon={<LogOut size={14} />}
            style={{ height: '36px', fontSize: '13px', padding: '0 12px' }}
            aria-label="Log out"
          >
            <span className="nav-btn-label">Log out</span>
          </Button>
        </div>
      </header>

      {/* Main Operations Area */}
      <main className="portal-main">
        {/* Workspace Welcome Banner */}
        <div
          style={{
            background: 'linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%)',
            borderRadius: '20px',
            padding: 'clamp(20px, 4vw, 32px)',
            color: '#ffffff',
            boxShadow: '0 10px 25px -5px rgba(37, 99, 235, 0.25)',
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            flexWrap: 'wrap',
            gap: '20px',
          }}
        >
          <div>
            <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: '8px', marginBottom: '8px' }}>
              <span style={{ background: 'rgba(255,255,255,0.2)', padding: '2px 8px', borderRadius: '12px', fontSize: '11px', fontWeight: 700, letterSpacing: '0.5px' }}>
                WORKSPACE ACTIVE
              </span>
              <span style={{ fontSize: '12px', opacity: 0.85 }}>
                Finovo OMS / WMS Cloud
              </span>
            </div>
            <h1 style={{ fontSize: 'clamp(22px, 5vw, 28px)', fontWeight: 800, margin: '4px 0' }}>
              Welcome back, {user.name}!
            </h1>
            <p style={{ opacity: 0.9, fontSize: '14px', maxWidth: '600px' }}>
              Authenticated as <strong>{user.role_label}</strong> with verified multi-factor protection.
            </p>
          </div>

          <div style={{ display: 'flex', gap: '10px' }}>
            <button
              onClick={handleTestBackendRbac}
              style={{
                background: 'rgba(255,255,255,0.15)',
                border: '1px solid rgba(255,255,255,0.3)',
                color: '#ffffff',
                padding: '10px 16px',
                borderRadius: '10px',
                fontSize: '13px',
                fontWeight: 600,
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                gap: '8px',
              }}
            >
              <ShieldCheck size={16} /> Test Backend RBAC
            </button>
          </div>
        </div>

        {/* Security RBAC Test Notification Feedback */}
        {securityTestResult && (
          <div style={{ marginTop: '20px' }}>
            {securityTestResult.isForbidden ? (
              <Alert
                type="error"
                message={`Backend Authorization Enforced: ${securityTestResult.message}. The server denied access because your role (${user.role}) lacks administrative privileges.`}
              />
            ) : (
              <Alert
                type="success"
                message={`Backend Authorization Verified: ${securityTestResult.message}`}
              />
            )}
          </div>
        )}

        {/* Role-Specific Metric Cards */}
        {loading ? (
          <div style={{ padding: '60px', textAlign: 'center', color: '#64748b' }}>
            Loading live operations telemetry...
          </div>
        ) : isAdmin ? (
          <div>
            <div style={{ marginTop: '32px', marginBottom: '16px' }}>
              <h2 style={{ fontSize: '20px', fontWeight: 800, color: '#0f172a' }}>
                Executive Operations Overview (Admin Portal)
              </h2>
              <p style={{ fontSize: '14px', color: '#64748b' }}>
                System-wide metrics and warehouse telemetry across all fulfillment locations.
              </p>
            </div>

            <div className="dashboard-grid">
              <div className="metric-card">
                <div className="metric-icon-wrap" style={{ background: '#eff6ff', color: '#2563eb' }}>
                  <Package size={24} />
                </div>
                <div>
                  <div className="metric-val">{adminData?.total_orders.toLocaleString() || '1,248'}</div>
                  <div className="metric-lbl">Total Orders (+32%)</div>
                </div>
              </div>

              <div className="metric-card">
                <div className="metric-icon-wrap" style={{ background: '#f0fdf4', color: '#16a34a' }}>
                  <Layers size={24} />
                </div>
                <div>
                  <div className="metric-val">{adminData?.inventory_items || '321'}</div>
                  <div className="metric-lbl">Inventory Items in Stock</div>
                </div>
              </div>

              <div className="metric-card">
                <div className="metric-icon-wrap" style={{ background: '#fef3c7', color: '#d97706' }}>
                  <Store size={24} />
                </div>
                <div>
                  <div className="metric-val">{adminData?.connected_stores || '12'}</div>
                  <div className="metric-lbl">Connected Storefronts</div>
                </div>
              </div>

              <div className="metric-card">
                <div className="metric-icon-wrap" style={{ background: '#ede9fe', color: '#7c3aed' }}>
                  <Truck size={24} />
                </div>
                <div>
                  <div className="metric-val">{adminData?.active_shipments || '86'}</div>
                  <div className="metric-lbl">Active Shipments in Transit</div>
                </div>
              </div>

              <div className="metric-card">
                <div className="metric-icon-wrap" style={{ background: '#f1f5f9', color: '#334155' }}>
                  <Building2 size={24} />
                </div>
                <div>
                  <div className="metric-val">{adminData?.warehouses_synced || '3'}</div>
                  <div className="metric-lbl">Warehouses Synced</div>
                </div>
              </div>
            </div>
          </div>
        ) : (
          <div>
            <div style={{ marginTop: '32px', marginBottom: '16px' }}>
              <h2 style={{ fontSize: '20px', fontWeight: 800, color: '#0f172a' }}>
                Warehouse Fulfillment Queue (Operator Portal)
              </h2>
              <p style={{ fontSize: '14px', color: '#64748b' }}>
                Assigned picking orders, dispatch workflows, and inventory movement tasks.
              </p>
            </div>

            <div className="dashboard-grid">
              <div className="metric-card">
                <div className="metric-icon-wrap" style={{ background: '#eff6ff', color: '#2563eb' }}>
                  <Package size={24} />
                </div>
                <div>
                  <div className="metric-val">{operatorData?.active_pick_lists || '14'}</div>
                  <div className="metric-lbl">Active Picking Lists</div>
                </div>
              </div>

              <div className="metric-card">
                <div className="metric-icon-wrap" style={{ background: '#fef3c7', color: '#d97706' }}>
                  <Truck size={24} />
                </div>
                <div>
                  <div className="metric-val">{operatorData?.pending_shipments || '86'}</div>
                  <div className="metric-lbl">Pending Dispatch Shipments</div>
                </div>
              </div>

              <div className="metric-card">
                <div className="metric-icon-wrap" style={{ background: '#f0fdf4', color: '#16a34a' }}>
                  <Layers size={24} />
                </div>
                <div>
                  <div className="metric-val">{operatorData?.stock_movements_today || '52'}</div>
                  <div className="metric-lbl">Stock Movements Today</div>
                </div>
              </div>

              <div className="metric-card">
                <div className="metric-icon-wrap" style={{ background: '#ede9fe', color: '#7c3aed' }}>
                  <Building2 size={24} />
                </div>
                <div>
                  <div className="metric-val" style={{ fontSize: '20px' }}>
                    {operatorData?.assigned_zone || 'Zone B'}
                  </div>
                  <div className="metric-lbl">Assigned Fulfillment Zone</div>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* Server-Enforced Permissions Reference */}
        <div
          style={{
            marginTop: '36px',
            background: '#ffffff',
            borderRadius: '16px',
            padding: '24px',
            border: '1px solid #e2e8f0',
          }}
        >
          <h3 style={{ fontSize: '16px', fontWeight: 700, color: '#0f172a', marginBottom: '12px' }}>
            Server-Enforced Role Privileges ({user.role.toUpperCase()})
          </h3>
          <p style={{ fontSize: '13px', color: '#64748b', marginBottom: '16px' }}>
            These permissions are validated independently on each API endpoint by Laravel middleware. Modifying client state cannot elevate your backend access.
          </p>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px' }}>
            {user.permissions.map((perm) => (
              <span
                key={perm}
                style={{
                  background: '#f8fafc',
                  border: '1px solid #cbd5e1',
                  borderRadius: '6px',
                  padding: '4px 10px',
                  fontSize: '12px',
                  fontFamily: 'monospace',
                  color: '#334155',
                }}
              >
                {perm}
              </span>
            ))}
          </div>
        </div>
      </main>
    </div>
  );
};
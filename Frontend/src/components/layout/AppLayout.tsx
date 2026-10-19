import React, { useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import {
  LayoutDashboard,
  Warehouse,
  Store as StoreIcon,
  FileText,
  Package,
  Truck,
  CheckSquare,
  FileSpreadsheet,
  ArrowLeftRight,
  RotateCcw,
  Users,
  Search,
  Bell,
  HelpCircle,
  Menu,
  X,
  ChevronRight,
  LogOut
} from 'lucide-react';
import { useAuth } from '../../hooks/useAuth';
import Logo from '../common/Logo';

export const AppLayout: React.FC = () => {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [userDropdownOpen, setUserDropdownOpen] = useState(false);

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  return (
    <div className="app-shell">
      {/* Mobile Backdrop */}
      {mobileMenuOpen && (
        <div
          className="modal-overlay"
          style={{ zIndex: 35 }}
          onClick={() => setMobileMenuOpen(false)}
        />
      )}

      {/* Sidebar matching Screenshot 1 */}
      <aside className={`app-sidebar ${mobileMenuOpen ? 'open' : ''}`}>
        {/* Brand Header */}
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 20 }}>
          <NavLink to="/dashboard" className="sidebar-brand">
            <Logo />
          </NavLink>
          {mobileMenuOpen && (
            <button
              onClick={() => setMobileMenuOpen(false)}
              className="topbar-icon-button"
              style={{ display: 'inline-flex' }}
            >
              <X size={20} />
            </button>
          )}
        </div>

        {/* User Card */}
        <div className="sidebar-user-card">
          <div className="sidebar-user-avatar">
            {user?.name ? user.name.charAt(0).toUpperCase() : 'A'}
          </div>
          <div style={{ display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
            <span style={{ fontSize: 13, fontWeight: 600, color: '#0f172a', whiteSpace: 'nowrap', textOverflow: 'ellipsis', overflow: 'hidden' }}>
              {user?.name || 'Admin User'}
            </span>
            <span style={{ fontSize: 11, color: '#94a3b8', textTransform: 'capitalize' }}>
              {user?.role || 'Admin'}
            </span>
          </div>
        </div>

        {/* Active Store Switcher Pill */}
        <div className="store-switcher-pill" onClick={() => navigate('/stores')}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <div style={{
              width: 30,
              height: 30,
              borderRadius: 8,
              backgroundColor: '#dbeafe',
              color: '#1e56ff',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center'
            }}>
              <StoreIcon size={16} />
            </div>
            <div style={{ display: 'flex', flexDirection: 'column' }}>
              <span style={{ fontSize: 10, fontWeight: 600, color: '#1e56ff', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                Managing
              </span>
              <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a' }}>
                My Store
              </span>
            </div>
          </div>
          <ChevronRight size={16} color="#94a3b8" />
        </div>

        {/* Navigation Sections */}
        <div style={{ display: 'flex', flexDirection: 'column', flex: 1 }}>
          {/* DIVISION */}
          <div className="sidebar-nav-section-title">DIVISION</div>
          <NavLink
            to="/dashboard"
            end
            className={({ isActive }) => `sidebar-nav-item ${isActive ? 'active' : ''}`}
            onClick={() => setMobileMenuOpen(false)}
          >
            <LayoutDashboard size={18} />
            <span>Dashboard</span>
          </NavLink>

          {/* GENERAL */}
          <div className="sidebar-nav-section-title">GENERAL</div>
          <NavLink
            to="/warehouses"
            className={({ isActive }) => `sidebar-nav-item ${isActive ? 'active' : ''}`}
            onClick={() => setMobileMenuOpen(false)}
          >
            <Warehouse size={18} />
            <span>Warehouses</span>
          </NavLink>
          <NavLink
            to="/stores"
            className={({ isActive }) => `sidebar-nav-item ${isActive ? 'active' : ''}`}
            onClick={() => setMobileMenuOpen(false)}
          >
            <StoreIcon size={18} />
            <span>Stores</span>
          </NavLink>

          {/* STORE MANAGEMENT */}
          <div className="sidebar-nav-section-title">STORE MANAGEMENT</div>
          <NavLink
            to="/orders"
            className={({ isActive }) => `sidebar-nav-item ${isActive ? 'active' : ''}`}
            onClick={() => setMobileMenuOpen(false)}
          >
            <FileText size={18} />
            <span>Orders</span>
          </NavLink>
          <NavLink
            to="/products"
            className={({ isActive }) => `sidebar-nav-item ${isActive ? 'active' : ''}`}
            onClick={() => setMobileMenuOpen(false)}
          >
            <Package size={18} />
            <span>Products</span>
          </NavLink>
          <NavLink
            to="/shipments"
            className={({ isActive }) => `sidebar-nav-item ${isActive ? 'active' : ''}`}
            onClick={() => setMobileMenuOpen(false)}
          >
            <Truck size={18} />
            <span>Shipments</span>
          </NavLink>
          <NavLink
            to="/picking"
            className={({ isActive }) => `sidebar-nav-item ${isActive ? 'active' : ''}`}
            onClick={() => setMobileMenuOpen(false)}
          >
            <CheckSquare size={18} />
            <span>Picking</span>
          </NavLink>
          <NavLink
            to="/purchase-orders"
            className={({ isActive }) => `sidebar-nav-item ${isActive ? 'active' : ''}`}
            onClick={() => setMobileMenuOpen(false)}
          >
            <FileSpreadsheet size={18} />
            <span>Purchase Orders</span>
          </NavLink>
          <NavLink
            to="/stock-movements"
            className={({ isActive }) => `sidebar-nav-item ${isActive ? 'active' : ''}`}
            onClick={() => setMobileMenuOpen(false)}
          >
            <ArrowLeftRight size={18} />
            <span>Stock Movements</span>
          </NavLink>
          <NavLink
            to="/returns"
            className={({ isActive }) => `sidebar-nav-item ${isActive ? 'active' : ''}`}
            onClick={() => setMobileMenuOpen(false)}
          >
            <RotateCcw size={18} />
            <span>Returns</span>
          </NavLink>
          <NavLink
            to="/customers"
            className={({ isActive }) => `sidebar-nav-item ${isActive ? 'active' : ''}`}
            onClick={() => setMobileMenuOpen(false)}
          >
            <Users size={18} />
            <span>Customers</span>
          </NavLink>
        </div>

        {/* Need Help Card (Bottom) */}
        <div className="sidebar-help-card">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <HelpCircle size={18} color="#1e56ff" />
            <span style={{ fontSize: 12, fontWeight: 700, color: '#0f172a' }}>Need help?</span>
          </div>
          <span style={{ fontSize: 11, color: '#64748b', lineHeight: 1.3 }}>
            Check our documentation or contact support.
          </span>
        </div>
      </aside>

      {/* Main Content Area */}
      <div className="app-main">
        {/* Topbar */}
        <header className="app-topbar">
          <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
            <button
              className="topbar-icon-button"
              style={{ display: 'none' }}
              id="mobile-drawer-toggle"
              onClick={() => setMobileMenuOpen(true)}
              aria-label="Toggle Navigation"
            >
              <Menu size={20} />
            </button>

            {/* Global Search Input */}
            <div className="topbar-search-input">
              <Search size={16} color="#94a3b8" />
              <input type="text" placeholder="Search anything..." />
            </div>
          </div>

          {/* Right Controls */}
          <div className="topbar-right-controls">
            <button className="topbar-icon-button" title="Help & Docs">
              <HelpCircle size={18} />
            </button>

            <button className="topbar-icon-button" title="Notifications">
              <Bell size={18} />
              <span className="notification-badge-dot">2</span>
            </button>

            {/* User Profile Pill & Dropdown */}
            <div style={{ position: 'relative' }}>
              <div
                style={{ display: 'flex', alignItems: 'center', gap: 10, cursor: 'pointer' }}
                onClick={() => setUserDropdownOpen(!userDropdownOpen)}
              >
                <div style={{
                  width: 34,
                  height: 34,
                  borderRadius: '50%',
                  backgroundColor: '#1e56ff',
                  color: '#ffffff',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  fontWeight: 600,
                  fontSize: 13
                }}>
                  {user?.name ? user.name.charAt(0).toUpperCase() : 'A'}
                </div>
                <div style={{ display: 'flex', flexDirection: 'column' }}>
                  <span style={{ fontSize: 13, fontWeight: 600, color: '#0f172a' }}>
                    {user?.name || 'Admin user'}
                  </span>
                  <span style={{ fontSize: 11, color: '#94a3b8' }}>
                    {user?.role_label || (user?.role === 'admin' ? 'Administrator' : 'Operator')}
                  </span>
                </div>
              </div>

              {userDropdownOpen && (
                <div style={{
                  position: 'absolute',
                  right: 0,
                  top: 44,
                  backgroundColor: '#ffffff',
                  border: '1px solid #e2e8f0',
                  borderRadius: 12,
                  boxShadow: '0 10px 15px -3px rgba(0, 0, 0, 0.1)',
                  padding: 6,
                  width: 170,
                  zIndex: 60
                }}>
                  <NavLink
                    to="/change-password"
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      gap: 8,
                      padding: '8px 12px',
                      fontSize: 13,
                      color: '#334155',
                      textDecoration: 'none',
                      borderRadius: 8
                    }}
                    onClick={() => setUserDropdownOpen(false)}
                  >
                    Change Password
                  </NavLink>
                  <div style={{ height: 1, backgroundColor: '#f1f5f9', margin: '4px 0' }} />
                  <button
                    onClick={handleLogout}
                    style={{
                      width: '100%',
                      display: 'flex',
                      alignItems: 'center',
                      gap: 8,
                      padding: '8px 12px',
                      fontSize: 13,
                      color: '#ef4444',
                      background: 'none',
                      border: 'none',
                      cursor: 'pointer',
                      borderRadius: 8
                    }}
                  >
                    <LogOut size={14} />
                    <span>Sign Out</span>
                  </button>
                </div>
              )}
            </div>
          </div>
        </header>

        {/* Dynamic Nested Content */}
        <Outlet />
      </div>

      <style>{`
        @media (max-width: 1024px) {
          #mobile-drawer-toggle {
            display: inline-flex !important;
          }
        }
      `}</style>
    </div>
  );
};

export default AppLayout;
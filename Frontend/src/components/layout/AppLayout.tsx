import React, { useCallback, useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import {
  LayoutDashboard,
  Warehouse,
  Store as StoreIcon,
  FileText,
  Package,
  Boxes,
  Truck,
  CheckSquare,
  FileSpreadsheet,
  ArrowLeftRight,
  RotateCcw,
  Users,
  UserCog,
  Search,
  Bell,
  HelpCircle,
  Loader2,
  Menu,
  X,
  ChevronRight,
  LogOut,
  Receipt,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useAuth } from '../../hooks/useAuth';
import { can } from '../../types/auth';
import type { Permission } from '../../types/auth';
import { useActiveStore } from '../../hooks/useActiveStore';
import Logo from '../common/Logo';
import { platformLabel } from '../stores/storePlatforms';
import ProductThumb from '../catalog/ProductThumb';
import BarcodeScanner from '../barcode/BarcodeScanner';
import omsService from '../../services/omsService';
import type { CustomerModel, OrderModel, ProductModel, StockAlert, StockAlertsResponse } from '../../services/omsService';
import { STOCK_ALERTS_ON_LOGIN_KEY } from '../../context/AuthContext';

const STOCK_ALERTS_REFRESH_MS = 5 * 60 * 1000;


type NavItem = { to: string; label: string; icon: LucideIcon; permission: Permission };

/** Sidebar sections and the permission each page needs; sections with no allowed page are hidden. */
const NAV_SECTIONS: { title: string; items: NavItem[] }[] = [
  {
    title: 'GENERAL',
    items: [
      { to: '/warehouses', label: 'Warehouses', icon: Warehouse, permission: 'warehouses.view' },
      { to: '/stores', label: 'Stores', icon: StoreIcon, permission: 'stores.view' },
    ],
  },
  {
    title: 'STORE MANAGEMENT',
    items: [
      { to: '/orders', label: 'Orders', icon: FileText, permission: 'orders.view' },
      { to: '/products', label: 'Products', icon: Package, permission: 'products.view' },
      { to: '/inventory', label: 'Inventory Engine', icon: Boxes, permission: 'inventory.view' },
      { to: '/shipments', label: 'Shipments', icon: Truck, permission: 'shipments.view' },
      { to: '/picking', label: 'Picking', icon: CheckSquare, permission: 'picking.view' },
      { to: '/purchase-orders', label: 'Purchase Orders', icon: FileSpreadsheet, permission: 'purchasing.view' },
      { to: '/stock-movements', label: 'Stock Movements', icon: ArrowLeftRight, permission: 'inventory.view' },
      { to: '/returns', label: 'Returns', icon: RotateCcw, permission: 'returns.view' },
      { to: '/customers', label: 'Customers', icon: Users, permission: 'customers.view' },
    ],
  },
  {
    title: 'ADMINISTRATION',
    items: [
      { to: '/billing', label: 'Client Billing', icon: Receipt, permission: 'billing.view' },
      { to: '/employees', label: 'Employees', icon: UserCog, permission: 'employees.view' },
    ],
  },
];

/**
 * Notification bell with out-of-stock and low-stock items. It refreshes every few minutes and when the tab
 * regains focus, and opens by itself once after each login when something needs reordering.
 */
const StockAlertsBell: React.FC = () => {
  const navigate = useNavigate();
  const [alerts, setAlerts] = useState<StockAlert[]>([]);
  const [meta, setMeta] = useState<StockAlertsResponse['meta'] | null>(null);
  const [open, setOpen] = useState(false);
  const wrapper = useRef<HTMLDivElement>(null);

  const load = useCallback(async () => {
    try {
      const res = await omsService.getStockAlerts();
      setAlerts(res.data);
      setMeta(res.meta);

      let justLoggedIn = false;
      try {
        justLoggedIn = sessionStorage.getItem(STOCK_ALERTS_ON_LOGIN_KEY) === '1';
        sessionStorage.removeItem(STOCK_ALERTS_ON_LOGIN_KEY);
      } catch {
        // Storage unavailable: the bell still shows the count.
      }
      if (justLoggedIn && res.meta.total > 0) setOpen(true);
    } catch (err) {
      console.error(err);
    }
  }, []);

  useEffect(() => {
    load();
    const timer = window.setInterval(load, STOCK_ALERTS_REFRESH_MS);
    window.addEventListener('focus', load);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener('focus', load);
    };
  }, [load]);

  useEffect(() => {
    if (!open) return;
    const close = (event: MouseEvent) => {
      if (wrapper.current && !wrapper.current.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [open]);

  const total = meta?.total ?? 0;

  return (
    <div className="stock-alerts" ref={wrapper}>
      <button
        className="topbar-icon-button"
        title="Stock alerts"
        aria-label={total ? `${total} stock alerts` : 'No stock alerts'}
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
      >
        <Bell size={18} />
        {total > 0 && <span className="notification-badge-dot">{total > 99 ? '99+' : total}</span>}
      </button>

      {open && (
        <div className="stock-alerts-panel" role="dialog" aria-label="Stock alerts">
          <div className="stock-alerts-head">
            <strong>Stock alerts</strong>
            {meta && total > 0 && (
              <span>
                {meta.out_of_stock} out of stock · {meta.low_stock} low
              </span>
            )}
          </div>

          {total === 0 ? (
            <div className="stock-alerts-empty">All items are above their reorder level.</div>
          ) : (
            <ul className="stock-alerts-list">
              {alerts.map((alert) => (
                <li key={`${alert.warehouse_id}-${alert.product_variant_id}`}>
                  <ProductThumb src={alert.image_url} size={34} />
                  <div className="stock-alerts-item">
                    <div className="stock-alerts-name">
                      {alert.product_name ?? alert.sku}
                      {alert.variant ? ` · ${alert.variant}` : ''}
                    </div>
                    <div className="stock-alerts-meta">
                      {alert.sku} · {alert.warehouse_name}
                    </div>
                  </div>
                  <span className={`stock-alerts-qty ${alert.status === 'out_of_stock' ? 'is-out' : 'is-low'}`}>
                    {alert.status === 'out_of_stock' ? 'Out of stock' : `${alert.available} left`}
                    <small>reorder at {alert.threshold}</small>
                  </span>
                </li>
              ))}
            </ul>
          )}

          <button
            className="stock-alerts-footer"
            onClick={() => {
              setOpen(false);
              navigate('/inventory');
            }}
          >
            {total > alerts.length ? `Showing ${alerts.length} of ${total} · ` : ''}Open inventory to reorder
          </button>
        </div>
      )}
    </div>
  );
};

interface SearchResults {
  orders: OrderModel[];
  products: ProductModel[];
  customers: CustomerModel[];
}

/**
 * Top-bar search across the managed store's orders, products and customers. Picking a result opens it on its page.
 */
const GlobalSearch: React.FC = () => {
  const navigate = useNavigate();
  const { user } = useAuth();
  const { activeStore } = useActiveStore();
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<SearchResults | null>(null);
  const [loading, setLoading] = useState(false);
  const [open, setOpen] = useState(false);
  const boxRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const term = query.trim();
    if (term.length < 2) {
      setResults(null);
      return;
    }

    let cancelled = false;
    const timer = setTimeout(async () => {
      setLoading(true);
      try {
        const params = { search: term, store_id: activeStore?.id, per_page: 5 };
        // Only the areas this user may open are searched.
        const none = { data: [] };
        const [orders, products, customers] = await Promise.all([
          can(user, 'orders.view') ? omsService.getOrders(params) : none,
          can(user, 'products.view') ? omsService.getProducts(params) : none,
          can(user, 'customers.view') ? omsService.getCustomers(params) : none,
        ]);
        if (!cancelled) setResults({ orders: orders.data, products: products.data, customers: customers.data });
      } catch {
        if (!cancelled) setResults({ orders: [], products: [], customers: [] });
      } finally {
        if (!cancelled) setLoading(false);
      }
    }, 300);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [query, activeStore?.id, user]);

  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  const go = (path: string) => {
    setOpen(false);
    setQuery('');
    navigate(path);
  };

  const term = query.trim();
  const empty = results && !results.orders.length && !results.products.length && !results.customers.length;

  return (
    <div className="global-search" ref={boxRef}>
      <div className="topbar-search-input">
        <Search size={16} color="#94a3b8" />
        <input
          type="text"
          placeholder="Search orders, products, customers..."
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          onKeyDown={(e) => {
            if (e.key === 'Escape') setOpen(false);
            if (e.key === 'Enter' && term) go(`/orders?search=${encodeURIComponent(term)}`);
          }}
          aria-label="Search orders, products and customers"
        />
        {loading && <Loader2 size={14} className="animate-spin" color="#94a3b8" />}
      </div>

      {open && term.length >= 2 && results && (
        <div className="global-search-results" role="listbox">
          {empty && <div className="global-search-empty">Nothing found for "{term}".</div>}

          {results.orders.length > 0 && (
            <div className="global-search-group">
              <div className="global-search-heading">Orders</div>
              {results.orders.map((o) => (
                <button key={`o${o.id}`} type="button" className="global-search-item" onClick={() => go(`/orders?search=${encodeURIComponent(o.order_number)}`)}>
                  <FileText size={15} />
                  <span className="global-search-title">{o.order_number}</span>
                  <span className="global-search-meta">{o.customer_name}</span>
                </button>
              ))}
            </div>
          )}

          {results.products.length > 0 && (
            <div className="global-search-group">
              <div className="global-search-heading">Products</div>
              {results.products.map((p) => (
                <button key={`p${p.id}`} type="button" className="global-search-item" onClick={() => go(`/products/${p.id}`)}>
                  <Package size={15} />
                  <span className="global-search-title">{p.name}</span>
                  <span className="global-search-meta">{p.sku}</span>
                </button>
              ))}
            </div>
          )}

          {results.customers.length > 0 && (
            <div className="global-search-group">
              <div className="global-search-heading">Customers</div>
              {results.customers.map((c) => (
                <button key={`c${c.id}`} type="button" className="global-search-item" onClick={() => go(`/customers/${c.id}`)}>
                  <Users size={15} />
                  <span className="global-search-title">{c.name}</span>
                  <span className="global-search-meta">{c.email ?? c.phone ?? ''}</span>
                </button>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export const AppLayout: React.FC = () => {
  const { user, logout } = useAuth();
  const navSections = NAV_SECTIONS.map((section) => ({ ...section, items: section.items.filter((item) => can(user, item.permission)) })).filter(
    (section) => section.items.length > 0
  );
  const { activeStore } = useActiveStore();
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
            <span style={{ fontSize: 11, color: '#94a3b8' }}>
              {user?.role_label ?? user?.role}
            </span>
          </div>
        </div>

        {/* Active Store Switcher Pill */}
        <div
          className="store-switcher-pill"
          onClick={() => navigate('/stores')}
          title={activeStore ? `${activeStore.name} (${platformLabel(activeStore.platform)})` : 'Choose a store to manage'}
        >
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
            <div style={{ display: 'flex', flexDirection: 'column', minWidth: 0 }}>
              <span style={{ fontSize: 10, fontWeight: 600, color: '#1e56ff', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
                Managing
              </span>
              <span style={{ fontSize: 13, fontWeight: 700, color: '#0f172a', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                {activeStore?.name ?? 'No store selected'}
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

          {/* Sections and pages the user may open (NAV_SECTIONS) */}
          {navSections.map((section) => (
            <React.Fragment key={section.title}>
              <div className="sidebar-nav-section-title">{section.title}</div>
              {section.items.map(({ to, label, icon: Icon }) => (
                <NavLink
                  key={to}
                  to={to}
                  className={({ isActive }) => `sidebar-nav-item ${isActive ? 'active' : ''}`}
                  onClick={() => setMobileMenuOpen(false)}
                >
                  <Icon size={18} />
                  <span>{label}</span>
                </NavLink>
              ))}
            </React.Fragment>
          ))}
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

            <GlobalSearch />
          </div>

          {/* Right Controls */}
          <div className="topbar-right-controls">
            <BarcodeScanner />
            {can(user, 'inventory.view') && <StockAlertsBell />}

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
                    {user?.role_label ?? user?.role}
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
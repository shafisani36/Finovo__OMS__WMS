import React, { Suspense, lazy } from 'react';
import { BrowserRouter, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import { ActiveStoreProvider } from './context/ActiveStoreContext';
import { useAuth } from './hooks/useAuth';
import { LoginPage } from './pages/LoginPage';
import { VerifyOtpPage } from './pages/VerifyOtpPage';
import { ForgotPasswordPage } from './pages/ForgotPasswordPage';
import { ResetPasswordPage } from './pages/ResetPasswordPage';
import { ChangePasswordPage } from './pages/ChangePasswordPage';
import { SsoCallbackPage } from './pages/SsoCallbackPage';
import { AppLayout } from './components/layout/AppLayout';
import { AccessDenied } from './components/common/AccessDenied';
import { PageLoader } from './components/common/PageLoader';
import { can } from './types/auth';
import type { Permission } from './types/auth';
import { Loader2 } from 'lucide-react';

// Each page is downloaded the first time it is opened, so the app starts with a small file.
const DashboardPage = lazy(() => import('./pages/DashboardPage'));
const AnalyticsPage = lazy(() => import('./pages/AnalyticsPage'));
const StoresPage = lazy(() => import('./pages/StoresPage'));
const PickingPage = lazy(() => import('./pages/PickingPage'));
const WorkBoardPage = lazy(() => import('./pages/WorkBoardPage'));
const FormsPage = lazy(() => import('./pages/FormsPage'));
const RecurringOrdersPage = lazy(() => import('./pages/RecurringOrdersPage'));
const SerialNumbersPage = lazy(() => import('./pages/SerialNumbersPage'));
const TrackOrderPage = lazy(() => import('./pages/TrackOrderPage'));
const FormBuilderPage = lazy(() => import('./pages/FormBuilderPage'));
const ClientPortalPage = lazy(() => import('./pages/ClientPortalPage'));
const AssistantPage = lazy(() => import('./pages/AssistantPage'));
const StockMovementsPage = lazy(() => import('./pages/StockMovementsPage'));
const WarehousesPage = lazy(() => import('./pages/WarehousesPage'));
const WarehouseCreatePage = lazy(() => import('./pages/WarehouseCreatePage'));
const WarehouseDetailPage = lazy(() => import('./pages/WarehouseDetailPage'));
const OrdersPage = lazy(() => import('./pages/OrdersPage'));
const ProductsPage = lazy(() => import('./pages/ProductsPage'));
const InventoryPage = lazy(() => import('./pages/InventoryPage'));
const ShipmentsPage = lazy(() => import('./pages/ShipmentsPage'));
const ReturnsPage = lazy(() => import('./pages/ReturnsPage'));
const CustomersPage = lazy(() => import('./pages/CustomersPage'));
const BillingPage = lazy(() => import('./pages/BillingPage'));
const EmployeesPage = lazy(() => import('./pages/EmployeesPage'));
const ProductDetailPage = lazy(() => import('./pages/ProductDetailPage'));
const CustomerDetailPage = lazy(() => import('./pages/CustomerDetailPage'));
const PurchaseOrdersPage = lazy(() => import('./pages/PurchaseOrdersPage'));
const MobileApp = lazy(() => import('./pages/mobile/MobileApp'));

const ProtectedRoute: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, isAuthenticated, isLoading } = useAuth();
  const location = useLocation();

  if (isLoading) {
    return (
      <div
        style={{
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
          minHeight: '100vh',
          color: '#1e56ff',
          gap: '12px',
        }}
      >
        <Loader2 className="animate-spin" size={32} />
        <span style={{ fontSize: '14px', color: '#64748b', fontWeight: 500 }}>
          Verifying security credentials...
        </span>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }

  // 3PL client accounts only have the client portal, whatever address they open.
  if (user?.role === 'client') {
    return <ClientPortalPage />;
  }

  return <>{children}</>;
};

/** Opens a page only for users holding the permission; everyone else sees the 403 page inside the app. */
const RequirePermission: React.FC<{ permission: Permission; children: React.ReactNode }> = ({ permission, children }) => {
  const { user } = useAuth();
  return can(user, permission) ? <>{children}</> : <AccessDenied variant={403} />;
};

const PublicOnlyRoute: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { isAuthenticated, isLoading } = useAuth();

  if (isLoading) {
    return null;
  }

  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />;
  }

  return <>{children}</>;
};

export const App: React.FC = () => {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Suspense fallback={<PageLoader />}>
        <Routes>
          {/* Public Authentication Routes */}
          <Route
            path="/login"
            element={
              <PublicOnlyRoute>
                <LoginPage />
              </PublicOnlyRoute>
            }
          />
          <Route path="/verify-otp" element={<VerifyOtpPage />} />
          <Route
            path="/forgot-password"
            element={
              <PublicOnlyRoute>
                <ForgotPasswordPage />
              </PublicOnlyRoute>
            }
          />
          <Route path="/sso/callback" element={<SsoCallbackPage />} />
          {/* Customer self-service: open to everyone, signed in or not */}
          <Route path="/track" element={<TrackOrderPage />} />
          <Route
            path="/reset-password"
            element={
              <PublicOnlyRoute>
                <ResetPasswordPage />
              </PublicOnlyRoute>
            }
          />

          {/* Mobile scanning app: full screen, without the desktop sidebar */}
          <Route
            path="/mobile"
            element={
              <ProtectedRoute>
                {/* Like the desktop, the app works for the store being managed. */}
                <ActiveStoreProvider>
                  <RequirePermission permission="inventory.view">
                    <MobileApp />
                  </RequirePermission>
                </ActiveStoreProvider>
              </ProtectedRoute>
            }
          />

          {/* Protected Enterprise OMS / WMS Application Shell */}
          <Route
            element={
              <ProtectedRoute>
                <ActiveStoreProvider>
                  <AppLayout />
                </ActiveStoreProvider>
              </ProtectedRoute>
            }
          >
            <Route path="/dashboard" element={<DashboardPage />} />
            <Route path="/analytics" element={<RequirePermission permission="orders.view"><AnalyticsPage /></RequirePermission>} />
            <Route path="/stores" element={<RequirePermission permission="stores.view"><StoresPage /></RequirePermission>} />
            <Route path="/warehouses" element={<RequirePermission permission="warehouses.view"><WarehousesPage /></RequirePermission>} />
            <Route path="/warehouses/create" element={<RequirePermission permission="warehouses.manage"><WarehouseCreatePage /></RequirePermission>} />
            <Route path="/warehouses/:id" element={<RequirePermission permission="warehouses.view"><WarehouseDetailPage /></RequirePermission>} />
            <Route path="/orders" element={<RequirePermission permission="orders.view"><OrdersPage /></RequirePermission>} />
            <Route path="/recurring-orders" element={<RequirePermission permission="orders.manage"><RecurringOrdersPage /></RequirePermission>} />
            <Route path="/products" element={<RequirePermission permission="products.view"><ProductsPage /></RequirePermission>} />
            <Route path="/products/:id" element={<RequirePermission permission="products.view"><ProductDetailPage /></RequirePermission>} />
            <Route path="/inventory" element={<RequirePermission permission="inventory.view"><InventoryPage /></RequirePermission>} />
            <Route path="/serial-numbers" element={<RequirePermission permission="inventory.view"><SerialNumbersPage /></RequirePermission>} />
            <Route path="/shipments" element={<RequirePermission permission="shipments.view"><ShipmentsPage /></RequirePermission>} />
            <Route path="/picking" element={<RequirePermission permission="picking.view"><PickingPage /></RequirePermission>} />
            <Route path="/work-board" element={<RequirePermission permission="picking.view"><WorkBoardPage /></RequirePermission>} />
            <Route path="/forms" element={<RequirePermission permission="forms.submit"><FormsPage /></RequirePermission>} />
            <Route path="/forms/:uuid" element={<RequirePermission permission="forms.submit"><FormBuilderPage /></RequirePermission>} />
            <Route path="/assistant" element={<RequirePermission permission="orders.view"><AssistantPage /></RequirePermission>} />
            <Route path="/purchase-orders" element={<RequirePermission permission="purchasing.view"><PurchaseOrdersPage /></RequirePermission>} />
            <Route path="/purchase-requisitions" element={<RequirePermission permission="purchasing.view"><PurchaseOrdersPage /></RequirePermission>} />
            <Route path="/goods-received-notes" element={<RequirePermission permission="purchasing.view"><PurchaseOrdersPage /></RequirePermission>} />
            <Route path="/stock-movements" element={<RequirePermission permission="inventory.view"><StockMovementsPage /></RequirePermission>} />
            <Route path="/returns" element={<RequirePermission permission="returns.view"><ReturnsPage /></RequirePermission>} />
            <Route path="/customers" element={<RequirePermission permission="customers.view"><CustomersPage /></RequirePermission>} />
            <Route path="/billing" element={<RequirePermission permission="billing.view"><BillingPage /></RequirePermission>} />
            <Route path="/employees" element={<RequirePermission permission="employees.view"><EmployeesPage /></RequirePermission>} />
            <Route path="/customers/:id" element={<RequirePermission permission="customers.view"><CustomerDetailPage /></RequirePermission>} />
            <Route path="/change-password" element={<ChangePasswordPage />} />
            <Route path="*" element={<AccessDenied variant={404} />} />
          </Route>

          {/* Default Navigation Fallbacks */}
          <Route path="/" element={<Navigate to="/dashboard" replace />} />
        </Routes>
        </Suspense>
      </AuthProvider>
    </BrowserRouter>
  );
};

export default App;

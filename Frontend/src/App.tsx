import React from 'react';
import { BrowserRouter, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import { ActiveStoreProvider } from './context/ActiveStoreContext';
import { useAuth } from './hooks/useAuth';
import { LoginPage } from './pages/LoginPage';
import { VerifyOtpPage } from './pages/VerifyOtpPage';
import { ForgotPasswordPage } from './pages/ForgotPasswordPage';
import { ResetPasswordPage } from './pages/ResetPasswordPage';
import { DashboardPage } from './pages/DashboardPage';
import { ChangePasswordPage } from './pages/ChangePasswordPage';
import { StoresPage } from './pages/StoresPage';
import { PickingPage } from './pages/PickingPage';
import { StockMovementsPage } from './pages/StockMovementsPage';
import { WarehousesPage } from './pages/WarehousesPage';
import { WarehouseCreatePage } from './pages/WarehouseCreatePage';
import { WarehouseDetailPage } from './pages/WarehouseDetailPage';
import { OrdersPage } from './pages/OrdersPage';
import { ProductsPage } from './pages/ProductsPage';
import { InventoryPage } from './pages/InventoryPage';
import { ShipmentsPage } from './pages/ShipmentsPage';
import { ReturnsPage } from './pages/ReturnsPage';
import { CustomersPage } from './pages/CustomersPage';
import { BillingPage } from './pages/BillingPage';
import { EmployeesPage } from './pages/EmployeesPage';
import { ProductDetailPage } from './pages/ProductDetailPage';
import { CustomerDetailPage } from './pages/CustomerDetailPage';
import { PurchaseOrdersPage } from './pages/PurchaseOrdersPage';
import { AppLayout } from './components/layout/AppLayout';
import { AccessDenied } from './components/common/AccessDenied';
import { can } from './types/auth';
import type { Permission } from './types/auth';
import { Loader2 } from 'lucide-react';

const ProtectedRoute: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { isAuthenticated, isLoading } = useAuth();
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
          <Route
            path="/reset-password"
            element={
              <PublicOnlyRoute>
                <ResetPasswordPage />
              </PublicOnlyRoute>
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
            <Route path="/stores" element={<RequirePermission permission="stores.view"><StoresPage /></RequirePermission>} />
            <Route path="/warehouses" element={<RequirePermission permission="warehouses.view"><WarehousesPage /></RequirePermission>} />
            <Route path="/warehouses/create" element={<RequirePermission permission="warehouses.manage"><WarehouseCreatePage /></RequirePermission>} />
            <Route path="/warehouses/:id" element={<RequirePermission permission="warehouses.view"><WarehouseDetailPage /></RequirePermission>} />
            <Route path="/orders" element={<RequirePermission permission="orders.view"><OrdersPage /></RequirePermission>} />
            <Route path="/products" element={<RequirePermission permission="products.view"><ProductsPage /></RequirePermission>} />
            <Route path="/products/:id" element={<RequirePermission permission="products.view"><ProductDetailPage /></RequirePermission>} />
            <Route path="/inventory" element={<RequirePermission permission="inventory.view"><InventoryPage /></RequirePermission>} />
            <Route path="/shipments" element={<RequirePermission permission="shipments.view"><ShipmentsPage /></RequirePermission>} />
            <Route path="/picking" element={<RequirePermission permission="picking.view"><PickingPage /></RequirePermission>} />
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
      </AuthProvider>
    </BrowserRouter>
  );
};

export default App;

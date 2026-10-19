import React from 'react';
import { BrowserRouter, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
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
import { PurchaseOrdersPage } from './pages/PurchaseOrdersPage';
import { AppLayout } from './components/layout/AppLayout';
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
                <AppLayout />
              </ProtectedRoute>
            }
          >
            <Route path="/dashboard" element={<DashboardPage />} />
            <Route path="/stores" element={<StoresPage />} />
            <Route path="/warehouses" element={<WarehousesPage />} />
            <Route path="/warehouses/create" element={<WarehouseCreatePage />} />
            <Route path="/warehouses/:id" element={<WarehouseDetailPage />} />
            <Route path="/orders" element={<OrdersPage />} />
            <Route path="/products" element={<ProductsPage />} />
            <Route path="/inventory" element={<InventoryPage />} />
            <Route path="/shipments" element={<ShipmentsPage />} />
            <Route path="/picking" element={<PickingPage />} />
            <Route path="/purchase-orders" element={<PurchaseOrdersPage />} />
            <Route path="/purchase-requisitions" element={<PurchaseOrdersPage />} />
            <Route path="/goods-received-notes" element={<PurchaseOrdersPage />} />
            <Route path="/stock-movements" element={<StockMovementsPage />} />
            <Route path="/returns" element={<ReturnsPage />} />
            <Route path="/customers" element={<CustomersPage />} />
            <Route path="/change-password" element={<ChangePasswordPage />} />
          </Route>

          {/* Default Navigation Fallbacks */}
          <Route path="/" element={<Navigate to="/dashboard" replace />} />
          <Route path="*" element={<Navigate to="/login" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  );
};

export default App;

import { BrowserRouter, Navigate, Route, Routes, useParams } from 'react-router-dom';
import { CartProvider } from './cart/CartContext.jsx';
import Layout from './components/Layout.jsx';
import NotFoundPage from './pages/NotFoundPage.jsx';
import AdminProductsPage from './pages/AdminProductsPage.jsx';
import ProductsPage from './pages/ProductsPage.jsx';
import ProductFormPage from './pages/ProductFormPage.jsx';
import CartPage from './pages/CartPage.jsx';
import OrderResultPage from './pages/OrderResultPage.jsx';

// Keyed by id so state never leaks between products (or into the create form).
function EditProductRoute() {
  const { id } = useParams();
  return <ProductFormPage key={`edit-${id}`} mode="edit" />;
}

function RedirectToAdminEdit() {
  const { id } = useParams();
  return <Navigate to={`/admin/products/${id}/edit`} replace />;
}

// Exported without the router so tests can use MemoryRouter.
export function AppRoutes() {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="/products" replace />} />
      <Route path="/products" element={<ProductsPage />} />
      <Route path="/products/new" element={<Navigate to="/admin/products/new" replace />} />
      <Route path="/products/:id/edit" element={<RedirectToAdminEdit />} />
      <Route path="/admin" element={<Navigate to="/admin/products" replace />} />
      <Route path="/admin/products" element={<AdminProductsPage />} />
      <Route path="/admin/products/new" element={<ProductFormPage key="new" mode="create" />} />
      <Route path="/admin/products/:id/edit" element={<EditProductRoute />} />
      <Route path="/cart" element={<CartPage />} />
      <Route path="/orders/:id" element={<OrderResultPage />} />
      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  );
}

// react-router v7 future flags.
export const ROUTER_FUTURE_FLAGS = { v7_startTransition: true, v7_relativeSplatPath: true };

export default function App() {
  return (
    <CartProvider>
      <BrowserRouter future={ROUTER_FUTURE_FLAGS}>
        <Layout>
          <AppRoutes />
        </Layout>
      </BrowserRouter>
    </CartProvider>
  );
}

import { BrowserRouter, Navigate, Route, Routes, useParams } from 'react-router-dom';
import { CartProvider } from './cart/CartContext.jsx';
import Layout from './components/Layout.jsx';
import NotFoundPage from './pages/NotFoundPage.jsx';
import ProductsPage from './pages/ProductsPage.jsx';
import ProductFormPage from './pages/ProductFormPage.jsx';
import CartPage from './pages/CartPage.jsx';
import OrderResultPage from './pages/OrderResultPage.jsx';

// Keyed by id so state never leaks between products (or into the create form).
function EditProductRoute() {
  const { id } = useParams();
  return <ProductFormPage key={`edit-${id}`} mode="edit" />;
}

// Exported separately (without the BrowserRouter) so tests can render it
// inside a MemoryRouter at a specific path.
export function AppRoutes() {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="/products" replace />} />
      <Route path="/products" element={<ProductsPage />} />
      <Route path="/products/new" element={<ProductFormPage key="new" mode="create" />} />
      <Route path="/products/:id/edit" element={<EditProductRoute />} />
      <Route path="/cart" element={<CartPage />} />
      <Route path="/orders/:id" element={<OrderResultPage />} />
      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  );
}

// Opt in to the v7 behaviors early so there's no warning noise and no
// surprise when react-router-dom is eventually upgraded past ^6.
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

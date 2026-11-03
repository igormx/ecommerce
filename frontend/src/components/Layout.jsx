import { Link, NavLink, useLocation } from 'react-router-dom';
import { useCart } from '../cart/CartContext.jsx';

function navLinkClass({ isActive }) {
  return isActive ? 'app-nav__link app-nav__link--active' : 'app-nav__link';
}

export default function Layout({ children }) {
  const { totalQuantity: cartItemCount } = useCart();
  const inAdmin = useLocation().pathname.startsWith('/admin');

  return (
    <div className="app-shell">
      <a href="#main" className="skip-link">
        Skip to content
      </a>
      <header className="app-header">
        <div className="app-header__inner">
          <NavLink to="/products" className="app-brand">
            E-Commerce
          </NavLink>
          <nav className="app-nav" aria-label="Main navigation">
            <NavLink to="/products" className={navLinkClass} end>
              Products
            </NavLink>
            <NavLink to="/cart" className={navLinkClass}>
              Cart ({cartItemCount})
            </NavLink>
            <NavLink to="/admin/products" className={navLinkClass}>
              Admin
            </NavLink>
          </nav>
        </div>
        {inAdmin && (
          <div className="admin-bar">
            <div className="admin-bar__inner">
              <strong>Admin section</strong>
              <Link to="/products">Back to storefront</Link>
            </div>
          </div>
        )}
      </header>
      <main id="main" className="app-main" tabIndex={-1}>{children}</main>
    </div>
  );
}

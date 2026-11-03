import { NavLink } from 'react-router-dom';
import { useCart } from '../cart/CartContext.jsx';

function navLinkClass({ isActive }) {
  return isActive ? 'app-nav__link app-nav__link--active' : 'app-nav__link';
}

export default function Layout({ children }) {
  const { totalQuantity: cartItemCount } = useCart();

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
            <NavLink to="/products/new" className={navLinkClass}>
              New product
            </NavLink>
            <NavLink to="/cart" className={navLinkClass}>
              Cart ({cartItemCount})
            </NavLink>
          </nav>
        </div>
      </header>
      <main id="main" className="app-main" tabIndex={-1}>{children}</main>
    </div>
  );
}

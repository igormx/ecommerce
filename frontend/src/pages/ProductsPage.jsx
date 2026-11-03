import ProductBrowser from '../components/ProductBrowser.jsx';
import ProductTable from '../components/ProductTable.jsx';

// Shopper-facing catalog: browse and add to cart only.
export default function ProductsPage() {
  return (
    <ProductBrowser title="Products">
      {({ items }) => <ProductTable products={items} mode="storefront" />}
    </ProductBrowser>
  );
}

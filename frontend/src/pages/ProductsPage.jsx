import ProductBrowser from '../components/ProductBrowser.jsx';
import ProductTable from '../components/ProductTable.jsx';

export default function ProductsPage() {
  return (
    <ProductBrowser title="Products">
      {({ items }) => <ProductTable products={items} mode="storefront" />}
    </ProductBrowser>
  );
}

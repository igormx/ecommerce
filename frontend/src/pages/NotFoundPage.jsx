import { Link } from 'react-router-dom';

export default function NotFoundPage() {
  return (
    <section className="page page--centered">
      <h1>Page not found</h1>
      <p>We couldn&rsquo;t find what you were looking for.</p>
      <Link to="/products">Back to products</Link>
    </section>
  );
}

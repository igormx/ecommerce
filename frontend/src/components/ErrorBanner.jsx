// aria-live so screen readers announce async failures.
export default function ErrorBanner({ error, message }) {
  const text = message || error?.description || 'Something went wrong. Please try again.';

  return (
    <div className="error-banner" role="alert" aria-live="assertive">
      {text}
    </div>
  );
}

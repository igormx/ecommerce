// Renders an ApiError (or a plain message) as an alert. `aria-live` so
// screen readers announce it when it appears after an async failure.
export default function ErrorBanner({ error, message }) {
  const text = message || error?.description || 'Something went wrong. Please try again.';

  return (
    <div className="error-banner" role="alert" aria-live="assertive">
      {text}
    </div>
  );
}

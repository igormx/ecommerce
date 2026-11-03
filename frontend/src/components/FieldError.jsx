// Server `fields` keys are literal dotted strings (e.g. 'items.0.quantity'), not nested objects.

export function fieldPath(...segments) {
  return segments.join('.');
}

export function getFieldError(fields, path) {
  return fields ? fields[path] : undefined;
}

/** For aria-describedby. */
export function fieldErrorId(name) {
  return `${name}-error`;
}

export default function FieldError({ fields, name }) {
  const message = getFieldError(fields, name);
  if (!message) return null;

  return (
    <span className="field-error" id={fieldErrorId(name)} role="alert">
      {message}
    </span>
  );
}

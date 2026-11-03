// `ApiError.fields` is a flat object whose keys can be nested paths, e.g.
// `{ "items.0.quantity": "0 must be between 1 and 1000" }` (see
// README.md "Errors"). Callers building that path for a given input (e.g.
// a line index in Phase 4) use `fieldPath`; `getFieldError` then looks it
// up directly, since the server's keys are literal dotted strings, not a
// nested object.

/** @param {...(string | number)} segments @returns {string} e.g. fieldPath('items', 0, 'quantity') === 'items.0.quantity' */
export function fieldPath(...segments) {
  return segments.join('.');
}

/** @param {Record<string, string> | undefined} fields @param {string} path */
export function getFieldError(fields, path) {
  return fields ? fields[path] : undefined;
}

/** DOM id of a field's error message, for `aria-describedby` on the input. */
export function fieldErrorId(name) {
  return `${name}-error`;
}

/** Shows the server's validation message for one field, if any. */
export default function FieldError({ fields, name }) {
  const message = getFieldError(fields, name);
  if (!message) return null;

  return (
    <span className="field-error" id={fieldErrorId(name)} role="alert">
      {message}
    </span>
  );
}

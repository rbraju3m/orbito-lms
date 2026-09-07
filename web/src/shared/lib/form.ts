import type { FieldValues, Path, UseFormSetError } from 'react-hook-form';

import { ApiError } from '@/shared/api/errors';

/**
 * Maps the server's 422 `details[]` back onto form fields.
 *
 * Anything the form does not have a field for (a cross-field rule, a domain
 * conflict) is returned so the caller can show it at form level rather than
 * silently dropping it.
 */
export function applyServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  knownFields: readonly string[],
): string | null {
  if (!(error instanceof ApiError)) {
    return 'Something went wrong. Please try again.';
  }

  if (!error.isValidation) {
    return error.message;
  }

  const fieldErrors = error.fieldErrors();
  const unmapped: string[] = [];
  let mappedAny = false;

  for (const [field, message] of Object.entries(fieldErrors)) {
    if (knownFields.includes(field)) {
      setError(field as Path<T>, { type: 'server', message });
      mappedAny = true;
    } else {
      unmapped.push(message);
    }
  }

  if (unmapped.length > 0) {
    return unmapped.join(' ');
  }

  // A rejection that lands on no field at all must still be visible. Returning
  // null here would leave the user clicking a button that silently does nothing.
  return mappedAny ? null : error.message;
}

/**
 * The API's wire contract, mirrored in TypeScript.
 * Source of truth: docs/API.md §2. If these drift, the API broke its promise.
 */

export interface Envelope<T> {
  data: T;
}

export interface OffsetMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface PageLinks {
  first: string | null;
  prev: string | null;
  next: string | null;
  last: string | null;
}

export interface Paginated<T> {
  data: T[];
  meta: OffsetMeta;
  links: PageLinks;
}

export interface CursorMeta {
  per_page: number;
  next_cursor: string | null;
  prev_cursor: string | null;
  has_more: boolean;
}

export interface CursorPaginated<T> {
  data: T[];
  meta: CursorMeta;
}

export interface ApiErrorDetail {
  field?: string;
  code: string;
  message: string;
}

export interface ApiErrorBody {
  error: {
    code: string;
    message: string;
    details: ApiErrorDetail[];
    request_id: string;
  };
}

/** Money is always integer minor units plus a currency (ADR-04). */
export interface Money {
  amount_minor: number;
  currency: string;
  formatted: string;
}

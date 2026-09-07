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

/**
 * `meta` is optional and omitted when empty. It carries what the caller can DO
 * about the failure — a date to wait for, the item blocking this one, the
 * courses still outstanding — never decoration. See docs/API.md §2.
 */
export interface ApiErrorMeta {
  unlocks_at?: string;
  blocked_by_id?: number;
  blocked_by_title?: string;
  prerequisites?: { id: string; slug: string; title: string }[];
  status?: string;
  cover_ended_at?: string;
  [key: string]: unknown;
}

export interface ApiErrorBody {
  error: {
    code: string;
    message: string;
    details: ApiErrorDetail[];
    request_id: string;
    meta?: ApiErrorMeta;
  };
}

/** Money is always integer minor units plus a currency (ADR-04). */
export interface Money {
  amount_minor: number;
  currency: string;
  formatted: string;
}

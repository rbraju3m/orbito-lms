/**
 * The commerce wire contract. Source of truth: docs/API.md.
 *
 * Note what is NOT here: there is no `confirmPayment` shape, because the API
 * offers no endpoint a client could call to say a payment succeeded (ADR-05).
 * Access arrives through a webhook the browser never sees.
 */

export type OrderStatus = 'pending' | 'awaiting_payment' | 'paid' | 'cancelled' | 'failed';

export interface CartLine {
  id: string;
  product_id: string;
  title: string;
  purchasable_type: string;
  purchasable_id: number;
  /** Null when the product stopped being sold — unavailable, not free. */
  amount_minor: number | null;
  list_amount_minor: number | null;
  is_on_sale: boolean;
  is_available: boolean;
}

export interface Cart {
  id: string;
  currency: string;
  item_count: number;
  /**
   * An ESTIMATE, and named one on purpose. The basket is priced live on every
   * read; the order is priced once, at checkout. If a sale ends between the
   * two they legitimately disagree, and the UI must not present this as a
   * promise.
   */
  estimated_total_minor: number;
  /** Decided by the server so the button cannot invent its own rule. */
  is_checkoutable: boolean;
  items: CartLine[];
}

export interface OrderItem {
  title: string;
  purchasable_type: string;
  purchasable_id: number;
  unit_amount_minor: number;
  total_minor: number;
}

export interface Order {
  id: string;
  number: string;
  status: OrderStatus;
  status_label: string;
  grants_access: boolean;
  currency: string;
  subtotal_minor: number;
  discount_minor: number;
  total_minor: number;
  placed_at: string | null;
  paid_at: string | null;
  cancelled_at: string | null;
  items?: OrderItem[];
}

/**
 * Where to send the learner. One of the two is set depending on the provider:
 * a redirect for hosted checkout, a client secret for an inline SDK.
 *
 * `order_status` will be `awaiting_payment`, never `paid` — handing off is not
 * paying.
 */
export interface PaymentHandoff {
  order_id: string;
  order_status: OrderStatus;
  gateway: string;
  redirect_url: string | null;
  client_secret: string | null;
}

export interface PaymentGatewayAccount {
  gateway: string;
  label: string;
  /** The API never returns the credentials themselves, only whether they exist. */
  is_connected: boolean;
  has_webhook_secret: boolean;
  is_active: boolean;
  is_test_mode: boolean;
  updated_at: string | null;
}

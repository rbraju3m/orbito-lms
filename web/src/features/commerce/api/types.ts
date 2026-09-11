/**
 * The commerce wire contract. Source of truth: docs/API.md.
 *
 * Note what is NOT here: there is no `confirmPayment` shape, because the API
 * offers no endpoint a client could call to say a payment succeeded (ADR-05).
 * Access arrives through a webhook the browser never sees.
 */

export type OrderStatus =
  | 'pending'
  | 'awaiting_payment'
  | 'paid'
  | 'partially_refunded'
  | 'refunded'
  | 'cancelled'
  | 'failed';

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
  /** This line's share of the coupon, split the way checkout will split it. */
  discount_minor: number;
}

/**
 * The coupon on a basket, and the SERVER's answer about it — the same rules
 * checkout enforces. `applies` false means it stopped applying while it sat
 * here; `message` says why, and `is_checkoutable` is already false.
 */
export interface CartCoupon {
  code: string;
  description: string | null;
  applies: boolean;
  reason: string | null;
  message: string | null;
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
  estimated_subtotal_minor: number;
  estimated_discount_minor: number;
  coupon: CartCoupon | null;
  /** Decided by the server so the button cannot invent its own rule. */
  is_checkoutable: boolean;
  items: CartLine[];
}

export interface OrderItem {
  title: string;
  purchasable_type: string;
  purchasable_id: number;
  unit_amount_minor: number;
  /** The line's share of the order's coupon; `total_minor` is net of it. */
  discount_minor: number;
  total_minor: number;
}

export interface Order {
  id: string;
  number: string;
  status: OrderStatus;
  status_label: string;
  grants_access: boolean;
  currency: string;
  /** The code as typed at checkout, frozen. */
  coupon_code: string | null;
  subtotal_minor: number;
  discount_minor: number;
  total_minor: number;
  /** Completed refunds only. */
  refunded_minor: number;
  /** What can still be refunded — on the detail only, never in a list. */
  refundable_minor?: number;
  placed_at: string | null;
  paid_at: string | null;
  cancelled_at: string | null;
  items?: OrderItem[];
  refunds?: Refund[];
}

export type RefundStatus = 'pending' | 'completed' | 'failed';

/** Shown to the learner too — `reason` is written for them. */
export interface Refund {
  id: string;
  amount_minor: number;
  currency: string;
  method: 'gateway' | 'external';
  method_label: string;
  status: RefundStatus;
  status_label: string;
  reason: string | null;
  revokes_access: boolean;
  failure_reason: string | null;
  completed_at: string | null;
  created_at: string;
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
  /**
   * Where the provider must send its webhooks. Carries the academy id, which
   * the provider's endpoint setup needs and the product shows nowhere else.
   */
  webhook_url: string;
  /** The events to subscribe that endpoint to — the list the server acts on. */
  webhook_events: string[];
  updated_at: string | null;
}

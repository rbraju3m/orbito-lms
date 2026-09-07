import { create } from 'zustand';

interface SubscriptionLapsedState {
  /** The server's message, or null while the academy is in good standing. */
  message: string | null;
  report: (message: string) => void;
  clear: () => void;
}

/**
 * Whether this academy's subscription has lapsed.
 *
 * Client state, deliberately — it is not a resource anyone can fetch. Writes
 * are gated server-side (402) and reads never are, so the only way the SPA
 * learns about a lapse is by attempting a write and being refused. This holds
 * that answer so the whole app can say it once, instead of every button
 * discovering it separately.
 *
 * Cleared on the next successful mutation: renewing restores writes
 * immediately, and a banner that outlives the problem trains people to ignore
 * banners.
 */
export const useSubscriptionLapsed = create<SubscriptionLapsedState>((set) => ({
  message: null,
  report: (message) => set({ message }),
  clear: () => set({ message: null }),
}));

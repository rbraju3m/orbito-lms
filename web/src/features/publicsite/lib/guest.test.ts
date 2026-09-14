import { describe, expect, it } from 'vitest';

import type { Webinar } from '@/features/live/api/types';
import { ApiError } from '@/shared/api/errors';

import { guestProblem, offersGuestPlace } from './guest';

function webinar(overrides: Partial<Webinar> = {}): Webinar {
  return {
    id: 'w',
    slug: 'open-evening',
    title: 'Open evening',
    description: null,
    status: 'published',
    status_label: 'Published',
    capacity: null,
    places_remaining: null,
    is_paid: false,
    session: {
      id: 's',
      starts_at: '2026-10-01T13:00:00Z',
      ends_at: '2026-10-01T14:00:00Z',
      timezone: 'Asia/Dhaka',
      status: 'scheduled',
    },
    is_registered: false,
    can_cancel: false,
    ...overrides,
  };
}

const before = new Date('2026-09-30T00:00:00Z');

describe('offersGuestPlace', () => {
  it('offers a free event that has room and has not finished', () => {
    expect(offersGuestPlace(webinar(), before)).toBe(true);
    expect(offersGuestPlace(webinar({ places_remaining: 4 }), before)).toBe(true);
  });

  it('does not offer a paid place, a full room or a finished event', () => {
    expect(offersGuestPlace(webinar({ is_paid: true }), before)).toBe(false);
    expect(offersGuestPlace(webinar({ places_remaining: 0 }), before)).toBe(false);
    expect(offersGuestPlace(webinar(), new Date('2026-10-02T00:00:00Z'))).toBe(false);
  });
});

describe('guestProblem', () => {
  it('explains the refusals a guest can do something about', () => {
    const full = new ApiError({ code: 'webinar_full', message: 'x', status: 409 });

    expect(guestProblem(full)).toMatch(/last place/);
    expect(
      guestProblem(new ApiError({ code: 'something_else', message: 'x', status: 500 })),
    ).toBeNull();
    expect(guestProblem(new Error('network'))).toBeNull();
  });
});

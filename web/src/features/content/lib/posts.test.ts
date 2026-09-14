import { describe, expect, it } from 'vitest';

import { isFuture, postState, toPublishAt } from './posts';

describe('postState', () => {
  it('reads a draft, a scheduled post and a live one', () => {
    expect(postState({ status: 'draft', is_scheduled: false }).label).toBe('Draft');
    expect(postState({ status: 'published', is_scheduled: true }).label).toBe('Scheduled');
    expect(postState({ status: 'published', is_scheduled: false }).label).toBe('Published');
  });
});

describe('toPublishAt', () => {
  it('leaves the moment to the server when nothing was chosen', () => {
    expect(toPublishAt('')).toBeNull();
    expect(toPublishAt('not a date')).toBeNull();
  });

  it('turns a local date and time into an instant', () => {
    expect(toPublishAt('2026-10-01T09:30')).toBe(new Date('2026-10-01T09:30').toISOString());
  });

  it('knows a time still ahead from one already passed', () => {
    const now = new Date('2026-09-20T00:00:00Z');

    expect(isFuture('2026-10-01T09:30', now)).toBe(true);
    expect(isFuture('2026-01-01T09:30', now)).toBe(false);
    expect(isFuture('', now)).toBe(false);
  });
});

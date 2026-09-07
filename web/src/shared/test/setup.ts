import '@testing-library/jest-dom/vitest';

import { cleanup } from '@testing-library/react';
import { afterAll, afterEach, beforeAll, vi } from 'vitest';

import { server } from './server';

/**
 * jsdom has no matchMedia. A stub that always answers `false` forces every
 * responsive component into its mobile branch, so a desktop-only element is
 * simply absent and the test fails for a reason unrelated to the code.
 *
 * This evaluates min-width/max-width against a settable viewport, defaulting to
 * a desktop width. Call `setTestViewportWidth(390)` to test the mobile layout.
 */
let viewportWidth = 1280;

export function setTestViewportWidth(width: number): void {
  viewportWidth = width;
}

function evaluate(query: string): boolean {
  const toPx = (value: string, unit: string) =>
    unit === 'em' || unit === 'rem' ? Number(value) * 16 : Number(value);

  return query
    .split(' and ')
    .map((clause) => clause.trim())
    .every((clause) => {
      const min = clause.match(/min-width:\s*([\d.]+)(px|em|rem)/);
      if (min) return viewportWidth >= toPx(min[1]!, min[2]!);

      const max = clause.match(/max-width:\s*([\d.]+)(px|em|rem)/);
      if (max) return viewportWidth <= toPx(max[1]!, max[2]!);

      // Anything else (prefers-reduced-motion, print) stays false.
      return false;
    });
}

Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: (query: string) => ({
    matches: evaluate(query),
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }),
});

class ResizeObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}

window.ResizeObserver = ResizeObserverStub as unknown as typeof ResizeObserver;

// jsdom implements no FontFaceSet. Mantine's autosize Textarea listens for
// `loadingdone` on it, and without this the whole component tree throws.
if (!('fonts' in document)) {
  Object.defineProperty(document, 'fonts', {
    writable: true,
    value: {
      ready: Promise.resolve(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    },
  });
}

// Used by floating-ui for viewport-aware positioning.
if (!('visualViewport' in window)) {
  Object.defineProperty(window, 'visualViewport', {
    writable: true,
    value: {
      width: 1024,
      height: 768,
      scale: 1,
      offsetLeft: 0,
      offsetTop: 0,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    },
  });
}

// `error` makes an unhandled request fail the test rather than silently 404 —
// mocks that drift from the API contract are the bug we are guarding against.
beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => {
  server.resetHandlers();
  cleanup();
  viewportWidth = 1280;
});
afterAll(() => server.close());

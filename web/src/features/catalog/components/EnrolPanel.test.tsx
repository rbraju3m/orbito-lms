import { screen } from '@testing-library/react';
import { HttpResponse, http } from 'msw';
import { describe, expect, it } from 'vitest';

import { renderWithRouter } from '@/shared/test/renderRoute';
import { server } from '@/shared/test/server';

import type { Course } from '../api/types';
import { EnrolPanel } from './EnrolPanel';

const API = 'http://localhost:8000/api/v1';

/** A paid course reads its basket, so every paid case needs one. */
function serveEmptyCart() {
  server.use(
    http.get(`${API}/cart`, () =>
      HttpResponse.json({
        data: {
          id: 'cart-uuid',
          currency: 'USD',
          item_count: 0,
          estimated_total_minor: 0,
          is_checkoutable: false,
          items: [],
        },
      }),
    ),
  );
}

function course(overrides: Partial<Course> = {}): Course {
  return {
    id: 'course-uuid',
    ref: 1,
    slug: 'a-course',
    title: 'Modern Bengali Poetry',
    subtitle: null,
    description: null,
    level: 'all',
    level_label: 'All levels',
    locale: 'en',
    status: 'published',
    status_label: 'Published',
    visibility: 'public',
    completion_mode: 'flexible',
    pricing_model: 'free',
    thumbnail_media_id: null,
    intro_video_media_id: null,
    intro_video_url: null,
    item_count: 3,
    section_count: 1,
    total_duration_seconds: 900,
    enrollment_count: 4,
    rating_avg: 0,
    rating_count: 0,
    published_at: null,
    created_at: null,
    updated_at: null,
    prerequisites: [],
    seats_remaining: null,
    price: null,
    ...overrides,
  } as Course;
}

describe('EnrolPanel', () => {
  it('offers enrolment when nothing is in the way', () => {
    renderWithRouter(<EnrolPanel course={course()} />);

    expect(screen.getByRole('button', { name: /enrol for free/i })).toBeEnabled();
  });

  /*
   * A disabled control with no explanation is the same dead end as a 403, so
   * the reason lives under the button rather than in a tooltip nobody opens.
   */
  it('names the outstanding prerequisite instead of just disabling the button', () => {
    renderWithRouter(
      <EnrolPanel
        course={course({
          prerequisites: [
            { id: 'p1', ref: 9, slug: 'intro', title: 'Intro to Metre', is_met: false },
          ],
        })}
      />,
    );

    expect(screen.getByRole('button', { name: /enrol for free/i })).toBeDisabled();
    expect(screen.getByText(/finish “intro to metre” first/i)).toBeInTheDocument();
  });

  it('lets someone enrol once every prerequisite is met', () => {
    renderWithRouter(
      <EnrolPanel
        course={course({
          prerequisites: [
            { id: 'p1', ref: 9, slug: 'intro', title: 'Intro to Metre', is_met: true },
          ],
        })}
      />,
    );

    expect(screen.getByRole('button', { name: /enrol for free/i })).toBeEnabled();
  });

  /* null means uncapped, which is not the same as none left. */
  it('says nothing about seats when the course is uncapped', () => {
    renderWithRouter(<EnrolPanel course={course({ seats_remaining: null })} />);

    expect(screen.queryByText(/places left/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/this course is full/i)).not.toBeInTheDocument();
  });

  it('warns when places are running out, and blocks at zero', () => {
    const { unmount } = renderWithRouter(<EnrolPanel course={course({ seats_remaining: 3 })} />);
    expect(screen.getByText(/3 places left/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /enrol for free/i })).toBeEnabled();
    unmount();

    renderWithRouter(<EnrolPanel course={course({ seats_remaining: 0 })} />);
    expect(screen.getByText(/this course is full/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /enrol for free/i })).toBeDisabled();
  });

  /* Paid enrolment goes through the basket (ADR-05), never the free button. */
  it('offers the basket for a paid course, not free enrolment', async () => {
    serveEmptyCart();
    renderWithRouter(
      <EnrolPanel
        course={course({
          pricing_model: 'one_time',
          price: {
            product_id: 'product-uuid',
            currency: 'USD',
            amount_minor: 4900,
            list_amount_minor: null,
            is_on_sale: false,
          },
        })}
      />,
    );

    /*
     * Being paid used to DISABLE this button, because there was nowhere to
     * send anyone. Paying is now the way past a price, so the button must be
     * live — a priced course with a dead buy button is the bug this guards.
     */
    expect(await screen.findByRole('button', { name: /buy this course/i })).toBeEnabled();
    expect(screen.getByText('$49.00')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /enrol for free/i })).not.toBeInTheDocument();
  });

  it('still blocks buying when a prerequisite is outstanding', async () => {
    serveEmptyCart();
    renderWithRouter(
      <EnrolPanel
        course={course({
          pricing_model: 'one_time',
          price: {
            product_id: 'product-uuid',
            currency: 'USD',
            amount_minor: 4900,
            list_amount_minor: null,
            is_on_sale: false,
          },
          prerequisites: [
            { id: 'p1', ref: 9, slug: 'intro', title: 'Intro to Metre', is_met: false },
          ],
        })}
      />,
    );

    expect(await screen.findByRole('button', { name: /buy this course/i })).toBeDisabled();
    expect(screen.getByText(/finish “intro to metre” first/i)).toBeInTheDocument();
  });

  it('does not call a paid course with no price free', async () => {
    // Its product was deactivated. `pricing_model` still says one_time, so the
    // page must say unavailable rather than inventing a free enrolment.
    serveEmptyCart();
    renderWithRouter(<EnrolPanel course={course({ pricing_model: 'one_time', price: null })} />);

    expect(await screen.findByText(/not available/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /enrol for free/i })).not.toBeInTheDocument();
  });
});

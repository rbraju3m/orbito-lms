import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { renderWithProviders } from '@/shared/test/render';

import type { CourseItem } from '../api/types';
import { DripFields, type DripValues } from './DripFields';

const values: DripValues = {
  drip_available_at: null,
  drip_after_days: null,
  drip_after_item_id: null,
};

const siblings = [{ ref: 7, title: 'Scansion basics' }] as CourseItem[];

/*
 * All three drip parameters are STORED whatever the mode, so switching mode
 * loses nothing — but showing three inputs when two are inert invites filling
 * them in and wondering why nothing happened. These pin one field per mode.
 */
describe('DripFields', () => {
  it('shows no schedule at all when drip is off', () => {
    renderWithProviders(
      <DripFields
        mode="none"
        values={values}
        onChange={vi.fn()}
        siblings={siblings}
        isPreview={false}
      />,
    );

    expect(screen.getByText(/releases everything immediately/i)).toBeInTheDocument();
    expect(screen.queryByLabelText(/available from/i)).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/days after enrolling/i)).not.toBeInTheDocument();
  });

  it('shows only the date field in by_date mode', () => {
    renderWithProviders(
      <DripFields
        mode="by_date"
        values={values}
        onChange={vi.fn()}
        siblings={siblings}
        isPreview={false}
      />,
    );

    expect(screen.getByLabelText(/available from/i)).toBeInTheDocument();
    expect(screen.queryByLabelText(/days after enrolling/i)).not.toBeInTheDocument();
    expect(screen.queryByPlaceholderText(/the previous item/i)).not.toBeInTheDocument();
  });

  it('shows only the days field in by_days mode', () => {
    renderWithProviders(
      <DripFields
        mode="by_days"
        values={values}
        onChange={vi.fn()}
        siblings={siblings}
        isPreview={false}
      />,
    );

    expect(screen.getByLabelText(/days after enrolling/i)).toBeInTheDocument();
    expect(screen.queryByLabelText(/available from/i)).not.toBeInTheDocument();
  });

  it('offers the sibling picker in sequential mode', () => {
    renderWithProviders(
      <DripFields
        mode="sequential"
        values={values}
        onChange={vi.fn()}
        siblings={siblings}
        isPreview={false}
      />,
    );

    // Mantine's Select renders a label plus a hidden input, so the label text
    // matches twice; the description is unique to this field.
    expect(screen.getByText(/the item immediately before this one/i)).toBeInTheDocument();
    expect(screen.getByPlaceholderText(/the previous item/i)).toBeInTheDocument();
  });

  /*
   * Previews are never dripped server-side, so an author setting a date on one
   * would be configuring something with no effect.
   */
  it('warns that a free preview is never dripped', () => {
    renderWithProviders(
      <DripFields
        mode="by_date"
        values={values}
        onChange={vi.fn()}
        siblings={siblings}
        isPreview
      />,
    );

    expect(screen.getByText(/never dripped/i)).toBeInTheDocument();
  });
});

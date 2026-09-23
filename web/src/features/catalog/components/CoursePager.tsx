import { Group, Pagination, useMatches } from '@mantine/core';

import { t } from '@/shared/i18n';
import { formatNumber } from '@/shared/lib/number';

// A function: called during render, after the reader's catalogue has arrived.
const controlLabels = () =>
  ({
    first: t('catalog.pager.first', 'First page'),
    previous: t('catalog.pager.previous', 'Previous page'),
    next: t('catalog.pager.next', 'Next page'),
    last: t('catalog.pager.last', 'Last page'),
  }) as const;

export interface CoursePagerProps {
  /** The page ASKED for — while the next one loads, the data still holds the last. */
  page: number;
  total: number;
  onChange: (page: number) => void;
}

/** The pager under a course grid — the members catalogue and the public list. */
export function CoursePager({ page, total, onChange }: CoursePagerProps) {
  // At 360px "< 1 2 3 4 5 … 40 >" wraps onto a second line; one neighbour
  // each side of the current page fits only from `xs` up.
  const siblings = useMatches({ base: 0, xs: 1 });

  if (total <= 1) return null;

  const labels = controlLabels();

  return (
    <Group justify="center">
      <Pagination
        value={page}
        onChange={onChange}
        total={total}
        siblings={siblings}
        getControlProps={(control) => ({ 'aria-label': labels[control] })}
        getItemProps={(item) => ({
          'aria-label': t('catalog.pager.page', 'Page {page}', { page: formatNumber(item) }),
        })}
      />
    </Group>
  );
}

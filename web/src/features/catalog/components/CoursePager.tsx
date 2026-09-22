import { Group, Pagination, useMatches } from '@mantine/core';

const CONTROL_LABELS = {
  first: 'First page',
  previous: 'Previous page',
  next: 'Next page',
  last: 'Last page',
} as const;

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

  return (
    <Group justify="center">
      <Pagination
        value={page}
        onChange={onChange}
        total={total}
        siblings={siblings}
        getControlProps={(control) => ({ 'aria-label': CONTROL_LABELS[control] })}
        getItemProps={(item) => ({ 'aria-label': `Page ${item}` })}
      />
    </Group>
  );
}

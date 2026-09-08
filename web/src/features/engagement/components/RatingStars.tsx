import { Group, Rating, Text } from '@mantine/core';

/**
 * A rating, shown or set.
 *
 * Integers only, in both directions. A half-star average is fine to DISPLAY —
 * and `RatingDisplay` rounds to one decimal — but a half-star INPUT would make
 * the stored column's meaning ambiguous, which is why the API validates
 * `integer|min:1|max:5`.
 */
export function RatingInput({
  value,
  onChange,
  'aria-label': ariaLabel = 'Your rating',
}: {
  value: number;
  onChange: (value: number) => void;
  'aria-label'?: string;
}) {
  return <Rating value={value} onChange={onChange} count={5} size="lg" aria-label={ariaLabel} />;
}

export function RatingDisplay({
  value,
  count,
  size = 'sm',
}: {
  value: number;
  count?: number;
  size?: 'xs' | 'sm' | 'md';
}) {
  return (
    <Group gap={6} wrap="nowrap">
      <Rating value={value} count={5} readOnly size={size} fractions={2} />
      <Text size={size} c="dimmed">
        {value.toFixed(1)}
        {count === undefined ? null : ` (${count})`}
      </Text>
    </Group>
  );
}

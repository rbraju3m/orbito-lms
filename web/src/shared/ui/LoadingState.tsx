import { Skeleton, Stack } from '@mantine/core';

export interface LoadingStateProps {
  /** Number of skeleton rows to draw. Match the shape of the real content. */
  rows?: number;
  height?: number;
  label?: string;
}

/**
 * Skeletons, not spinners. A spinner tells the user nothing about what is
 * coming; a skeleton shaped like the content prevents the layout shift too.
 */
export function LoadingState({ rows = 3, height = 64, label = 'Loading' }: LoadingStateProps) {
  return (
    <Stack gap="sm" role="status" aria-busy="true" aria-label={label}>
      {Array.from({ length: rows }, (_, index) => (
        <Skeleton key={index} height={height} radius="md" />
      ))}
    </Stack>
  );
}

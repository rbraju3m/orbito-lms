import { SegmentedControl } from '@mantine/core';

import { RANGE_PRESETS, type RangePreset } from '../lib/range';

export function RangePicker({
  value,
  onChange,
}: {
  value: RangePreset;
  onChange: (value: RangePreset) => void;
}) {
  return (
    <SegmentedControl
      size="xs"
      value={value}
      onChange={(next) => onChange(next as RangePreset)}
      data={RANGE_PRESETS.map((preset) => ({ value: preset.value, label: preset.label }))}
      aria-label="Date range"
    />
  );
}

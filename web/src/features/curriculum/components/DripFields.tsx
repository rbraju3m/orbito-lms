import { Alert, NumberInput, Select, Stack, Text, TextInput } from '@mantine/core';
import { IconInfoCircle } from '@tabler/icons-react';

import type { DripMode } from '@/features/catalog/api/types';
import { fromLocalInputValue, toLocalInputValue } from '@/shared/lib/datetime';
import { optionalNumberValue } from '@/shared/lib/numberValue';

import type { CourseItem } from '../api/types';

export interface DripValues {
  drip_available_at: string | null;
  drip_after_days: number | null;
  drip_after_item_id: number | null;
}

interface DripFieldsProps {
  mode: DripMode;
  values: DripValues;
  onChange: (patch: Partial<DripValues>) => void;
  /** Every other item in the course, for the "after this one" picker. */
  siblings: CourseItem[];
  isPreview: boolean;
}

/**
 * Only the field the course's drip mode actually reads is shown.
 *
 * All three are still STORED whatever the mode, so an author can try
 * sequential, go back to by-date, and lose nothing — but showing three inputs
 * when two are inert invites filling them in and wondering why nothing
 * happened.
 */
export function DripFields({ mode, values, onChange, siblings, isPreview }: DripFieldsProps) {
  if (mode === 'none') {
    return (
      <Alert color="gray" icon={<IconInfoCircle size={16} />} variant="light">
        <Text size="sm">
          This course releases everything immediately. Turn on drip in the course’s access settings
          to schedule items.
        </Text>
      </Alert>
    );
  }

  return (
    <Stack gap="sm">
      {isPreview && (
        <Alert color="yellow" icon={<IconInfoCircle size={16} />} variant="light">
          <Text size="sm">
            Free previews are never dripped — this item stays open whatever you set here.
          </Text>
        </Alert>
      )}

      {mode === 'by_date' && (
        <TextInput
          type="datetime-local"
          label="Available from"
          description="The same date for every learner. Leave empty to release immediately."
          value={toLocalInputValue(values.drip_available_at)}
          onChange={(event) =>
            onChange({ drip_available_at: fromLocalInputValue(event.currentTarget.value) })
          }
        />
      )}

      {mode === 'by_days' && (
        <NumberInput
          label="Days after enrolling"
          description="Counted from when the learner's access starts, not from today."
          min={0}
          max={3650}
          value={values.drip_after_days ?? ''}
          onChange={(value) => onChange({ drip_after_days: optionalNumberValue(value) })}
        />
      )}

      {mode === 'sequential' && (
        <Select
          label="Unlocks after"
          description="Leave empty to use the item immediately before this one."
          placeholder="The previous item"
          clearable
          searchable
          data={siblings.map((sibling) => ({
            value: String(sibling.ref),
            label: sibling.title,
          }))}
          value={values.drip_after_item_id === null ? null : String(values.drip_after_item_id)}
          onChange={(value) =>
            onChange({ drip_after_item_id: value === null ? null : Number(value) })
          }
        />
      )}
    </Stack>
  );
}

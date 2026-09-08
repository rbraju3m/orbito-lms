import { Group, Text } from '@mantine/core';

import { formatMinor } from '@/shared/lib/money';

/**
 * A price, with the original struck through during a sale.
 *
 * The old figure is rendered from `list_amount_minor` rather than computed,
 * so a sale that has expired cannot leave a phantom discount on the page.
 */
export function PriceTag({
  amountMinor,
  currency,
  listAmountMinor = null,
  size = 'xl',
}: {
  amountMinor: number;
  currency: string;
  listAmountMinor?: number | null;
  size?: string;
}) {
  const onSale = listAmountMinor !== null && listAmountMinor > amountMinor;

  return (
    <Group gap="xs" align="baseline" wrap="nowrap">
      <Text fw={700} size={size}>
        {amountMinor === 0 ? 'Free' : formatMinor(amountMinor, currency)}
      </Text>
      {onSale && (
        <Text size="sm" c="dimmed" td="line-through">
          <span className="sr-only">Usual price </span>
          {formatMinor(listAmountMinor, currency)}
        </Text>
      )}
    </Group>
  );
}

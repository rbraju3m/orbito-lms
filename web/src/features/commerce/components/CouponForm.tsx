import {
  Alert,
  Button,
  Group,
  MultiSelect,
  NumberInput,
  SegmentedControl,
  SimpleGrid,
  Stack,
  Switch,
  Text,
  TextInput,
} from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';

import { applyServerErrors } from '@/shared/lib/form';
import { optionalNumberValue } from '@/shared/lib/numberValue';

import { couponProductsQuery, useSaveCoupon, type Coupon } from '../api/coupons';
import { couponSchema, type CouponValues } from '../couponSchema';
import { couponFormDefaults, toCouponInput } from '../lib/coupons';

const FIELDS = [
  'code',
  'description',
  'discount_type',
  'percent_off',
  'currency',
  'applies_to_all',
  'product_ids',
  'max_redemptions',
  'max_redemptions_per_user',
  'starts_at',
  'ends_at',
  'is_active',
] as const;

export interface CouponFormProps {
  /** Null creates one. */
  coupon: Coupon | null;
  onDone: () => void;
}

/**
 * The whole coupon, for create and edit alike — saving replaces it. Money is
 * typed in major units and converted once, on the way out (`toCouponInput`).
 */
export function CouponForm({ coupon, onDone }: CouponFormProps) {
  const save = useSaveCoupon(coupon?.id ?? null);
  const products = useQuery(couponProductsQuery(''));
  const [formError, setFormError] = useState<string | null>(null);

  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<CouponValues>({
    resolver: zodResolver(couponSchema),
    defaultValues: couponFormDefaults(coupon),
  });

  // useWatch, not watch(): the compiler cannot memoise around watch().
  const type = useWatch({ control, name: 'discount_type' });
  const appliesToAll = useWatch({ control, name: 'applies_to_all' });

  const submit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      await save.mutateAsync(toCouponInput(values));
      onDone();
    } catch (error) {
      setFormError(applyServerErrors(error, setError, FIELDS));
    }
  });

  // Everything for sale, plus whatever this coupon already names — a product
  // taken off sale since must still show, or saving would silently drop it.
  const productOptions = [
    ...(products.data?.data ?? []).map((product) => ({ value: product.id, label: product.title })),
    ...(coupon?.products ?? [])
      .filter((named) => !(products.data?.data ?? []).some((product) => product.id === named.id))
      .map((named) => ({ value: named.id, label: named.title })),
  ];

  return (
    <form onSubmit={submit} noValidate>
      <Stack gap="md">
        {formError ? (
          <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="alert">
            {formError}
          </Alert>
        ) : null}

        <SimpleGrid cols={{ base: 1, sm: 2 }}>
          <TextInput
            label="Code"
            description="What learners type. Case does not matter."
            required
            autoCapitalize="characters"
            {...register('code')}
            error={errors.code?.message}
          />
          <TextInput
            label="Description"
            description="Optional — shown in the basket."
            {...register('description')}
            error={errors.description?.message}
          />
        </SimpleGrid>

        <Stack gap={4}>
          <Text size="sm" fw={500} id="discount-type-label">
            Discount
          </Text>
          <Controller
            control={control}
            name="discount_type"
            render={({ field }) => (
              <SegmentedControl
                aria-labelledby="discount-type-label"
                value={field.value}
                onChange={(value) => field.onChange(value)}
                data={[
                  { label: 'Percentage', value: 'percent' },
                  { label: 'Fixed amount', value: 'fixed' },
                ]}
              />
            )}
          />
        </Stack>

        <SimpleGrid cols={{ base: 1, sm: 2 }}>
          {type === 'percent' ? (
            <Controller
              control={control}
              name="percent_off"
              render={({ field, fieldState }) => (
                <NumberInput
                  label="Percent off"
                  suffix="%"
                  min={1}
                  max={100}
                  allowDecimal={false}
                  value={field.value ?? ''}
                  onChange={(value) => field.onChange(optionalNumberValue(value))}
                  error={fieldState.error?.message}
                />
              )}
            />
          ) : (
            <Controller
              control={control}
              name="amount_off"
              render={({ field, fieldState }) => (
                <NumberInput
                  label="Amount off"
                  min={0}
                  decimalScale={2}
                  value={field.value ?? ''}
                  onChange={(value) => field.onChange(optionalNumberValue(value))}
                  error={fieldState.error?.message}
                />
              )}
            />
          )}
          <TextInput
            label="Currency"
            description="Needed for a fixed amount or a minimum spend."
            placeholder="BDT"
            maxLength={3}
            {...register('currency')}
            error={errors.currency?.message}
          />
        </SimpleGrid>

        <Controller
          control={control}
          name="applies_to_all"
          render={({ field }) => (
            <Switch
              label="Applies to everything"
              description="Switch off to choose which courses, bundles or downloads it covers."
              checked={field.value}
              onChange={(event) => field.onChange(event.currentTarget.checked)}
            />
          )}
        />

        {!appliesToAll ? (
          <Controller
            control={control}
            name="product_ids"
            render={({ field, fieldState }) => (
              <MultiSelect
                label="Products"
                placeholder={products.isPending ? 'Loading…' : 'Search what you sell'}
                searchable
                nothingFoundMessage="Nothing for sale matches"
                data={productOptions}
                value={field.value}
                onChange={field.onChange}
                error={fieldState.error?.message}
              />
            )}
          />
        ) : null}

        <SimpleGrid cols={{ base: 1, sm: 3 }}>
          <Controller
            control={control}
            name="min_subtotal"
            render={({ field, fieldState }) => (
              <NumberInput
                label="Minimum spend"
                description="On what it applies to."
                min={0}
                decimalScale={2}
                value={field.value ?? ''}
                onChange={(value) => field.onChange(optionalNumberValue(value))}
                error={fieldState.error?.message}
              />
            )}
          />
          <Controller
            control={control}
            name="max_redemptions"
            render={({ field, fieldState }) => (
              <NumberInput
                label="Total uses"
                description="Blank for no limit."
                min={1}
                allowDecimal={false}
                value={field.value ?? ''}
                onChange={(value) => field.onChange(optionalNumberValue(value))}
                error={fieldState.error?.message}
              />
            )}
          />
          <Controller
            control={control}
            name="max_redemptions_per_user"
            render={({ field, fieldState }) => (
              <NumberInput
                label="Uses per person"
                description="Blank for no limit."
                min={1}
                allowDecimal={false}
                value={field.value ?? ''}
                onChange={(value) => field.onChange(optionalNumberValue(value))}
                error={fieldState.error?.message}
              />
            )}
          />
        </SimpleGrid>

        <SimpleGrid cols={{ base: 1, sm: 2 }}>
          <TextInput type="datetime-local" label="Starts" {...register('starts_at')} error={errors.starts_at?.message} />
          <TextInput type="datetime-local" label="Ends" {...register('ends_at')} error={errors.ends_at?.message} />
        </SimpleGrid>

        <Controller
          control={control}
          name="is_active"
          render={({ field }) => (
            <Switch
              label="Active"
              description="A switched-off coupon is refused at the basket and at checkout."
              checked={field.value}
              onChange={(event) => field.onChange(event.currentTarget.checked)}
            />
          )}
        />

        <Group justify="flex-end">
          <Button variant="subtle" onClick={onDone}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending}>
            Save coupon
          </Button>
        </Group>
      </Stack>
    </form>
  );
}

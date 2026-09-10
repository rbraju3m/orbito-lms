import { Button } from '@mantine/core';
import { IconDownload } from '@tabler/icons-react';

import { useAddToCart } from '@/features/commerce/api/queries';

import { useClaimDownload, useFetchDownload } from '../api/queries';
import type { Download } from '../api/types';

/**
 * The one button a download page needs, chosen from what the SERVER said:
 * `can_fetch` comes from `DownloadAccess`, the class the fetch endpoint asks,
 * so this can never offer a download that would 423.
 */
export function DownloadAction({ download }: { download: Download }) {
  const fetchFile = useFetchDownload();
  const claim = useClaimDownload(download.slug);
  const addToCart = useAddToCart();

  if (download.can_fetch) {
    return (
      <Button
        leftSection={<IconDownload size={16} />}
        loading={fetchFile.isPending}
        onClick={() => fetchFile.mutate(download.slug)}
      >
        Download
      </Button>
    );
  }

  if (download.is_free) {
    return (
      <Button loading={claim.isPending} onClick={() => claim.mutate()}>
        Get it free
      </Button>
    );
  }

  const price = download.price;

  return (
    <Button
      disabled={!price}
      loading={addToCart.isPending}
      onClick={() => price && addToCart.mutate(price.product_id)}
    >
      Add to basket
    </Button>
  );
}

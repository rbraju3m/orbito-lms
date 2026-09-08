import { Badge } from '@mantine/core';

import type { Certificate } from '../api/types';

/**
 * Three states, three colours, because a checker must be able to tell them
 * apart at a glance:
 *
 *  - valid: the qualification stands.
 *  - expired: it was genuinely earned and has lapsed. Grey, not red — an
 *    honest holder must not be coloured like a forger.
 *  - revoked: the academy withdrew it. Red, because that is the one that
 *    means the claim is bad.
 */
export function CertificateStatusBadge({
  certificate,
}: {
  certificate: Pick<Certificate, 'is_valid' | 'has_expired' | 'status'>;
}) {
  if (certificate.status === 'revoked') {
    return (
      <Badge color="red" variant="light">
        Revoked
      </Badge>
    );
  }

  if (certificate.has_expired) {
    return (
      <Badge color="gray" variant="light">
        Expired
      </Badge>
    );
  }

  return (
    <Badge color="green" variant="light">
      Valid
    </Badge>
  );
}

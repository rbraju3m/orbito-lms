import {
  Alert,
  Card,
  Center,
  Container,
  Divider,
  Group,
  Loader,
  Stack,
  Text,
  Title,
} from '@mantine/core';
import {
  IconAlertTriangle,
  IconCircleCheckFilled,
  IconCircleX,
  IconClockExclamation,
} from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router';

import { ApiError } from '@/shared/api/errors';
import { formatDate } from '@/shared/lib/datetime';

import { verificationQuery } from '../api/queries';
import type { CertificateVerification } from '../api/types';

/**
 * The public verification page.
 *
 * Its reader is a stranger — a hiring manager with a printed certificate and
 * no account — so it is written for someone who has never seen this product
 * and wants one question answered: is this claim true?
 *
 * The answer is the FIRST thing on the page, in words, before any detail.
 * Three outcomes, deliberately distinguished:
 *
 *  - valid — the qualification stands.
 *  - expired — genuinely earned, since lapsed. NOT an error, and not coloured
 *    like one; treating it as a failure would make an honest holder look like
 *    a forger.
 *  - revoked — the academy withdrew it. This is the one that means the claim
 *    is bad, and it says so plainly.
 *
 * A 404 says "we have no record", never "invalid" — those are different
 * claims, and only one of them is something we know.
 */
export function VerifyRoute() {
  const { tenant = '', token = '' } = useParams();
  const { data, isPending, isError, error } = useQuery(verificationQuery(tenant, token));

  if (isPending) {
    return (
      <Center h="60vh">
        <Loader aria-label="Checking this certificate" />
      </Center>
    );
  }

  if (isError) {
    const notFound = error instanceof ApiError && error.status === 404;

    return (
      <Container size="sm" py="xl">
        <Card withBorder padding="xl">
          <Stack align="center" gap="sm" ta="center">
            <IconAlertTriangle size={44} color="var(--mantine-color-yellow-6)" />
            <Title order={2}>
              {notFound ? 'No record of this certificate' : 'Could not check this certificate'}
            </Title>
            <Text c="dimmed">
              {notFound
                ? 'We have no certificate with this reference. Check the link was copied in full — it may have been shortened or cut off.'
                : 'Something went wrong on our side. Please try again in a moment.'}
            </Text>
            {/*
             * Never "this certificate is fake". We know we have no record;
             * we do not know what the paper in their hand is.
             */}
          </Stack>
        </Card>
      </Container>
    );
  }

  return (
    <Container size="sm" py="xl">
      <Verdict certificate={data} />

      <Card withBorder mt="md" padding="lg">
        <Stack gap="sm">
          <Field label="Awarded to" value={data.learner_name} strong />
          <Divider />
          <Field label="For completing" value={data.course_title} strong />
          <Divider />
          <Field label="Issued by" value={data.academy_name} />
          <Divider />
          <Field label="Certificate number" value={data.number} mono />
          <Divider />
          <Field label="Issued on" value={formatDate(data.issued_at)} />
          {data.completed_at && (
            <>
              <Divider />
              <Field label="Completed on" value={formatDate(data.completed_at)} />
            </>
          )}
          {data.expires_at && (
            <>
              <Divider />
              <Field label="Valid until" value={formatDate(data.expires_at)} />
            </>
          )}
        </Stack>
      </Card>

      <Text size="xs" c="dimmed" ta="center" mt="md">
        This page is the authoritative record. A printed or downloaded copy is not.
      </Text>
    </Container>
  );
}

function Verdict({ certificate }: { certificate: CertificateVerification }) {
  if (certificate.is_revoked) {
    return (
      <Alert color="red" icon={<IconCircleX size={20} />} title="This certificate was withdrawn">
        The academy that issued it has revoked it
        {certificate.revoked_at ? ` on ${formatDate(certificate.revoked_at)}` : ''}. It should not
        be relied on.
      </Alert>
    );
  }

  if (certificate.has_expired) {
    return (
      <Alert
        color="yellow"
        icon={<IconClockExclamation size={20} />}
        title="This certificate has expired"
      >
        It was genuinely awarded and has since lapsed
        {certificate.expires_at ? ` on ${formatDate(certificate.expires_at)}` : ''}. The achievement
        below is real; the certificate is no longer current.
      </Alert>
    );
  }

  return (
    <Alert
      color="green"
      icon={<IconCircleCheckFilled size={20} />}
      title="This certificate is valid"
    >
      It was issued by the academy named below and has not been withdrawn.
    </Alert>
  );
}

function Field({
  label,
  value,
  strong = false,
  mono = false,
}: {
  label: string;
  value: string | null;
  strong?: boolean;
  mono?: boolean;
}) {
  return (
    <Group justify="space-between" align="flex-start" gap="md" wrap="nowrap">
      <Text size="sm" c="dimmed" style={{ whiteSpace: 'nowrap' }}>
        {label}
      </Text>
      <Text
        fw={strong ? 600 : 400}
        size={strong ? 'lg' : 'sm'}
        ta="right"
        ff={mono ? 'monospace' : undefined}
      >
        {value ?? '—'}
      </Text>
    </Group>
  );
}

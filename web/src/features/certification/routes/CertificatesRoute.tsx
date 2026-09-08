import { Button, Card, CopyButton, Group, Pagination, Stack, Text, Tooltip } from '@mantine/core';
import { IconCertificate, IconCheck, IconCopy, IconDownload } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router';

import { formatDate } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { CertificateStatusBadge } from '../components/CertificateStatusBadge';
import { certificatesQuery, useCertificateDownload } from '../api/queries';
import type { Certificate } from '../api/types';

/**
 * The holder's certificates.
 *
 * The primary action is COPYING THE LINK, not downloading. A certificate is
 * useful when somebody else can check it, and the verification URL is the
 * thing that proves the claim — the PDF is a copy anyone could have made.
 */
export function CertificatesRoute() {
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const { data, isPending, isError, error, refetch } = useQuery(certificatesQuery(page));

  if (isPending) return <LoadingState rows={3} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (data.data.length === 0) {
    return (
      <>
        <PageHeader title="Certificates" />
        <EmptyState
          icon={IconCertificate}
          title="No certificates yet"
          description="Finish a course that awards one and it will appear here."
          action={{ label: 'My learning', onClick: () => void navigate('/dashboard/courses') }}
        />
      </>
    );
  }

  return (
    <>
      <PageHeader title="Certificates" description="Everything you have earned." />

      <Stack gap="sm">
        {data.data.map((certificate) => (
          <CertificateRow key={certificate.id} certificate={certificate} />
        ))}

        {data.meta.last_page > 1 && (
          <Group justify="center" mt="md">
            <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
          </Group>
        )}
      </Stack>
    </>
  );
}

function CertificateRow({ certificate }: { certificate: Certificate }) {
  const download = useCertificateDownload();

  return (
    <Card withBorder>
      <Group justify="space-between" wrap="nowrap" gap="md" align="flex-start">
        <Stack gap={4} style={{ minWidth: 0 }}>
          <Group gap="xs">
            <Text fw={600} truncate>
              {certificate.course_title ?? 'Course'}
            </Text>
            <CertificateStatusBadge certificate={certificate} />
          </Group>

          <Text size="sm" c="dimmed">
            {certificate.number} · issued {formatDate(certificate.issued_at)}
          </Text>

          {/*
           * Said plainly rather than hidden. A revoked certificate the holder
           * cannot see the status of is a nasty surprise at an interview.
           */}
          {certificate.status === 'revoked' && certificate.revoked_reason && (
            <Text size="sm" c="red.7">
              {certificate.revoked_reason}
            </Text>
          )}
        </Stack>

        <Group gap="xs" wrap="nowrap">
          <CopyButton value={certificate.verification_url} timeout={2000}>
            {({ copied, copy }) => (
              <Tooltip label={copied ? 'Link copied' : 'Copy verification link'} withArrow>
                <Button
                  variant={copied ? 'filled' : 'light'}
                  color={copied ? 'green' : undefined}
                  size="compact-sm"
                  onClick={copy}
                  leftSection={copied ? <IconCheck size={14} /> : <IconCopy size={14} />}
                >
                  {copied ? 'Copied' : 'Share'}
                </Button>
              </Tooltip>
            )}
          </CopyButton>

          {/*
           * Absent, not disabled, while the render is queued. A greyed-out
           * button with no explanation is the dead end this codebase keeps
           * refusing; the certificate itself is already valid.
           */}
          {certificate.has_pdf ? (
            <Button
              variant="subtle"
              size="compact-sm"
              loading={download.isPending && download.variables === certificate.id}
              leftSection={<IconDownload size={14} />}
              onClick={() =>
                download.mutate(certificate.id, {
                  // The URL is short-lived, so it is used the instant it
                  // arrives rather than held.
                  onSuccess: ({ url }) => window.open(url, '_blank', 'noopener'),
                })
              }
            >
              PDF
            </Button>
          ) : (
            <Text size="xs" c="dimmed" style={{ whiteSpace: 'nowrap' }}>
              PDF preparing…
            </Text>
          )}
        </Group>
      </Group>
    </Card>
  );
}

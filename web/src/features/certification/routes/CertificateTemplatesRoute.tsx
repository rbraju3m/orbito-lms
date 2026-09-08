import {
  Alert,
  Badge,
  Button,
  Card,
  Group,
  Modal,
  Select,
  Stack,
  Switch,
  Text,
  Textarea,
  TextInput,
} from '@mantine/core';
import { IconAlertTriangle, IconCertificate, IconPlus } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { ApiError } from '@/shared/api/errors';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { certificateTemplatesQuery, useDeleteTemplate, useSaveTemplate } from '../api/queries';
import type { CertificateTemplate } from '../api/types';

const DEFAULT_LAYOUT = {
  heading: 'Certificate of Completion',
  body: 'This is to certify that {learner_name} has successfully completed {course_title}.',
  signature_name: '',
  signature_title: '',
  accent_colour: '#1c7ed6',
  show_score: false,
  show_qr: true,
};

/**
 * Certificate designs.
 *
 * Editing one does NOT restyle certificates already issued — they carry their
 * own snapshot and their PDF is already rendered. That is said on the screen,
 * because an admin reasonably assumes the opposite.
 */
export function CertificateTemplatesRoute() {
  const { data, isPending, isError, error, refetch } = useQuery(certificateTemplatesQuery());
  const [editing, setEditing] = useState<CertificateTemplate | 'new' | null>(null);

  if (isPending) return <LoadingState rows={2} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return (
    <>
      <PageHeader
        title="Certificate templates"
        description="How your certificates look. Changes apply to certificates issued from now on."
        actions={
          <Button leftSection={<IconPlus size={16} />} onClick={() => setEditing('new')}>
            New template
          </Button>
        }
      />

      {data.length === 0 ? (
        <EmptyState
          icon={IconCertificate}
          title="No templates yet"
          description="Certificates still issue without one — they use a plain default design."
          action={{ label: 'Create a template', onClick: () => setEditing('new') }}
        />
      ) : (
        <Stack gap="sm">
          {data.map((template) => (
            <TemplateCard
              key={template.id}
              template={template}
              onEdit={() => setEditing(template)}
            />
          ))}
        </Stack>
      )}

      {editing !== null && (
        <TemplateModal
          template={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
        />
      )}
    </>
  );
}

function TemplateCard({ template, onEdit }: { template: CertificateTemplate; onEdit: () => void }) {
  const remove = useDeleteTemplate();

  return (
    <Card withBorder>
      <Group justify="space-between" wrap="nowrap" gap="md">
        <Stack gap={4} style={{ minWidth: 0 }}>
          <Group gap="xs">
            <Text fw={600}>{template.name}</Text>
            {template.is_default && (
              <Badge color="blue" variant="light">
                Default
              </Badge>
            )}
            {!template.is_active && (
              <Badge color="gray" variant="outline">
                Inactive
              </Badge>
            )}
          </Group>
          <Text size="sm" c="dimmed">
            {template.orientation_label}
          </Text>
        </Stack>

        <Group gap="xs" wrap="nowrap">
          <Button variant="light" size="compact-sm" onClick={onEdit}>
            Edit
          </Button>
          <Button
            variant="subtle"
            color="red"
            size="compact-sm"
            loading={remove.isPending && remove.variables === template.id}
            onClick={() => remove.mutate(template.id)}
          >
            Delete
          </Button>
        </Group>
      </Group>
    </Card>
  );
}

function TemplateModal({
  template,
  onClose,
}: {
  template: CertificateTemplate | null;
  onClose: () => void;
}) {
  const save = useSaveTemplate();
  const [name, setName] = useState(template?.name ?? '');
  const [orientation, setOrientation] = useState<'landscape' | 'portrait'>(
    template?.orientation ?? 'landscape',
  );
  const [layout, setLayout] = useState({ ...DEFAULT_LAYOUT, ...(template?.layout ?? {}) });
  const [isDefault, setIsDefault] = useState(template?.is_default ?? false);
  const [isActive, setIsActive] = useState(template?.is_active ?? true);

  const error = save.error instanceof ApiError ? save.error : null;

  return (
    <Modal
      opened
      onClose={onClose}
      title={template ? `Edit ${template.name}` : 'New template'}
      size="lg"
      centered
    >
      <Stack gap="md">
        <Alert color="gray" icon={<IconCertificate size={16} />}>
          Certificates already issued keep the design they were made with. Editing this affects
          future ones only.
        </Alert>

        <TextInput
          label="Template name"
          description="For your own reference. It does not appear on the certificate."
          value={name}
          onChange={(event) => setName(event.currentTarget.value)}
          required
        />

        <Select
          label="Orientation"
          data={[
            { value: 'landscape', label: 'Landscape' },
            { value: 'portrait', label: 'Portrait' },
          ]}
          value={orientation}
          onChange={(value) => setOrientation((value as 'landscape' | 'portrait') ?? 'landscape')}
          allowDeselect={false}
        />

        <TextInput
          label="Heading"
          value={layout.heading}
          onChange={(event) => setLayout({ ...layout, heading: event.currentTarget.value })}
        />

        <Textarea
          label="Body"
          description="{learner_name} and {course_title} are replaced when the certificate is made."
          autosize
          minRows={3}
          value={layout.body}
          onChange={(event) => setLayout({ ...layout, body: event.currentTarget.value })}
        />

        <Group grow>
          <TextInput
            label="Signature name"
            value={layout.signature_name}
            onChange={(event) =>
              setLayout({ ...layout, signature_name: event.currentTarget.value })
            }
          />
          <TextInput
            label="Signature title"
            value={layout.signature_title}
            onChange={(event) =>
              setLayout({ ...layout, signature_title: event.currentTarget.value })
            }
          />
        </Group>

        {/*
         * A NATIVE colour input rather than Mantine's ColorInput. That
         * component drags the whole colour-picker tree — picker, swatches,
         * parsers — into the SHARED Mantine chunk for one admin field, and
         * that chunk is on the first-paint path with 0.3KB of headroom
         * against the 250KB budget. The browser already has this control.
         *
         * The server re-validates the value against a hex pattern regardless:
         * a template field reaching the stylesheet unchecked would be a CSS
         * injection into every certificate the academy issues.
         */}
        <TextInput
          label="Accent colour"
          type="color"
          value={layout.accent_colour}
          onChange={(event) => setLayout({ ...layout, accent_colour: event.currentTarget.value })}
        />

        <Switch
          label="Make this the default template"
          description="New certificates use the default. There can only be one."
          checked={isDefault}
          onChange={(event) => setIsDefault(event.currentTarget.checked)}
        />

        <Switch
          label="Active"
          checked={isActive}
          onChange={(event) => setIsActive(event.currentTarget.checked)}
        />

        {error && (
          <Alert color="red" icon={<IconAlertTriangle size={16} />}>
            {error.message}
          </Alert>
        )}

        <Group justify="flex-end">
          <Button variant="subtle" onClick={onClose}>
            Cancel
          </Button>
          <Button
            loading={save.isPending}
            disabled={name.trim() === ''}
            onClick={() =>
              save.mutate(
                {
                  ...(template ? { id: template.id } : {}),
                  name,
                  orientation,
                  layout,
                  is_default: isDefault,
                  is_active: isActive,
                },
                { onSuccess: onClose },
              )
            }
          >
            Save
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}

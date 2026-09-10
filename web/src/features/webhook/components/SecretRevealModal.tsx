import { ActionIcon, Alert, Button, CopyButton, Group, Modal, Stack, Text, TextInput } from '@mantine/core';
import { IconAlertTriangle, IconCheck, IconCopy } from '@tabler/icons-react';

export interface SecretRevealModalProps {
  /** Null keeps the modal closed. */
  secret: string | null;
  onClose: () => void;
}

/**
 * The one moment a signing secret is visible.
 *
 * The API never reads it back, so this cannot be dismissed by a stray click
 * or Escape — closing it is a deliberate "I have saved it". Losing it means
 * rotating, which breaks the receiver until it is updated.
 */
export function SecretRevealModal({ secret, onClose }: SecretRevealModalProps) {
  return (
    <Modal
      opened={secret !== null}
      onClose={onClose}
      title="Save your signing secret"
      centered
      closeOnClickOutside={false}
      closeOnEscape={false}
      withCloseButton={false}
    >
      <Stack gap="sm">
        <Alert color="warning" icon={<IconAlertTriangle size={16} />} role="note">
          This is the only time it is shown. If you lose it, rotate the secret and update your
          receiver.
        </Alert>

        <TextInput
          label="Signing secret"
          value={secret ?? ''}
          readOnly
          styles={{ input: { fontFamily: 'var(--mantine-font-family-monospace)' } }}
          rightSection={
            <CopyButton value={secret ?? ''}>
              {({ copied, copy }) => (
                <ActionIcon
                  variant="subtle"
                  color={copied ? 'green' : 'gray'}
                  aria-label={copied ? 'Copied' : 'Copy secret'}
                  onClick={copy}
                >
                  {copied ? <IconCheck size={16} /> : <IconCopy size={16} />}
                </ActionIcon>
              )}
            </CopyButton>
          }
        />

        <Text size="sm" c="dimmed">
          Your receiver uses it to check the <code>Orbito-Signature</code> header on every delivery.
        </Text>

        <Group justify="flex-end">
          <Button onClick={onClose}>I have saved it</Button>
        </Group>
      </Stack>
    </Modal>
  );
}

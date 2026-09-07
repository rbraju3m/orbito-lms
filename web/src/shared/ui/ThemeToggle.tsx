import { ActionIcon, Menu, useMantineColorScheme } from '@mantine/core';
import { IconDeviceDesktop, IconMoon, IconSun } from '@tabler/icons-react';

const OPTIONS = [
  { value: 'light', label: 'Light', Icon: IconSun },
  { value: 'dark', label: 'Dark', Icon: IconMoon },
  { value: 'auto', label: 'System', Icon: IconDeviceDesktop },
] as const;

/**
 * Light / dark / system. `auto` is the default so we follow the OS until the
 * user says otherwise. The choice is per-device; persisting it to the profile
 * so it follows a user across devices is not built.
 */
export function ThemeToggle() {
  const { colorScheme, setColorScheme } = useMantineColorScheme();
  const current = OPTIONS.find((option) => option.value === colorScheme) ?? OPTIONS[2];
  const CurrentIcon = current.Icon;

  return (
    <Menu position="bottom-end" width={160} withinPortal>
      <Menu.Target>
        <ActionIcon variant="default" size="lg" aria-label={`Colour scheme: ${current.label}`}>
          <CurrentIcon size={18} stroke={1.5} />
        </ActionIcon>
      </Menu.Target>

      <Menu.Dropdown>
        <Menu.Label>Appearance</Menu.Label>
        {OPTIONS.map(({ value, label, Icon }) => (
          <Menu.Item
            key={value}
            leftSection={<Icon size={16} stroke={1.5} />}
            onClick={() => setColorScheme(value)}
            aria-current={colorScheme === value}
          >
            {label}
          </Menu.Item>
        ))}
      </Menu.Dropdown>
    </Menu>
  );
}

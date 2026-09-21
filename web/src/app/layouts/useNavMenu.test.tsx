import { Burger } from '@mantine/core';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import { renderWithProviders } from '@/shared/test/render';
import { setTestViewportWidth } from '@/shared/test/setup';

import { useNavMenu } from './useNavMenu';

function Shell({ onDesktop }: { onDesktop: 'navbar' | 'header' }) {
  const { navbarInert, burgerProps } = useNavMenu({ onDesktop });

  return (
    <>
      <Burger {...burgerProps} />
      <nav inert={navbarInert}>
        <a href="/courses">Courses</a>
      </nav>
    </>
  );
}

// The testing library does not honour `inert`, so these assert the attribute:
// it is what keeps a browser's Tab key out of a navbar that is off-screen.
describe('useNavMenu', () => {
  it('keeps a desktop navbar reachable where the shell shows one', () => {
    renderWithProviders(<Shell onDesktop="navbar" />);

    expect(document.querySelector('nav')).not.toHaveAttribute('inert');
  });

  it('keeps it out of reach at desktop width where the links live in the header', () => {
    renderWithProviders(<Shell onDesktop="header" />);

    expect(document.querySelector('nav')).toHaveAttribute('inert');
  });

  it('lets a keyboard into a narrow screen menu only while it is open', async () => {
    setTestViewportWidth(360);
    const user = userEvent.setup();
    renderWithProviders(<Shell onDesktop="navbar" />);

    const burger = screen.getByRole('button', { name: 'Menu' });
    expect(document.querySelector('nav')).toHaveAttribute('inert');
    expect(burger).toHaveAttribute('aria-expanded', 'false');

    await user.click(burger);

    expect(document.querySelector('nav')).not.toHaveAttribute('inert');
    expect(burger).toHaveAttribute('aria-expanded', 'true');
  });

  it('closes on Escape and hands focus back to the burger', async () => {
    setTestViewportWidth(360);
    const user = userEvent.setup();
    renderWithProviders(<Shell onDesktop="header" />);

    await user.click(screen.getByRole('button', { name: 'Menu' }));
    screen.getByRole('link', { name: 'Courses' }).focus();

    await user.keyboard('{Escape}');

    const burger = screen.getByRole('button', { name: 'Menu' });
    expect(burger).toHaveAttribute('aria-expanded', 'false');
    expect(burger).toHaveFocus();
    expect(document.querySelector('nav')).toHaveAttribute('inert');
  });
});

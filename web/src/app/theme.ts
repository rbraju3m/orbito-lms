import { Button, Card, createTheme, Modal, type MantineColorsTuple } from '@mantine/core';

/**
 * Orbito design tokens, expressed as a Mantine theme.
 * See docs/DESIGN_SYSTEM.md — nothing in a feature component may hard-code a
 * colour, spacing value or font size; it all comes from here.
 */

const orbito: MantineColorsTuple = [
  '#eef2ff',
  '#dde3fd',
  '#b7c3f7',
  '#8ea1f2',
  '#6b84ed',
  '#5572eb',
  '#4968eb',
  '#3a58d1',
  '#314ebb',
  '#2543a5',
];

const success: MantineColorsTuple = [
  '#e6f9ef',
  '#d0f2e1',
  '#a2e4c3',
  '#71d5a3',
  '#4bc989',
  '#33c178',
  '#22bd6f',
  '#12a55e',
  '#029352',
  '#008044',
];

const warning: MantineColorsTuple = [
  '#fff8e1',
  '#ffefcc',
  '#ffdd9b',
  '#ffca64',
  '#ffba38',
  '#ffb01b',
  '#ffab09',
  '#e39500',
  '#ca8500',
  '#af7100',
];

const danger: MantineColorsTuple = [
  '#ffe9e9',
  '#ffd1d1',
  '#fba0a1',
  '#f76d6d',
  '#f34141',
  '#f22625',
  '#f21616',
  '#d8070b',
  '#c10008',
  '#a90003',
];

export const theme = createTheme({
  primaryColor: 'orbito',
  primaryShade: { light: 6, dark: 8 },

  colors: { orbito, success, warning, danger },

  // Bengali is a launch locale, so the stack must render it without falling
  // back to a serif face mid-sentence.
  fontFamily:
    '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans Bengali", "Helvetica Neue", Arial, sans-serif',
  fontFamilyMonospace: 'ui-monospace, SFMono-Regular, Menlo, monospace',

  headings: {
    fontWeight: '600',
    sizes: {
      h1: { fontSize: '1.875rem', lineHeight: '1.2' },
      h2: { fontSize: '1.5rem', lineHeight: '1.25' },
      h3: { fontSize: '1.25rem', lineHeight: '1.3' },
      h4: { fontSize: '1.0625rem', lineHeight: '1.4' },
    },
  },

  defaultRadius: 'md',

  radius: { xs: '4px', sm: '6px', md: '8px', lg: '12px', xl: '16px' },
  spacing: { xs: '4px', sm: '8px', md: '16px', lg: '24px', xl: '32px' },

  components: {
    Button: Button.extend({ defaultProps: { radius: 'sm' } }),
    Card: Card.extend({ defaultProps: { withBorder: true, radius: 'md', padding: 'lg' } }),
    Modal: Modal.extend({ defaultProps: { centered: true, radius: 'lg' } }),
  },

  other: {
    // Motion tokens; every animated component reads these so
    // prefers-reduced-motion can zero them in one place.
    transitionFast: 120,
    transitionBase: 200,
    transitionSlow: 320,
  },
});

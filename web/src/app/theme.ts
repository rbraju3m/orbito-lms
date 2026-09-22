import {
  Button,
  Card,
  createTheme,
  Modal,
  type CSSVariablesResolver,
  type MantineColorsTuple,
} from '@mantine/core';

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
    // Mantine's close button is an icon with no accessible name; axe flagged
    // it on every modal in the product.
    Modal: Modal.extend({
      defaultProps: { centered: true, radius: 'lg', closeButtonProps: { 'aria-label': 'Close' } },
    }),
  },

  other: {
    // Motion tokens; every animated component reads these so
    // prefers-reduced-motion can zero them in one place.
    transitionFast: 120,
    transitionBase: 200,
    transitionSlow: 320,
  },
});

/**
 * Mantine's defaults for four text colours fall short of the 4.5:1 that
 * docs/DESIGN_SYSTEM.md requires of body text, in both schemes. Each value
 * below is the ratio against the surface it sits on:
 *
 * - dimmed: gray.6 is 3.3:1 on white; dark.2 is 3.5:1 on a dark card.
 *   Now 5.0:1 on white and 5.4:1 on a card. Nothing between gray.6 and
 *   gray.7 exists in the scale, and gray.7 (8.2:1) no longer reads as dimmed.
 * - anchor, dark: orbito.4 is 4.0:1 on a card; orbito.3 is 5.5:1.
 * - success TEXT, light: success.6 is 2.4:1 on white; success.9 is 5.0:1.
 *   Text only — filled buttons and badges keep their own shade.
 * - gray OUTLINE, light: the "All levels" badge's text is gray.6, 3.3:1.
 *   gray.7 is 8.2:1, and the border it also draws is only firmer for it.
 */
export const cssVariablesResolver: CSSVariablesResolver = () => ({
  variables: {},
  light: {
    '--mantine-color-dimmed': '#687078',
    '--mantine-color-success-text': 'var(--mantine-color-success-9)',
    '--mantine-color-gray-outline': 'var(--mantine-color-gray-7)',
  },
  dark: {
    '--mantine-color-dimmed': '#a3a3a3',
    '--mantine-color-anchor': 'var(--mantine-color-orbito-3)',
  },
});

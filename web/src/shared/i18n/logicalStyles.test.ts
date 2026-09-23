import { describe, expect, it } from 'vitest';

/*
 * Left and right are not directions a right-to-left reader shares
 * (docs/I18N.md §3, I1). Styles say START and END — `ps`/`pe`,
 * `margin-inline-start`, `textAlign: 'end'` — so adding an RTL language is a
 * locale, not a hunt through every screen.
 *
 * oxlint has no rule for this, so the suite is the rule.
 */
const sources = import.meta.glob<string>(['/src/**/*.{ts,tsx,css}', '!/src/**/*.test.{ts,tsx}'], {
  query: '?raw',
  import: 'default',
  eager: true,
});

const PHYSICAL = {
  'Mantine ml/mr/pl/pr — use ms/me/ps/pe': /\s(ml|mr|pl|pr)=["{]/,
  'marginLeft, paddingRight… — use marginInlineStart, paddingInlineEnd…':
    /\b(margin|padding|border)(Left|Right)\b/,
  'margin-left, padding-right… — use margin-inline-start, padding-inline-end…':
    /\b(margin|padding|border)-(left|right)\b/,
  'left: / right: — use inset-inline-start / inset-inline-end':
    /(^|[\s{;,])(left|right)\s*:\s*[^/]/m,
  'text-align left/right — use start/end': /text-?align["']?\s*[:=]\s*\{?\s*["'](left|right)["']/i,
  'float — use flex or grid': /\bfloat\s*:/,
} satisfies Record<string, RegExp>;

type Rule = keyof typeof PHYSICAL;

/*
 * Each exception says why it is not a direction. Adding one is a decision a
 * reviewer should see.
 */
const ALLOWED: Record<string, Rule[]> = {
  // SVG padding in chart coordinates; a time axis reads left to right in RTL too.
  '/src/features/analytics/components/TrendChart.tsx': [
    'left: / right: — use inset-inline-start / inset-inline-end',
  ],
};

function violations(path: string, source: string): Rule[] {
  return (Object.keys(PHYSICAL) as Rule[]).filter(
    (rule) => PHYSICAL[rule].test(source) && !(ALLOWED[path] ?? []).includes(rule),
  );
}

describe('logical styles', () => {
  it('has sources to check', () => {
    expect(Object.keys(sources).length).toBeGreaterThan(100);
  });

  // A guard that matches nothing passes forever.
  it.each([
    ['<Group pl="md">'],
    ['style={{ marginLeft: 2 }}'],
    ['.a { padding-right: 4px; }'],
    ['.a {\n  left: 0;\n}'],
    ["style={{ textAlign: 'right' }}"],
    ['.a { float: left; }'],
  ])('catches %s', (sample) => {
    expect(violations('/sample.tsx', sample)).not.toEqual([]);
  });

  it.each([['<Group ps="md">'], ["{ insetInlineStart: 0, textAlign: 'end' }"]])(
    'lets %s through',
    (sample) => {
      expect(violations('/sample.tsx', sample)).toEqual([]);
    },
  );

  for (const [path, source] of Object.entries(sources)) {
    it(`${path.replace('/src/', '')} names no physical side`, () => {
      expect(violations(path, source)).toEqual([]);
    });
  }
});

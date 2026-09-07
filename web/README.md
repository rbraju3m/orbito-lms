# Orbito LMS — Web

React 19 + TypeScript + Vite + Mantine 9 + TanStack Query 5.

Setup and running instructions are in the [repository README](../README.md).

## Layout

```
src/
├── app/            providers, router, theme, layouts, error boundary
├── shared/
│   ├── api/        axios client, error normalisation, query client, wire types
│   ├── ui/         the design system — wrappers over Mantine
│   ├── hooks/ lib/
│   └── test/       MSW server, handlers, renderWithProviders
└── features/<domain>/
    ├── api/        keys.ts (query key factory) + queries.ts (queryOptions)
    ├── components/ hooks/ routes/ schemas/ types.ts
```

A feature may import from `shared/*` and from itself. If two features need the
same thing, it belongs in `shared/`.

## Commands

```bash
npm run dev            # :5173
npm run check          # oxlint + tsc + vitest — run before pushing
npm run test:watch
npm run build
npm run e2e            # Playwright (Ubuntu 22.04+ / macOS / CI only)
```

## Rules

- Server state is TanStack Query's, only. No `useEffect` fetching, no server data
  in Zustand.
- Query keys come from a per-feature `keys` factory; never inline a `queryKey`.
- Every list ships loading, empty, error and success states.
- No `any`, no `@ts-ignore` without a comment.

Full conventions: [`../CLAUDE.md`](../CLAUDE.md) §5 and
[`../docs/FRONTEND_ARCHITECTURE.md`](../docs/FRONTEND_ARCHITECTURE.md).

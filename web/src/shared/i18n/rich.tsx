import { Fragment, type ReactNode } from 'react';

import { t } from './t';

type Params = Record<string, string | number>;
type Tags = Record<string, (chunk: string) => ReactNode>;

/**
 * A message with markup inside it — a link, a bold word — without splitting
 * the sentence into pieces a translator cannot reorder.
 *
 *   rich('example.signin', 'Already enrolled? <link>Sign in</link> to continue.', {
 *     link: (chunk) => <Link to="/login">{chunk}</Link>,
 *   })
 *
 * Tags are flat: `<name>text</name>`, no nesting and no attributes. The
 * translation moves them wherever its word order needs; an unknown tag is
 * drawn as plain text rather than dropped.
 */
export function rich(key: string, fallback: string, tags: Tags, params?: Params): ReactNode {
  const message = t(key, fallback, params);
  const parts: ReactNode[] = [];
  const pattern = /<(\w+)>(.*?)<\/\1>/g;
  let last = 0;

  for (const match of message.matchAll(pattern)) {
    const [whole, name = '', chunk = ''] = match;
    const index = match.index ?? 0;

    if (index > last) parts.push(message.slice(last, index));

    const render = tags[name];
    parts.push(<Fragment key={index}>{render ? render(chunk) : chunk}</Fragment>);
    last = index + whole.length;
  }

  if (last < message.length) parts.push(message.slice(last));

  return <>{parts}</>;
}

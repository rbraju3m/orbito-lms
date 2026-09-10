import { z } from 'zod';

/**
 * An endpoint as the form sees it. Whether the URL is SAFE — https, a public
 * address — is the server's to decide (`WebhookTarget`): it depends on DNS,
 * and a developer's machine is allowed in development. This checks only that
 * it is a URL at all.
 */
export const endpointSchema = z.object({
  url: z.url({ error: 'Enter a full URL, like https://example.com/orbito.' }),
  description: z.string().trim().max(255, 'Keep the description under 255 characters.'),
  events: z.array(z.string()).min(1, 'Pick at least one event.'),
});

export type EndpointValues = z.infer<typeof endpointSchema>;

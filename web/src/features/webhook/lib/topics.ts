import type { WebhookTopicOption } from '../api/types';

export interface TopicGroup {
  group: string;
  topics: WebhookTopicOption[];
}

/**
 * Topics grouped for the picker, in the order the API sent them. The server
 * decides both the order and the grouping (`WebhookTopic::group()`); this only
 * folds a flat list into sections without re-sorting anything.
 */
export function groupTopics(topics: WebhookTopicOption[]): TopicGroup[] {
  const groups: TopicGroup[] = [];

  for (const topic of topics) {
    const existing = groups.find((entry) => entry.group === topic.group);

    if (existing) {
      existing.topics.push(topic);
    } else {
      groups.push({ group: topic.group, topics: [topic] });
    }
  }

  return groups;
}

/** "3 events" / "1 event" — the list shows a count, the detail shows names. */
export function topicCount(events: string[]): string {
  return `${events.length} ${events.length === 1 ? 'event' : 'events'}`;
}

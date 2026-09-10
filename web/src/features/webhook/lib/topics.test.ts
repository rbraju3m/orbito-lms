import { describe, expect, it } from 'vitest';

import { groupTopics, topicCount } from './topics';

describe('groupTopics', () => {
  it('folds topics into sections without re-sorting them', () => {
    const groups = groupTopics([
      { value: 'enrollment.created', label: 'Enrolled', group: 'Enrolment' },
      { value: 'item.completed', label: 'Lesson completed', group: 'Progress' },
      { value: 'enrollment.revoked', label: 'Enrolment revoked', group: 'Enrolment' },
    ]);

    expect(groups.map((g) => g.group)).toEqual(['Enrolment', 'Progress']);
    expect(groups[0]!.topics.map((t) => t.value)).toEqual([
      'enrollment.created',
      'enrollment.revoked',
    ]);
  });

  it('returns nothing for nothing', () => {
    expect(groupTopics([])).toEqual([]);
  });
});

describe('topicCount', () => {
  it('says one event, not one events', () => {
    expect(topicCount(['a'])).toBe('1 event');
    expect(topicCount(['a', 'b'])).toBe('2 events');
  });
});

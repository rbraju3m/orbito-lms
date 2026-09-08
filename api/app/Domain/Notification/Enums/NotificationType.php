<?php

declare(strict_types=1);

namespace App\Domain\Notification\Enums;

/**
 * The catalogue of things worth telling somebody about.
 *
 * One case per notification a listener can raise. The value is what is stored
 * in `notifications.type` and in `notification_preferences.event_key`, so it
 * is a stable key rather than a class name — see the migration.
 *
 * NOTHING is here that reports back the actor's OWN action. Enrolling,
 * submitting a quiz and posting a reply are all things somebody just did and
 * watched happen; a notification about them is noise that teaches people to
 * ignore the bell. Every case below is somebody ELSE acting on your work.
 */
enum NotificationType: string
{
    /** Course staff sent an announcement to everybody enrolled. */
    case AnnouncementPublished = 'announcement.published';

    /** Somebody replied in a thread you started, or under your reply. */
    case DiscussionReplied = 'discussion.replied';

    /** A learner asked a question in a course you teach. */
    case QuestionAsked = 'question.asked';

    /** An instructor marked your assignment. */
    case AssignmentGraded = 'assignment.graded';

    /** You finished a course and the certificate exists. */
    case CertificateIssued = 'certificate.issued';

    /** You earned a badge. */
    case BadgeAwarded = 'badge.awarded';

    public function label(): string
    {
        return match ($this) {
            self::AnnouncementPublished => 'Course announcements',
            self::DiscussionReplied => 'Replies to my questions',
            self::QuestionAsked => 'New questions in my courses',
            self::AssignmentGraded => 'My assignment is graded',
            self::CertificateIssued => 'My certificate is ready',
            self::BadgeAwarded => 'I earned a badge',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AnnouncementPublished => 'When staff post an announcement in a course you are enrolled in.',
            self::DiscussionReplied => 'When somebody answers a question you asked, or replies to you.',
            self::QuestionAsked => 'When a learner asks a question in a course you teach.',
            self::AssignmentGraded => 'When an instructor grades work you submitted.',
            self::CertificateIssued => 'When you complete a course and the certificate is issued.',
            self::BadgeAwarded => 'When you earn a badge.',
        };
    }

    /**
     * Which audience this belongs to, so the settings screen can group the
     * matrix instead of rendering one flat list that grows every phase.
     */
    public function group(): NotificationGroup
    {
        return match ($this) {
            self::QuestionAsked => NotificationGroup::Teaching,
            default => NotificationGroup::Learning,
        };
    }

    /**
     * What somebody gets before they touch anything.
     *
     * All on: the point of the switches is turning things OFF, and a default
     * of silence means a feature nobody discovers. A type that should arrive
     * quiet — a digest, a marketing message — expresses that here, which is
     * why this is a method rather than an assumed constant.
     *
     * @return list<NotificationChannel>
     */
    public function defaultChannels(): array
    {
        return NotificationChannel::cases();
    }

    public function defaultsFor(NotificationChannel $channel): bool
    {
        return in_array($channel, $this->defaultChannels(), true);
    }
}

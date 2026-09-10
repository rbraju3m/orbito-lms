<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Scheduled maintenance.
|
| None of these are load-bearing for correctness — expiry and progress are
| evaluated live on every request. They exist so abandoned state does not sit
| around, and so drift in a stored aggregate is noticed.
*/
Schedule::command('quiz:sweep-expired')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('enrollment:sweep-expired')->hourly()->withoutOverlapping();
/*
 * Subscriptions degrade here and nowhere else, so this runs BEFORE the daily
 * reconcilers: an academy that expired overnight should read as expired for
 * the whole of the next day rather than for part of it.
 */
Schedule::command('subscriptions:expire')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('progress:reconcile')->dailyAt('03:10')->withoutOverlapping();
/*
 * Uploads nothing used within their grace period. BEFORE usage:reconcile, so
 * the storage figures it checks already reflect tonight's deletions.
 */
Schedule::command('media:sweep-unused')->dailyAt('03:20')->withoutOverlapping();
Schedule::command('usage:reconcile')->dailyAt('03:30')->withoutOverlapping();
/*
 * A wrong rating on a course card is invisible — nobody reports it, because
 * nobody knows what it should be. This is the only thing that would notice.
 */
Schedule::command('engagement:reconcile')->dailyAt('03:50')->withoutOverlapping();

/*
 * Retention on the event log. Weekly rather than nightly: it deletes by age,
 * so a week's worth of overdue rows is a week's worth of rows — nobody is
 * waiting on it, and a nightly full-table scan for nothing is a nightly cost.
 */
Schedule::command('analytics:prune')->weeklyOn(1, '04:20')->withoutOverlapping();
/*
 * The webhook delivery log carries names and emails in every payload, and is
 * only for debugging an integration. Settled rows go after 30 days.
 */
Schedule::command('webhooks:prune')->weeklyOn(1, '04:40')->withoutOverlapping();

/*
 * Analytics rollups (ADR-08). Dashboards read these and never the log.
 *
 * Nightly covers yesterday AND today: a listener that fired at 23:59 may only
 * have been written after midnight, so a day never revisited would be
 * permanently short. Hourly then keeps today's figures honest for anybody
 * looking at a dashboard before tomorrow's run.
 *
 * After the reconcilers, because the instructor rollup snapshots `rating_avg`
 * and should snapshot the reconciled value rather than yesterday's drift.
 */
Schedule::command('analytics:rollup --days=2')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('analytics:rollup --days=1 --skip-funnel')->hourly()->withoutOverlapping();

/*
 * Gamification. Boards are snapshots (see BuildLeaderboards); an hour old is
 * the price of not summing the whole ledger on every page load.
 */
Schedule::command('gamification:leaderboards')->hourly()->withoutOverlapping();
/*
 * Creates missing rules and badges only, so this is safe to run on every
 * deploy and safe to run twice — an academy's own tuning is never touched.
 */
Schedule::command('gamification:sync')->dailyAt('02:50')->withoutOverlapping();

/*
 * Live-session reminders. Every five minutes, looking thirty ahead — the
 * window is bounded at both ends so a scheduler that was down does not send
 * "starts in 30 minutes" about a class that has already finished.
 */
Schedule::command('live:remind')->everyFiveMinutes()->withoutOverlapping();

<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Live\Enums\SessionStatus;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Providers\LiveProviderFactory;
use Throwable;

/**
 * Calls a session off.
 *
 * Cancels HERE first and at the provider second, and swallows a provider
 * failure. The order matters: a session that failed to cancel upstream but is
 * cancelled here shows learners the truth and leaves an orphaned meeting
 * nobody joins. The reverse — cancelled at Zoom, still `scheduled` here —
 * sends forty people to a dead link.
 *
 * The row is never deleted. Attendance, the recording and the fact that it was
 * called off are all things somebody may need later.
 */
final class CancelLiveSession
{
    public function __construct(private readonly LiveProviderFactory $providers) {}

    public function handle(LiveSession $session): LiveSession
    {
        $session->forceFill(['status' => SessionStatus::Cancelled])->save();

        if ($session->provider->needsAccount() && $session->external_id !== null) {
            try {
                [$implementation, $account] = $this->providers->for($session->provider);
                $implementation->cancel($session->external_id, $account);
            } catch (Throwable $e) {
                /*
                 * Reported, not raised. The learner-visible truth is already
                 * written, and a provider outage must not stop an academy
                 * calling off a class.
                 */
                report($e);
            }
        }

        return $session->refresh();
    }
}

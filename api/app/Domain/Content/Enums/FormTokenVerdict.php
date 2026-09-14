<?php

declare(strict_types=1);

namespace App\Domain\Content\Enums;

/**
 * What `LeadFormToken` makes of a submission's token. Only `Valid` stores
 * anything, and the other three are deliberately answered differently:
 *
 *  - `TooFast` is accepted SILENTLY and discarded. It is a script that fetched
 *    the form and posted it before a person could have read it, and telling
 *    it so teaches it how long to wait.
 *  - `Expired` and `Invalid` are a 422 on the token. A person who left a tab
 *    open over the weekend needs to be told to reload, and a script learns
 *    nothing it did not already know.
 */
enum FormTokenVerdict
{
    case Valid;
    case TooFast;
    case Expired;
    case Invalid;
}

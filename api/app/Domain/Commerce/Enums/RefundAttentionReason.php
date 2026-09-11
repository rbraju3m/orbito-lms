<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * Why a refund the provider reported could not be taken into the books on its
 * own (ReconcileProviderRefund). The label says what happened; the advice says
 * what the person reading it should check — both here, so the screen renders
 * them and decides nothing.
 */
enum RefundAttentionReason: string
{
    case MoreThanLeft = 'more_than_left';
    case DifferentAmount = 'different_amount';
    case WrongCurrency = 'wrong_currency';
    case RefundedAfterFailure = 'refunded_after_failure';
    case FailedAfterCompletion = 'failed_after_completion';

    public function label(): string
    {
        return match ($this) {
            self::MoreThanLeft => 'More than the order has left to refund',
            self::DifferentAmount => 'A different amount or currency than the refund it names',
            self::WrongCurrency => 'A refund in a currency the order was not paid in',
            self::RefundedAfterFailure => 'Money went back on a refund recorded here as failed',
            self::FailedAfterCompletion => 'The provider failed a refund already completed here',
        };
    }

    public function advice(): string
    {
        return match ($this) {
            self::MoreThanLeft => 'Usually a refund made in the provider’s dashboard that was also recorded here by hand. If the order already shows it, nothing is owed — mark this resolved.',
            self::DifferentAmount => 'Compare the refund on the order with the provider’s dashboard before changing anything.',
            self::WrongCurrency => 'Nothing was recorded here. Check the payment in the provider’s dashboard.',
            self::RefundedAfterFailure => 'The request timed out after the provider acted. Record the refund on the order as refunded elsewhere, then mark this resolved.',
            self::FailedAfterCompletion => 'The money did not reach the learner. Refund them again — through the provider or by bank — then mark this resolved.',
        };
    }
}

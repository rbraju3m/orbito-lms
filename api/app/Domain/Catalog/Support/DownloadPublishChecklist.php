<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\Download;
use App\Domain\Media\Enums\MediaStatus;

/**
 * The single definition of "this download is ready to sell". Rendered by the
 * studio and enforced by `ChangeDownloadStatus` — the same shape as
 * `PublishChecklist` (§10) and `BundlePublishChecklist`.
 */
final class DownloadPublishChecklist
{
    /**
     * @return list<array{code: string, field: string, message: string, blocking: bool, passed: bool}>
     */
    public function evaluate(Download $download): array
    {
        $download->loadMissing(['file', 'product.prices']);

        return [
            $this->check(
                'title_present',
                'title',
                'Give the download a title of at least 5 characters.',
                blocking: true,
                passed: mb_strlen(trim($download->title)) >= 5,
            ),
            $this->check(
                'description_present',
                'description',
                'Write a description of at least 50 characters so buyers know what they are getting.',
                blocking: true,
                passed: mb_strlen(trim(strip_tags((string) $download->description))) >= 50,
            ),
            $this->check(
                'file_attached',
                'media_id',
                'Upload the file buyers will receive.',
                blocking: true,
                passed: $download->file !== null,
            ),
            /*
             * Passes vacuously when there is no file, so a missing file is
             * reported ONCE, above, rather than as two failures that are really
             * one.
             */
            $this->check(
                'file_ready',
                'media_id',
                'The file has not finished uploading yet.',
                blocking: true,
                passed: $download->file === null || $download->file->status === MediaStatus::Ready,
            ),
            $this->check(
                'price_configured',
                'price',
                'A paid download needs a price in '.$this->baseCurrency().' before it can be published.',
                blocking: true,
                passed: $this->isPriced($download),
            ),
            $this->check(
                'thumbnail_present',
                'thumbnail_media_id',
                'A cover image makes the download far more likely to be opened.',
                blocking: false,
                passed: $download->thumbnail_media_id !== null,
            ),
        ];
    }

    /** @return list<array{field: string, code: string, message: string}> */
    public function blockingFailures(Download $download): array
    {
        $failures = [];

        foreach ($this->evaluate($download) as $check) {
            if ($check['blocking'] && ! $check['passed']) {
                $failures[] = ['field' => $check['field'], 'code' => $check['code'], 'message' => $check['message']];
            }
        }

        return $failures;
    }

    /** Free needs nothing; paid needs a price in the accounting currency. */
    private function isPriced(Download $download): bool
    {
        if ($download->pricing_model->isFree()) {
            return true;
        }

        return $download->product?->priceIn($this->baseCurrency()) !== null;
    }

    private function baseCurrency(): string
    {
        return strtoupper((string) config('orbito.currency.base', 'USD'));
    }

    /** @return array{code: string, field: string, message: string, blocking: bool, passed: bool} */
    private function check(string $code, string $field, string $message, bool $blocking, bool $passed): array
    {
        return compact('code', 'field', 'message', 'blocking', 'passed');
    }
}

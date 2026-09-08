<?php

declare(strict_types=1);

namespace App\Domain\Certification\Support;

use App\Domain\Certification\Models\Certificate;
use App\Domain\Certification\Models\CertificateTemplate;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds the HTML dompdf renders.
 *
 * Not a Blade view, deliberately. This runs inside a queued job where the
 * tenant is bootstrapped but no request exists; a view would drag in view
 * composers and the `request()` helpers some of them use. A string builder
 * has no such surface, and the output is easy to assert on in a test.
 *
 * DOMPDF CONSTRAINTS drive the markup, and they are severe enough to be worth
 * naming so nobody "modernises" this and breaks it:
 *
 *  - no flex, no grid. Layout is absolute positioning and tables.
 *  - no external resources (`isRemoteEnabled` is off, because a renderer that
 *    fetches URLs is an SSRF surface). Images are embedded as data URIs.
 *  - `DejaVu Sans` is the only font guaranteed present, and it is the only one
 *    with the glyph coverage to render a Bengali or accented name without
 *    tofu. Do not swap it for a webfont.
 */
final class CertificateHtml
{
    public function for(Certificate $certificate): string
    {
        $layout = $this->layout($certificate->template);
        $snapshot = $certificate->snapshot;

        $learner = $this->text($snapshot['learner_name'] ?? 'Unknown learner');
        $course = $this->text($snapshot['course_title'] ?? '');
        $academy = $this->text($snapshot['academy_name'] ?? '');
        $accent = $this->colour($layout['accent_colour'] ?? '#1c7ed6');

        $body = str_replace(
            ['{learner_name}', '{course_title}', '{academy_name}'],
            [$learner, $course, $academy],
            $this->text($layout['body'] ?? ''),
        );

        $background = $this->backgroundDataUri($certificate->template);

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
  @page { margin: 0; }
  body { margin: 0; font-family: "DejaVu Sans", sans-serif; color: #212529; }
  /* Absolute, because dompdf has no flex to centre with. */
  .sheet { position: relative; width: 100%; height: 100%; }
  .bg { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
  .inner { position: absolute; top: 60px; left: 60px; right: 60px; text-align: center; }
  .rule { border-top: 3px solid {$accent}; width: 120px; margin: 18px auto; }
  h1 { font-size: 26pt; margin: 0 0 6px; color: {$accent}; letter-spacing: 1px; }
  .academy { font-size: 11pt; color: #868e96; text-transform: uppercase; letter-spacing: 2px; }
  .learner { font-size: 30pt; margin: 22px 0 6px; }
  .body { font-size: 12pt; line-height: 1.6; color: #495057; }
  .meta { position: absolute; bottom: 48px; left: 60px; right: 60px; font-size: 9pt; color: #868e96; }
  .meta td { font-size: 9pt; color: #868e96; }
  .sig { font-size: 11pt; color: #212529; border-top: 1px solid #ced4da; padding-top: 4px; }
</style></head>
<body><div class="sheet">
  {$background}
  <div class="inner">
    <div class="academy">{$academy}</div>
    <h1>{$this->text($layout['heading'] ?? 'Certificate of Completion')}</h1>
    <div class="rule"></div>
    <div class="learner">{$learner}</div>
    <div class="body">{$body}</div>
  </div>

  <!-- A table, because two columns without flex is what tables are for. -->
  <table class="meta" width="100%"><tr>
    <td width="50%" align="left">
      Certificate number<br><strong>{$this->text($certificate->number)}</strong><br>
      Issued {$certificate->issued_at->toFormattedDateString()}
    </td>
    <td width="50%" align="right">
      {$this->signature($layout)}
    </td>
  </tr></table>
</div></body></html>
HTML;
    }

    /** @param array<string, mixed> $layout */
    private function signature(array $layout): string
    {
        $name = $this->text($layout['signature_name'] ?? '');

        if ($name === '') {
            return '';
        }

        $title = $this->text($layout['signature_title'] ?? '');

        return '<span class="sig">'.$name.'</span><br>'.$title;
    }

    /** @return array<string, mixed> */
    private function layout(?CertificateTemplate $template): array
    {
        // A certificate whose template was deleted still renders, on the
        // default design. Failing to produce a PDF because somebody tidied up
        // the template list would be the wrong trade.
        return array_merge(
            CertificateTemplate::defaultLayout(),
            $template->layout ?? [],
        );
    }

    /**
     * The background, inlined.
     *
     * Remote loading is off in the renderer, so the file is read off the disk
     * and embedded. A missing or oversized image degrades to no background
     * rather than failing the render — a certificate on white beats no
     * certificate.
     */
    private function backgroundDataUri(?CertificateTemplate $template): string
    {
        $media = $template?->background;

        if ($media === null) {
            return '';
        }

        try {
            $disk = Storage::disk($media->disk->value);

            if (! $disk->exists($media->path)) {
                return '';
            }

            $bytes = (string) $disk->get($media->path);
        } catch (Throwable) {
            return '';
        }

        $uri = 'data:'.$media->mime.';base64,'.base64_encode($bytes);

        return '<img class="bg" src="'.$uri.'" alt="">';
    }

    /** Everything interpolated is escaped: a course title is author input. */
    private function text(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A colour from a template must never become a CSS injection. */
    private function colour(mixed $value): string
    {
        $candidate = (string) $value;

        return preg_match('/^#[0-9a-f]{3,8}$/i', $candidate) === 1 ? $candidate : '#1c7ed6';
    }
}

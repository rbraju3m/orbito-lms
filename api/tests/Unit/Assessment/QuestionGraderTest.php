<?php

declare(strict_types=1);

use App\Domain\Assessment\Grading\QuestionGrader;
use App\Domain\Assessment\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedRegistry();
    $this->grader = app(QuestionGrader::class);
});

describe('single choice', function (): void {
    it('awards full marks for the right option', function (): void {
        $q = Question::factory()->singleChoice()->create();
        $correct = $q->options->firstWhere('is_correct', true);

        $result = $this->grader->grade($q, ['option_id' => $correct->id], 1.0, false);

        expect($result->isCorrect)->toBeTrue()->and($result->pointsEarned)->toBe(1.0);
    });

    it('awards nothing for the wrong option', function (): void {
        $q = Question::factory()->singleChoice()->create();
        $wrong = $q->options->firstWhere('is_correct', false);

        $result = $this->grader->grade($q, ['option_id' => $wrong->id], 1.0, false);

        expect($result->isCorrect)->toBeFalse()->and($result->pointsEarned)->toBe(0.0);
    });

    it('applies negative marking only when the quiz enables it', function (): void {
        $q = Question::factory()->singleChoice()->create(['negative_points' => 0.5]);
        $wrong = $q->options->firstWhere('is_correct', false);

        expect($this->grader->grade($q, ['option_id' => $wrong->id], 1.0, false)->pointsEarned)->toBe(0.0)
            ->and($this->grader->grade($q, ['option_id' => $wrong->id], 1.0, true)->pointsEarned)->toBe(-0.5);
    });

    /* Leaving a question blank is not the same as answering it wrongly. */
    it('never penalises an unanswered question', function (): void {
        $q = Question::factory()->singleChoice()->create(['negative_points' => 2]);

        expect($this->grader->grade($q, null, 1.0, true)->pointsEarned)->toBe(0.0)
            ->and($this->grader->grade($q, [], 1.0, true)->pointsEarned)->toBe(0.0);
    });
});

describe('true or false', function (): void {
    it('grades both ways', function (): void {
        $q = Question::factory()->trueFalse()->create();
        $t = $q->options->firstWhere('label', 'True');
        $f = $q->options->firstWhere('label', 'False');

        expect($this->grader->grade($q, ['option_id' => $t->id], 1.0, false)->isCorrect)->toBeTrue()
            ->and($this->grader->grade($q, ['option_id' => $f->id], 1.0, false)->isCorrect)->toBeFalse();
    });
});

describe('multiple choice', function (): void {
    it('awards full marks for exactly the right set', function (): void {
        $q = Question::factory()->multipleChoice()->create();
        $ids = $q->options->where('is_correct', true)->pluck('id')->all();

        $result = $this->grader->grade($q, ['option_ids' => $ids], 4.0, false);

        expect($result->isCorrect)->toBeTrue()->and($result->pointsEarned)->toBe(4.0);
    });

    it('gives partial credit for a partly right set', function (): void {
        $q = Question::factory()->multipleChoice()->create();
        $one = $q->options->where('is_correct', true)->first()->id;

        // One of two correct, nothing wrong -> half marks.
        $result = $this->grader->grade($q, ['option_ids' => [$one]], 4.0, false);

        expect($result->pointsEarned)->toBe(2.0)->and($result->isCorrect)->toBeFalse();
    });

    /* Selecting everything must not score full marks. */
    it('penalises wrong picks so selecting everything scores nothing', function (): void {
        $q = Question::factory()->multipleChoice()->create();
        $all = $q->options->pluck('id')->all();

        expect($this->grader->grade($q, ['option_ids' => $all], 4.0, false)->pointsEarned)->toBe(0.0);
    });

    it('ignores duplicate selections', function (): void {
        $q = Question::factory()->multipleChoice()->create();
        $ids = $q->options->where('is_correct', true)->pluck('id')->all();

        $result = $this->grader->grade($q, ['option_ids' => [...$ids, ...$ids]], 4.0, false);

        expect($result->pointsEarned)->toBe(4.0);
    });
});

describe('ordering', function (): void {
    it('awards marks only for the exact sequence', function (): void {
        $q = Question::factory()->ordering()->create();
        $correct = $q->options->sortBy('position')->pluck('id')->all();

        expect($this->grader->grade($q, ['option_ids' => $correct], 1.0, false)->isCorrect)->toBeTrue();
    });

    /* A nearly-right sequence is still the wrong sequence. */
    it('gives nothing for a nearly right sequence', function (): void {
        $q = Question::factory()->ordering()->create();
        $ids = $q->options->sortBy('position')->pluck('id')->all();
        [$ids[0], $ids[1]] = [$ids[1], $ids[0]];

        expect($this->grader->grade($q, ['option_ids' => $ids], 1.0, false)->isCorrect)->toBeFalse();
    });
});

describe('matching', function (): void {
    it('awards full marks for every pair right', function (): void {
        $q = Question::factory()->matching()->create();
        $pairs = $q->options->mapWithKeys(fn ($o) => [(string) $o->id => $o->match_key])->all();

        $result = $this->grader->grade($q, ['pairs' => $pairs], 3.0, false);

        expect($result->isCorrect)->toBeTrue()->and($result->pointsEarned)->toBe(3.0);
    });

    it('gives partial credit per correct pair', function (): void {
        $q = Question::factory()->matching()->create();
        $options = $q->options->values();
        $pairs = [
            (string) $options[0]->id => $options[0]->match_key,
            (string) $options[1]->id => 'wrong',
            (string) $options[2]->id => 'also wrong',
        ];

        expect($this->grader->grade($q, ['pairs' => $pairs], 3.0, false)->pointsEarned)->toBe(1.0);
    });
});

describe('short answer', function (): void {
    it('accepts any configured answer', function (): void {
        $q = Question::factory()->shortAnswer()->create();

        expect($this->grader->grade($q, ['text' => 'Tagore'], 1.0, false)->isCorrect)->toBeTrue()
            ->and($this->grader->grade($q, ['text' => 'Rabindranath Tagore'], 1.0, false)->isCorrect)
            ->toBeTrue();
    });

    it('ignores case and stray whitespace by default', function (): void {
        $q = Question::factory()->shortAnswer()->create();

        expect($this->grader->grade($q, ['text' => '  tagore '], 1.0, false)->isCorrect)->toBeTrue();
        expect($this->grader->grade($q, ['text' => 'rabindranath   tagore'], 1.0, false)->isCorrect)
            ->toBeTrue();
    });

    it('honours case sensitivity when asked', function (): void {
        $q = Question::factory()->shortAnswer()->create([
            'settings' => ['accepted' => ['Tagore'], 'case_sensitive' => true],
        ]);

        expect($this->grader->grade($q, ['text' => 'tagore'], 1.0, false)->isCorrect)->toBeFalse();
    });

    /*
     * With no accepted answers configured, queueing for a human beats marking
     * every learner wrong.
     */
    it('queues for review when no accepted answers are configured', function (): void {
        $q = Question::factory()->shortAnswer(withAccepted: false)->create();

        expect($this->grader->grade($q, ['text' => 'anything'], 1.0, false)->isCorrect)->toBeNull();
    });
});

describe('long answer', function (): void {
    it('always needs a human', function (): void {
        $q = Question::factory()->longAnswer()->create();

        $result = $this->grader->grade($q, ['text' => str_repeat('essay ', 100)], 5.0, false);

        expect($result->isCorrect)->toBeNull()->and($result->pointsEarned)->toBe(0.0);
    });
});

describe('fill in the blank', function (): void {
    it('awards full marks for every blank right', function (): void {
        $q = Question::factory()->fillBlank()->create();

        $result = $this->grader->grade($q, ['blanks' => ['Tagore', 'Gitanjali']], 2.0, false);

        expect($result->isCorrect)->toBeTrue()->and($result->pointsEarned)->toBe(2.0);
    });

    it('gives an equal share per blank', function (): void {
        $q = Question::factory()->fillBlank()->create();

        expect($this->grader->grade($q, ['blanks' => ['Tagore', 'wrong']], 2.0, false)->pointsEarned)
            ->toBe(1.0);
    });

    it('does not count an empty blank as correct', function (): void {
        $q = Question::factory()->fillBlank()->create([
            'settings' => ['blanks' => [['accepted' => ['']], ['accepted' => ['x']]]],
        ]);

        expect($this->grader->grade($q, ['blanks' => ['', '']], 2.0, false)->pointsEarned)->toBe(0.0);
    });
});

describe('image types', function (): void {
    it('grades image choice like single choice', function (): void {
        $q = Question::factory()->imageChoice()->create();
        $correct = $q->options->firstWhere('is_correct', true);

        expect($this->grader->grade($q, ['option_id' => $correct->id], 1.0, false)->isCorrect)->toBeTrue();
    });

    it('grades image matching like matching', function (): void {
        $q = Question::factory()->imageMatching()->create();
        $pairs = $q->options->mapWithKeys(fn ($o) => [(string) $o->id => $o->match_key])->all();

        expect($this->grader->grade($q, ['pairs' => $pairs], 2.0, false)->isCorrect)->toBeTrue();
    });
});

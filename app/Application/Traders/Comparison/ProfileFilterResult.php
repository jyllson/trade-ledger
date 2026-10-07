<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

/**
 * Every analysis profile criterion of one trader (D-048), in
 * ProfileCriterion order, plus the derived unweighted verdict. There is
 * no score: the verdict only reports which outcomes occur.
 */
final readonly class ProfileFilterResult
{
    /**
     * @param  array<string, CriterionResult>  $results  keyed by ProfileCriterion value, enum order
     */
    private function __construct(
        public ?int $profileId,
        public array $results,
        public ProfileFilterVerdict $verdict,
    ) {}

    /**
     * @param  list<CriterionResult>  $results
     */
    public static function of(?int $profileId, array $results): self
    {
        $keyed = [];

        foreach ($results as $result) {
            $keyed[$result->criterion->value] = $result;
        }

        $outcomes = array_map(static fn (CriterionResult $result): CriterionOutcome => $result->outcome, $results);

        $verdict = match (true) {
            in_array(CriterionOutcome::Fail, $outcomes, true) => ProfileFilterVerdict::AtLeastOneFailed,
            in_array(CriterionOutcome::Unknown, $outcomes, true) => ProfileFilterVerdict::AtLeastOneUnknown,
            in_array(CriterionOutcome::Pass, $outcomes, true) => ProfileFilterVerdict::AllAppliedPassed,
            default => ProfileFilterVerdict::NoneApplied,
        };

        return new self($profileId, $keyed, $verdict);
    }

    public function result(ProfileCriterion $criterion): CriterionResult
    {
        return $this->results[$criterion->value];
    }

    /**
     * @return list<ProfileCriterion>
     */
    public function criteriaWithOutcome(CriterionOutcome $outcome): array
    {
        return array_values(array_map(
            static fn (CriterionResult $result): ProfileCriterion => $result->criterion,
            array_filter($this->results, static fn (CriterionResult $result): bool => $result->outcome === $outcome),
        ));
    }
}

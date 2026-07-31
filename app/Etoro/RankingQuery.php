<?php

namespace App\Etoro;

final readonly class RankingQuery
{
    public function __construct(
        public string $period,
        public int $page = 1,
        public int $pageSize = 20,
        public ?string $sort = null,
        public ?string $country = null,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function toQueryArray(): array
    {
        return array_filter([
            'period' => $this->period,
            'page' => $this->page,
            'pageSize' => $this->pageSize,
            'sort' => $this->sort,
            'country' => $this->country,
        ], static fn (mixed $value): bool => $value !== null);
    }
}

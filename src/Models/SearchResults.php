<?php
declare(strict_types=1);

namespace ContentReactor\Search\Models;

use craft\base\ElementInterface;

final class SearchResults
{
	/**
	 * @param array<int, array{elementId: int, siteId: int, score: float}> $hits The requested page of matching documents, best first
	 * @param int $total The number of matching documents
	 * @param array<string, array<string, int>> $facetCounts The number of matching documents per requested facet handle and value
	 * @param ElementInterface[] $elements The hits' elements, in order, leaving out the ones that aren't live (e.g. with a future post date)
	 * @param int[] $pinnedElementIds The pages shown first because they're pinned for the term, on any page of the results
	 */
	public function __construct(
		public readonly array $hits = [],
		public readonly int $total = 0,
		public readonly array $facetCounts = [],
		public array $elements = [],
		public readonly array $pinnedElementIds = [],
	) {}

	public function isPinned(ElementInterface $element): bool
	{
		return in_array((int)$element->id, $this->pinnedElementIds, true);
	}
}

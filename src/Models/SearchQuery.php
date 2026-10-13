<?php
declare(strict_types=1);

namespace ContentReactor\Search\Models;

final class SearchQuery
{
	/**
	 * @param string $term Words the documents have to contain, all of them
	 * @param int|null $siteId The site to search in, the current site when `null`
	 * @param array<string, mixed> $filters Values the documents have to have, per facet handle: a value, a list of values any of which matches,
	 * or a range with `min` and/or `max` keys, e.g. `['units' => [12, 14], 'published' => ['min' => '2020-01-01']]`
	 * @param string[] $facets Handles of the facets to count the matching documents' values of
	 * @param bool $track Whether the search is logged for search analytics, as searches from the search endpoint and GraphQL are
	 * @param array<int, string[]> $synonyms Groups of words that match each other, the site's synonyms when left empty
	 * @param int[]|null $pinnedElementIds Pages shown first, in this order, when they match the filters. The site's pinned results
	 * for the term when `null`, none when empty.
	 * @param int[]|null $elementIds Only these pages are searched, all of them when `null`
	 * @param int[] $excludedElementIds Pages left out of the search
	 */
	public function __construct(
		public string $term = '',
		public ?int $siteId = null,
		public array $filters = [],
		public array $facets = [],
		public int $limit = 20,
		public int $offset = 0,
		public bool $track = false,
		public array $synonyms = [],
		public ?array $pinnedElementIds = null,
		public ?array $elementIds = null,
		public array $excludedElementIds = [],
	) {}

	/**
	 * The words a word of the term matches: the words of its synonym groups, or just itself
	 *
	 * @param callable(string): string $normalize Normalizes synonyms the way the term's words are
	 * @return string[]
	 */
	public function getWordAlternatives(string $word, callable $normalize): array
	{
		$alternatives = [$word];
		foreach ($this->synonyms as $group) {
			$normalizedGroup = array_map($normalize, $group);
			if (in_array($word, $normalizedGroup, true)) {
				array_push($alternatives, ...$normalizedGroup);
			}
		}

		return array_values(array_unique(array_filter($alternatives, fn(string $alternative): bool => $alternative !== '')));
	}

	public static function isRange(mixed $filter): bool
	{
		return is_array($filter) && (array_key_exists('min', $filter) || array_key_exists('max', $filter));
	}
}

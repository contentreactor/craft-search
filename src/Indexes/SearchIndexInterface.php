<?php
declare(strict_types=1);

namespace ContentReactor\Search\Indexes;

use ContentReactor\Search\Models\{
	Facet,
	SearchDocument,
	SearchQuery,
	SearchResults,
};

/**
 * Stores pages' search documents and searches them, e.g. in the database or in Elasticsearch.
 *
 * Filters and facet values arrive normalized to their facets' types, see [[Facet::normalizeValue()]].
 * Facet counts are keyed by values as strings: numbers without trailing zeros, booleans as `true` and `false`, dates as `Y-m-d\TH:i:s\Z`.
 * Only live documents are searched: those without a post date in the future or an expiry date in the past.
 * Searches are kept to a query's `elementIds` when it has them, and leave out its `excludedElementIds`, for pinned results.
 *
 * Indexes are created from their component configs, which the control panel saves from their settings forms' inputs,
 * named after their properties, see [[\MarcusGaius\FieldValueParser\Helpers\SettingsHelper::castToPropertyTypes()]].
 */
interface SearchIndexInterface
{
	public static function displayName(): string;

	/**
	 * The control panel form for the index's settings
	 */
	public function getSettingsHtml(): ?string;

	/**
	 * Why the index can't be used, e.g. its service being unreachable, or `null` when it can
	 */
	public function getConnectionError(): ?string;

	/**
	 * The number of stored documents of a site, or of all of them, whether they're live or not
	 */
	public function getDocumentCount(?int $siteId = null): int;

	/**
	 * Creates or updates what the index needs to store documents with the facets, e.g. Elasticsearch mappings
	 *
	 * @param array<string, Facet> $facets Indexed by handle
	 */
	public function prepare(array $facets): void;

	/**
	 * Stores a page's document, replacing the one it had in its site
	 */
	public function save(SearchDocument $document): void;

	public function delete(int $elementId, int $siteId): void;

	/**
	 * Removes the documents of a site, or all of them
	 */
	public function clear(?int $siteId = null): void;

	/**
	 * @param array<string, Facet> $facets The defined facets, indexed by handle
	 */
	public function search(SearchQuery $query, array $facets): SearchResults;
}

<?php
declare(strict_types=1);

namespace ContentReactor\Search\Gql;

use ContentReactor\Search\Models\{
	Facet,
	SearchResults,
};
use ContentReactor\Search\Plugin;
use Craft;
use craft\helpers\Gql as GqlHelper;
use craft\models\Site;
use GraphQL\Error\UserError;

/**
 * Resolves `crSearch` queries. Searches are kept to the sites, sections, volumes, category groups and tag groups the schema
 * can read, so totals, pages and facet counts only include what it can see.
 */
final class SearchResolver
{
	/**
	 * @param array{q?: string|null, site?: string|null, filters?: array<int, array{facet: string, values?: string[]|null, min?: string|null, max?: string|null}>|null, facets?: string[]|null, page?: int|null, limit?: int|null} $arguments
	 * @return array<string, mixed>
	 * @throws UserError if the site doesn't exist or the schema can't read it
	 */
	public static function resolve(mixed $source, array $arguments): array
	{
		$plugin = Plugin::getInstance();
		$search = $plugin->getSearch();
		$site = self::getSite($arguments['site'] ?? null);

		$filters = [];
		foreach ($arguments['filters'] ?? [] as $filter) {
			$filters[$filter['facet']] = isset($filter['min']) || isset($filter['max'])
				? array_filter(['min' => $filter['min'] ?? null, 'max' => $filter['max'] ?? null], fn(?string $bound): bool => $bound !== null)
				: $filter['values'] ?? [];
		}

		// Filters by facets that aren't defined are left out, like the search endpoint's
		$query = $search->createQuery([
			'q' => $arguments['q'] ?? '',
			'filters' => $filters,
			'page' => $arguments['page'] ?? 1,
		]);
		$query->siteId = (int)$site->id;
		$query->track = true;
		if (isset($arguments['limit'])) {
			$query->limit = max(1, $arguments['limit']);
			$query->offset = (max(1, $arguments['page'] ?? 1) - 1) * $query->limit;
		}
		if (isset($arguments['facets'])) {
			$query->facets = $arguments['facets'];
		}

		// Only the sources the schema can read, of the ones asked for
		$sources = self::getReadableSources();
		if (isset($query->filters[Facet::SOURCE])) {
			$sources = array_values(array_intersect($sources, (array)$query->filters[Facet::SOURCE]));
		}
		$query->filters[Facet::SOURCE] = $sources;

		$results = empty($sources) ? new SearchResults() : $search->search($query);
		$facets = $plugin->getSettings()->getFacets();

		return [
			'total' => $results->total,
			'page' => intdiv($query->offset, $query->limit) + 1,
			'pageCount' => max(1, (int)ceil($results->total / $query->limit)),
			'elements' => array_values($results->elements),
			'pinnedIds' => $results->pinnedElementIds,
			'facets' => array_map(fn(string $handle): array => [
				'handle' => $handle,
				'label' => Craft::t('site', $facets[$handle]->label),
				'type' => $facets[$handle]->type->value,
				'width' => $facets[$handle]->width->value,
				'options' => $search->getFacetOptions($facets[$handle], $results->facetCounts[$handle] ?? []),
			], array_values(array_filter($query->facets, fn(string $handle): bool => isset($facets[$handle])))),
		];
	}

	/**
	 * @throws UserError
	 */
	private static function getSite(?string $handle): Site
	{
		$sites = Craft::$app->getSites();
		$site = $handle !== null ? $sites->getSiteByHandle($handle) : $sites->getCurrentSite();
		$allowed = array_map(fn(Site $site): string => $site->uid, GqlHelper::getAllowedSites());

		if (!$site || !in_array($site->uid, $allowed, true)) {
			throw new UserError(Craft::t('cr-search', 'The schema can’t search the “{site}” site.', ['site' => $handle ?? $site?->handle]));
		}

		return $site;
	}

	/**
	 * @return string[] Source keys, as the `source` facet has them
	 */
	private static function getReadableSources(): array
	{
		$allowed = GqlHelper::extractAllowedEntitiesFromSchema();
		$prefixes = [
			'sections' => 'section',
			'volumes' => 'volume',
			'categorygroups' => 'group',
			'taggroups' => 'taggroup',
		];

		$sources = [];
		foreach ($prefixes as $entity => $prefix) {
			foreach ($allowed[$entity] ?? [] as $uid) {
				$sources[] = "$prefix:$uid";
			}
		}

		return $sources;
	}
}

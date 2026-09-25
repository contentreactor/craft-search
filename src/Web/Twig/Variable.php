<?php
declare(strict_types=1);

namespace ContentReactor\Search\Web\Twig;

use ContentReactor\Search\Enums\FacetType;
use ContentReactor\Search\Indexes\SearchIndexInterface;
use ContentReactor\Search\Models\{
	SearchQuery,
	SearchResults,
};
use ContentReactor\Search\Plugin;
use Craft;
use craft\base\ElementInterface;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Available as `craft.crSearch`: search helpers for site templates, and helpers for the control panel settings
 */
class Variable
{
	/**
	 * Searches with request-style params: `q`, `filters` and `page`, and `track: true` to log the search for search analytics,
	 * e.g. on a site's own search results page
	 *
	 * @param array<string, mixed>|SearchQuery $query
	 */
	public function search(array|SearchQuery $query = []): SearchResults
	{
		$search = Plugin::getInstance()->getSearch();
		if ($query instanceof SearchQuery) return $search->search($query);

		// Only searches visitors make are worth logging, e.g. not related pages' searches
		$track = (bool)($query['track'] ?? false);
		unset($query['track']);
		$searchQuery = $search->createQuery($query);
		$searchQuery->track = $track;

		return $search->search($searchQuery);
	}

	/**
	 * The sources pages can be excluded by, as the `source` facet has them
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	public function getExcludableSourceOptions(): array
	{
		$options = [];
		// Each labeled with what it is, as sections, groups and volumes can share names
		$label = fn(string $name, string $kind): string => Craft::t('cr-search', '{name} ({kind})', ['name' => Craft::t('site', $name), 'kind' => $kind]);
		foreach (Craft::$app->getEntries()->getAllSections() as $section) {
			$options[] = ['label' => $label($section->name, Craft::t('app', 'Section')), 'value' => "section:$section->uid"];
		}
		foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
			$options[] = ['label' => $label($group->name, Craft::t('app', 'Category Group')), 'value' => "group:$group->uid"];
		}
		foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
			$options[] = ['label' => $label($volume->name, Craft::t('app', 'Volume')), 'value' => "volume:$volume->uid"];
		}

		return $options;
	}

	/**
	 * @return array<int, array{label: string, value: string}>
	 */
	public function getEntryTypeOptions(): array
	{
		return array_map(
			fn($entryType): array => ['label' => Craft::t('site', $entryType->name), 'value' => (string)$entryType->uid],
			Craft::$app->getEntries()->getAllEntryTypes(),
		);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	public function searchUrl(array $params = []): string
	{
		return Plugin::getInstance()->getSearch()->getSearchUrl($params);
	}

	/**
	 * @return array{name: string|null, error: string|null, sites: array<int, array{name: string, count: int}>, endpointUrl: string|null}
	 */
	public function getSearchStatus(): array
	{
		$status = ['name' => null, 'error' => null, 'sites' => [], 'endpointUrl' => null];
		$search = Plugin::getInstance()->getSearch();

		try {
			$index = $search->getIndex();
			if ($index === null) return $status;

			$status['name'] = $index::displayName();
			$status['error'] = $index->getConnectionError();
			if ($status['error'] === null) {
				foreach (Craft::$app->getSites()->getAllSites() as $site) {
					$status['sites'][] = ['name' => Craft::t('site', $site->getName()), 'count' => $index->getDocumentCount($site->id)];
				}
			}
			if ($search->isEndpointEnabled()) {
				$status['endpointUrl'] = $search->getSearchUrl();
			}
		} catch (Throwable $e) {
			$status['error'] = $e->getMessage();
		}

		return $status;
	}

	/**
	 * @return array{rows: array<int, array{handle: string, label: string, type: string, icon: string, width: string}>, error: string|null}
	 */
	public function getFacetRows(): array
	{
		try {
			$facets = Plugin::getInstance()->getSettings()->getFacets();
		} catch (InvalidConfigException $e) {
			return ['rows' => [], 'error' => $e->getMessage()];
		}

		$rows = [];
		foreach ($facets as $facet) {
			$rows[] = [
				'handle' => $facet->handle,
				'label' => $facet->label,
				'type' => ucfirst($facet->type->value),
				'icon' => $this->getFacetTypeIcon($facet->type),
				'width' => ucfirst($facet->width->value),
			];
		}

		return ['rows' => $rows, 'error' => null];
	}

	/**
	 * @return array<int, array{label: string, value: string}>
	 */
	public function getSearchIndexTypeOptions(): array
	{
		return array_map(
			fn(string $indexType): array => ['label' => $indexType::displayName(), 'value' => $indexType],
			Plugin::getInstance()->getSearch()->getIndexTypes(),
		);
	}

	/**
	 * @param SearchIndexInterface|null $current An index that failed validation, shown with its errors
	 * @return SearchIndexInterface[] An index of each type, the configured one with its settings
	 */
	public function getSearchIndexes(?SearchIndexInterface $current = null): array
	{
		$settings = Plugin::getInstance()->getSettings();
		$indexes = [];

		foreach (Plugin::getInstance()->getSearch()->getIndexTypes() as $indexType) {
			$indexes[] = match (true) {
				$current instanceof $indexType => $current,
				$settings->getSearchIndexType() === $indexType => Craft::createObject($settings->searchIndex),
				default => new $indexType(),
			};
		}

		return $indexes;
	}

	/**
	 * @return string|null A warning for settings `config/cr-search.php` overrides
	 */
	public function getConfigWarning(string $attribute): ?string
	{
		if (!Plugin::getInstance()->getSettings()->isOverriddenByConfig($attribute)) return null;

		return Craft::t('cr-search', 'This is being overridden by the `{setting}` setting in `config/cr-search.php`.', [
			'setting' => $attribute,
		]);
	}

	private function getFacetTypeIcon(FacetType $type): string
	{
		return match ($type) {
			FacetType::KEYWORD => 'tags',
			FacetType::NUMBER => 'hashtag',
			FacetType::BOOLEAN => 'toggle-on',
			FacetType::DATE => 'calendar',
		};
	}

	public function getPluginName(): string
	{
		return Plugin::getInstance()->getPluginName();
	}

	/**
	 * @return array<string, array{label: string, url: string, icon: string}> The screens the user can access, by handle
	 */
	public function getSettingsNavItems(): array
	{
		return Plugin::getInstance()->getNavItems();
	}

	/**
	 * @return array<class-string<ElementInterface>, string> Display names, indexed by element type
	 */
	public function getElementTypes(bool $withUrisOnly = false): array
	{
		$elementTypes = [];
		foreach (Craft::$app->getElements()->getAllElementTypes() as $elementType) {
			/** @var class-string<ElementInterface> $elementType */
			if ($withUrisOnly && !$elementType::hasUris()) continue;
			$elementTypes[$elementType] = $elementType::pluralDisplayName();
		}
		asort($elementTypes);

		return $elementTypes;
	}
}

<?php
declare(strict_types=1);

namespace ContentReactor\Search\Services;

use ContentReactor\Search\Enums\FacetType;
use ContentReactor\Search\Events\{
	DefineIndexableEvent,
	SearchEvent,
	SearchableContentChangeEvent,
};
use ContentReactor\Search\Fields\HideFromSearch;
use ContentReactor\Search\Indexes\{
	DatabaseIndex,
	ElasticsearchIndex,
	SearchIndexInterface,
};
use ContentReactor\Search\Jobs\IndexPages;
use ContentReactor\Search\Models\{
	Facet,
	SearchDocument,
	SearchQuery,
	SearchResults,
	SearchSettings,
};
use ContentReactor\Search\Plugin;
use Craft;
use craft\base\{
	Component,
	ElementInterface,
};
use craft\db\{
	Query,
	Table,
};
use craft\elements\Entry;
use craft\events\{
	ElementEvent,
	RegisterComponentTypesEvent,
};
use craft\helpers\{
	ElementHelper,
	Queue,
	UrlHelper,
};
use InvalidArgumentException;
use MarcusGaius\FieldValueParser\Enums\Purpose;
use MarcusGaius\FieldValueParser\FieldValueParser;
use yii\base\InvalidConfigException;

/**
 * Builds the searchable content of pages, keeps the search index up to date, and searches it
 */
class Search extends Component
{
	public const EVENT_AFTER_CONTENT_CHANGE = 'afterContentChange';

	/**
	 * Lets plugins register their own search index types, selectable in the control panel
	 */
	public const EVENT_REGISTER_INDEX_TYPES = 'registerIndexTypes';

	/**
	 * Lets plugins change a query before it's searched, or answer it themselves by setting the event's results
	 *
	 * @see SearchEvent
	 */
	public const EVENT_BEFORE_SEARCH = 'beforeSearch';

	/**
	 * Lets plugins change or replace results once they're found
	 *
	 * @see SearchEvent
	 */
	public const EVENT_AFTER_SEARCH = 'afterSearch';

	/**
	 * Lets plugins decide whether pages belong in the search index
	 *
	 * @see DefineIndexableEvent
	 */
	public const EVENT_DEFINE_INDEXABLE = 'defineIndexable';

	/** @var array<string, array<int, array<int, ElementInterface>>> Pages of elements being deleted, resolved while their relations still exist */
	private array $deletedElementPages = [];

	/** @var SearchIndexInterface|false|null `false` until the configured index is created */
	private SearchIndexInterface|false|null $index = false;

	/** @var array<string, array{elementId: int, elementType: class-string<ElementInterface>, siteId: int}> Pages to reindex once the request ends */
	private array $pendingPages = [];

	private bool $isPushScheduled = false;

	/** @var array<string, array<string, mixed>> Search states of elements being saved, from before they were saved */
	private array $searchStates = [];

	/**
	 * @throws InvalidConfigException if the configured search index isn't one
	 */
	public function getIndex(): ?SearchIndexInterface
	{
		if ($this->index === false) {
			$config = $this->getSearchSettings()->searchIndex;
			$index = $config === null ? null : Craft::createObject($config);

			if ($index !== null && !$index instanceof SearchIndexInterface) {
				throw new InvalidConfigException(sprintf('The search index must implement %s.', SearchIndexInterface::class));
			}

			$this->index = $index;
		}

		return $this->index;
	}

	public function setIndex(?SearchIndexInterface $index): void
	{
		$this->index = $index;
	}

	/**
	 * Creates the search index from the settings again, once they've changed
	 */
	public function resetIndex(): void
	{
		$this->index = false;
	}

	/**
	 * @return class-string<SearchIndexInterface>[]
	 */
	public function getIndexTypes(): array
	{
		$event = new RegisterComponentTypesEvent([
			'types' => [
				DatabaseIndex::class,
				ElasticsearchIndex::class,
			],
		]);

		if ($this->hasEventHandlers(self::EVENT_REGISTER_INDEX_TYPES)) {
			$this->trigger(self::EVENT_REGISTER_INDEX_TYPES, $event);
		}

		return $event->types;
	}

	/**
	 * Whether the search results endpoint is registered: it's enabled, and there's a search index to search
	 */
	public function isEndpointEnabled(): bool
	{
		return $this->getSearchSettings()->searchEndpointEnabled && $this->getIndex() !== null;
	}

	/**
	 * @param array<string, mixed> $params Query string params, e.g. `['q' => 'forest']`
	 */
	public function getSearchUrl(array $params = []): string
	{
		return UrlHelper::siteUrl($this->getSearchSettings()->searchEndpointUri, $params ?: null);
	}

	/**
	 * Returns a page's searchable content in its site, with its facet values: its own, its nested elements', however deeply they're nested,
	 * and its related elements', as many relations deep as the relation depth allows
	 */
	public function getDocument(ElementInterface $page): SearchDocument
	{
		$keywords = $this->flatten(FieldValueParser::getInstance()->getValues()->parse($page, Purpose::SEARCH));

		$facets = [];
		foreach ($this->getSearchSettings()->getFacets() as $handle => $facet) {
			if (!$facet->appliesTo($page)) continue;

			$facetValues = $facet->getValues($page);
			if (!empty($facetValues)) $facets[$handle] = $facetValues;
		}

		return new SearchDocument($page, $keywords, $facets);
	}

	/**
	 * Whether the page belongs in the search index: an enabled canonical element of an indexed element type, with a URL in its site,
	 * that isn't excluded. Pages that aren't live yet or anymore are indexed, and left out of results while they aren't.
	 */
	public function isIndexable(ElementInterface $page): bool
	{
		$isIndexable = $page->id
			&& !ElementHelper::isDraftOrRevision($page)
			&& in_array($page::class, $this->getIndexedElementTypes(), true)
			&& $page->getUrl() !== null
			&& $page->enabled
			&& $page->getEnabledForSite()
			&& !$this->isExcluded($page);

		if ($this->hasEventHandlers(self::EVENT_DEFINE_INDEXABLE)) {
			$event = new DefineIndexableEvent(['page' => $page, 'isIndexable' => $isIndexable]);
			$this->trigger(self::EVENT_DEFINE_INDEXABLE, $event);
			$isIndexable = $event->isIndexable;
		}

		return $isIndexable;
	}

	/**
	 * Whether the page is kept out of search: by its source or entry type in the search settings, or its Hide from Search field
	 */
	public function isExcluded(ElementInterface $page): bool
	{
		$settings = $this->getSearchSettings();

		if (!empty($settings->excludedSources)
			&& !empty(array_intersect(FieldValueParser::getInstance()->getPages()->getSourceKeys($page), $settings->excludedSources))
		) {
			return true;
		}

		if (!empty($settings->excludedEntryTypes) && $page instanceof Entry && in_array($page->getType()->uid, $settings->excludedEntryTypes, true)) {
			return true;
		}

		foreach ($page->getFieldLayout()?->getCustomFields() ?? [] as $field) {
			if ($field instanceof HideFromSearch && $page->getFieldValue((string)$field->handle)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Stores the page's document in the search index, or removes it if the page doesn't belong there
	 */
	public function indexPage(ElementInterface $page): void
	{
		$index = $this->requireIndex();

		if ($this->isIndexable($page)) {
			$index->save($this->getDocument($page));
		} elseif ($page->id) {
			$index->delete((int)$page->id, (int)$page->siteId);
		}
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 */
	public function indexPageById(int $elementId, string $elementType, int $siteId): void
	{
		$page = Craft::$app->getElements()->getElementById($elementId, $elementType, $siteId, ['status' => null]);

		if ($page) {
			$this->indexPage($page);
		} else {
			$this->requireIndex()->delete($elementId, $siteId);
		}
	}

	/**
	 * Rebuilds the search index from every page of the indexed element types
	 *
	 * @param callable(ElementInterface, int, int): void|null $onIndexed Called with each indexed page, how many pages were gone through, and how many there are
	 * @return int The number of indexed pages
	 */
	public function rebuild(?int $siteId = null, ?callable $onIndexed = null): int
	{
		$index = $this->requireIndex();
		$index->prepare($this->getSearchSettings()->getFacets());
		$index->clear($siteId);

		$queries = [];
		foreach ($this->getIndexedElementTypes() as $elementType) {
			foreach ($siteId !== null ? [$siteId] : Craft::$app->getSites()->getAllSiteIds() as $querySiteId) {
				$queries[] = $elementType::find()
					->siteId($querySiteId)
					->uri(':notempty:')
					->status(null);
			}
		}

		$total = array_sum(array_map(fn($query): int => (int)$query->count(), $queries));
		$processed = 0;
		$count = 0;

		foreach ($queries as $query) {
			foreach ($query->each() as $page) {
				/** @var ElementInterface $page */
				$processed++;
				if (!$this->isIndexable($page)) continue;

				$index->save($this->getDocument($page));
				$count++;
				if ($onIndexed) $onIndexed($page, $processed, $total);
			}
		}

		return $count;
	}

	/**
	 * Searches the search index in the query's site, the current one by default, for live pages
	 *
	 * @throws InvalidArgumentException if the query refers to facets that aren't defined
	 */
	public function search(SearchQuery $query): SearchResults
	{
		$facets = $this->getSearchSettings()->getFacets();

		$query = clone $query;
		$query->siteId ??= (int)Craft::$app->getSites()->getCurrentSite()->id;

		$event = new SearchEvent(['query' => $query]);
		if ($this->hasEventHandlers(self::EVENT_BEFORE_SEARCH)) {
			$this->trigger(self::EVENT_BEFORE_SEARCH, $event);
		}

		if ($event->results === null) {
			$query = $event->query;
			if (empty($query->synonyms) && $query->term !== '') {
				$query->synonyms = Plugin::getInstance()->getSynonyms()->getGroups((int)$query->siteId);
			}
			$query->filters = $this->normalizeFilters($query->filters, $facets);
			foreach ($query->facets as $handle) {
				if (!isset($facets[$handle])) {
					throw new InvalidArgumentException("Unknown facet `$handle`.");
				}
			}

			$event->results = $this->searchWithPins($query, $facets);
			$event->results->elements = $this->getHitElements($event->results->hits);
		}

		if ($this->hasEventHandlers(self::EVENT_AFTER_SEARCH)) {
			$this->trigger(self::EVENT_AFTER_SEARCH, $event);
		}

		if ($event->query->track) {
			Plugin::getInstance()->getSearchLog()->record($event->query, $event->results);
		}

		return $event->results;
	}

	/**
	 * Searches the index, with the pages pinned for the query's term first when they match its filters. Pinned pages take the
	 * first places of the results and move the others along, so pages of results, totals and facet counts include them once.
	 *
	 * @param array<string, Facet> $facets
	 */
	private function searchWithPins(SearchQuery $query, array $facets): SearchResults
	{
		$index = $this->requireIndex();
		$pinnedIds = $query->pinnedElementIds
			?? ($query->term !== '' ? Plugin::getInstance()->getPins()->getElementIds($query->term, (int)$query->siteId) : []);
		if ($query->elementIds !== null) {
			$pinnedIds = array_intersect($pinnedIds, $query->elementIds);
		}
		$pinnedIds = array_values(array_diff(array_map('intval', $pinnedIds), $query->excludedElementIds));
		if (empty($pinnedIds)) {
			return $index->search($query, $facets);
		}

		// The pinned pages that are live and match the filters, whatever the term, in their pinned order
		$pinnedQuery = clone $query;
		$pinnedQuery->term = '';
		$pinnedQuery->elementIds = $pinnedIds;
		$pinnedQuery->offset = 0;
		$pinnedQuery->limit = count($pinnedIds);
		$pinned = $index->search($pinnedQuery, $facets);
		$order = array_flip($pinnedIds);
		$pinnedHits = $pinned->hits;
		usort($pinnedHits, fn(array $a, array $b): int => $order[$a['elementId']] <=> $order[$b['elementId']]);
		$pinCount = count($pinnedHits);

		$otherQuery = clone $query;
		$otherQuery->excludedElementIds = [...$query->excludedElementIds, ...$pinnedIds];
		$shownPins = array_slice($pinnedHits, $query->offset, $query->limit);
		if ($query->offset < $pinCount) {
			$otherQuery->offset = 0;
			$otherQuery->limit = $query->limit - count($shownPins);
		} else {
			$otherQuery->offset = $query->offset - $pinCount;
		}
		$others = $index->search($otherQuery, $facets);

		$facetCounts = [];
		foreach ($query->facets as $handle) {
			$counts = $others->facetCounts[$handle] ?? [];
			foreach ($pinned->facetCounts[$handle] ?? [] as $value => $count) {
				$counts[$value] = ($counts[$value] ?? 0) + $count;
			}
			arsort($counts);
			$facetCounts[$handle] = $counts;
		}

		return new SearchResults(
			[...$shownPins, ...$others->hits],
			$pinCount + $others->total,
			$facetCounts,
			pinnedElementIds: array_map(fn(array $hit): int => $hit['elementId'], $pinnedHits),
		);
	}

	/**
	 * Creates a query for a page of results from request params: `q`, `filters` per facet handle (a value, a list of values,
	 * or a range with `min` and `max`), and `page`. Filters by facets that aren't defined, and empty filter inputs, are left out.
	 * All the facets are counted.
	 *
	 * @param array<string, mixed> $params
	 */
	public function createQuery(array $params): SearchQuery
	{
		$settings = $this->getSearchSettings();
		$facets = $settings->getFacets();

		$filters = [];
		foreach ((array)($params['filters'] ?? []) as $handle => $filter) {
			if (!isset($facets[$handle])) continue;

			if (is_array($filter)) {
				$filter = array_filter($filter, fn(mixed $value): bool => is_scalar($value) && $value !== '');
				if (!SearchQuery::isRange($filter)) {
					$filter = array_values($filter);
				}
			}
			if ($filter === '' || $filter === [] || !(is_scalar($filter) || is_array($filter))) continue;

			$filters[$handle] = $filter;
		}

		$perPage = max(1, $settings->searchResultsPerPage);
		$page = max(1, (int)($params['page'] ?? 1));

		return new SearchQuery(
			term: is_string($params['q'] ?? null) ? trim($params['q']) : '',
			filters: $filters,
			facets: array_keys($facets),
			limit: $perPage,
			offset: ($page - 1) * $perPage,
		);
	}

	/**
	 * Counts facet values for filters to pick from: every value the site's live pages have, each counted with the query's term
	 * and its filters by the other facets. A facet's own filter is left out of its counts, so they show what picking another
	 * of its values would find, and values nothing matches count 0.
	 *
	 * @param string[] $handles
	 * @return array<string, array<string|int, int>> Counts indexed by facet handle and value, in the order of the values' site-wide counts
	 */
	public function getFilterCounts(SearchQuery $query, array $handles): array
	{
		if (empty($handles)) return [];

		$totals = $this->search(new SearchQuery(siteId: $query->siteId, facets: $handles, limit: 0))->facetCounts;
		$narrowed = [];

		// Facets without a filter of their own are counted with all the query's filters at once, the others each without theirs
		$unfiltered = array_values(array_diff($handles, array_keys($query->filters)));
		if (!empty($unfiltered)) {
			$narrowed = $this->search(new SearchQuery($query->term, $query->siteId, $query->filters, $unfiltered, limit: 0))->facetCounts;
		}
		foreach (array_intersect($handles, array_keys($query->filters)) as $handle) {
			$filters = $query->filters;
			unset($filters[$handle]);
			$narrowed += $this->search(new SearchQuery($query->term, $query->siteId, $filters, [$handle], limit: 0))->facetCounts;
		}

		$counts = [];
		foreach ($handles as $handle) {
			foreach (array_keys($totals[$handle] ?? []) as $value) {
				$counts[$handle][$value] = $narrowed[$handle][$value] ?? 0;
			}
		}

		return $counts;
	}

	/**
	 * @param array<string|int, int> $counts Result counts, indexed by value
	 * @return array<int, array{value: string, label: string, count: int}>
	 */
	public function getFacetOptions(Facet $facet, array $counts): array
	{
		$values = array_map('strval', array_keys($counts));
		$labels = $this->getFacetValueLabels($facet, $values);

		return array_map(fn(string $value): array => [
			'value' => $value,
			'label' => $labels[$value] ?? $value,
			'count' => (int)$counts[$value],
		], $values);
	}

	/**
	 * Labels facet values: with the facet's own labels, element type names, element source names, the titles of the elements
	 * whose IDs they are, or formatted dates and booleans
	 *
	 * @param string[] $values
	 * @return array<string, string> Indexed by value
	 */
	public function getFacetValueLabels(Facet $facet, array $values): array
	{
		if (empty($values)) return [];

		$labels = match (true) {
			$facet->labels !== null => array_map('strval', ($facet->labels)($values)),
			$facet->handle === Facet::ELEMENT_TYPE => array_combine($values, array_map(
				fn(string $value): string => is_a($value, ElementInterface::class, true) ? $value::pluralDisplayName() : $value,
				$values,
			)),
			$facet->handle === Facet::SOURCE => array_combine($values, array_map($this->getSourceLabel(...), $values)),
			$facet->type === FacetType::KEYWORD => $this->getElementTitles($values),
			$facet->type === FacetType::BOOLEAN => array_combine($values, array_map(
				fn(string $value): string => $value === 'true' ? Craft::t('app', 'Yes') : Craft::t('app', 'No'),
				$values,
			)),
			$facet->type === FacetType::DATE => array_combine($values, array_map(
				fn(string $value): string => Craft::$app->getFormatter()->asDate($value),
				$values,
			)),
			default => [],
		};

		return $labels + array_combine($values, $values);
	}

	/**
	 * @return class-string<ElementInterface>[]
	 */
	public function getIndexedElementTypes(): array
	{
		$elementTypes = $this->getSearchSettings()->indexedElementTypes;
		if (!empty($elementTypes)) return $elementTypes;

		return array_values(array_filter(
			Craft::$app->getElements()->getAllElementTypes(),
			fn(string $elementType): bool => $elementType::hasUris(),
		));
	}

	/**
	 * Collects the pages of a content change, to push a job reindexing them all once the request ends
	 */
	public function queueReindex(SearchableContentChangeEvent $event): void
	{
		foreach ($event->pages as $siteId => $pages) {
			foreach ($pages as $page) {
				$this->pendingPages["$page->id:$siteId"] = [
					'elementId' => (int)$page->id,
					'elementType' => $page::class,
					'siteId' => (int)$siteId,
				];
			}
		}

		if (empty($this->pendingPages) || $this->isPushScheduled) return;

		$this->isPushScheduled = true;
		Craft::$app->onAfterRequest(function (): void {
			$this->pushPendingPages();
		});
	}

	/**
	 * Pushes a job reindexing the pages collected so far
	 */
	public function pushPendingPages(): void
	{
		if (empty($this->pendingPages)) return;

		Queue::push(new IndexPages([
			'pages' => array_values($this->pendingPages),
		]));
		$this->pendingPages = [];
	}

	/**
	 * Remembers the element's search state before it's saved, where only changed content is reindexed
	 */
	public function handleElementSaving(ElementEvent $event): void
	{
		$element = $event->element;
		if ($event->isNew || !$this->getSearchSettings()->reindexChangedContentOnly || !$this->isTracked($element)) return;

		$stored = Craft::$app->getElements()->getElementById((int)$element->id, $element::class, $element->siteId, ['status' => null]);
		if ($stored) {
			$this->searchStates[$this->getElementKey($element)] = $this->getSearchState($stored);
		}
	}

	public function handleElementSaved(ElementEvent $event): void
	{
		if (!$this->isTracked($event->element)) return;

		$key = $this->getElementKey($event->element);
		$before = $this->searchStates[$key] ?? null;
		unset($this->searchStates[$key]);

		// The pages above an element whose search state didn't change keep the same search documents
		if ($before !== null && $before === $this->getSearchState($event->element)) return;

		$this->triggerContentChange($event->element, FieldValueParser::getInstance()->getPages()->getAffectedPages($event->element), false);
	}

	/**
	 * What decides whether and how the element is found: its searchable values, status, URI, dates and facet values
	 *
	 * @return array<string, mixed>
	 */
	private function getSearchState(ElementInterface $element): array
	{
		$document = new SearchDocument($element, []);

		$facets = [];
		foreach ($this->getSearchSettings()->getFacets() as $handle => $facet) {
			if ($facet->appliesTo($element)) $facets[$handle] = $facet->getValues($element);
		}

		return [
			'keywords' => $this->flatten(FieldValueParser::getInstance()->getValues()->parse($element, Purpose::SEARCH)),
			'enabled' => (bool)$element->enabled,
			'enabledForSite' => (bool)$element->getEnabledForSite(),
			'uri' => $element->uri,
			'postDate' => Facet::formatDate($document->getPostDate()),
			'expiryDate' => Facet::formatDate($document->getExpiryDate()),
			'facets' => $facets,
			'excluded' => $this->isExcluded($element),
		];
	}

	public function handleElementDeleting(ElementEvent $event): void
	{
		if (!$this->isTracked($event->element)) return;

		$this->deletedElementPages[$this->getElementKey($event->element)] = FieldValueParser::getInstance()->getPages()->getAffectedPages($event->element);
	}

	public function handleElementDeleted(ElementEvent $event): void
	{
		$key = $this->getElementKey($event->element);
		if (!isset($this->deletedElementPages[$key])) return;

		$pages = $this->deletedElementPages[$key];
		unset($this->deletedElementPages[$key]);
		$this->triggerContentChange($event->element, $pages, true);
	}

	private function requireIndex(): SearchIndexInterface
	{
		return $this->getIndex() ?? throw new InvalidConfigException('No search index is configured, see the `searchIndex` setting.');
	}

	/**
	 * @param array<string, mixed> $filters
	 * @param array<string, Facet> $facets
	 * @return array<string, array<int|string, string|float|bool>> Values lists, or ranges with `min` and/or `max` keys
	 */
	private function normalizeFilters(array $filters, array $facets): array
	{
		$normalizedFilters = [];

		foreach ($filters as $handle => $filter) {
			$facet = $facets[$handle] ?? throw new InvalidArgumentException("Unknown facet `$handle`.");

			if (SearchQuery::isRange($filter)) {
				$range = array_filter([
					'min' => isset($filter['min']) ? $facet->normalizeValue($filter['min']) : null,
					'max' => isset($filter['max']) ? $facet->normalizeValue($filter['max']) : null,
				], fn(mixed $value): bool => $value !== null);
				if (!empty($range)) $normalizedFilters[$handle] = $range;
				continue;
			}

			$values = array_values(array_filter(
				array_map($facet->normalizeValue(...), is_array($filter) ? $filter : [$filter]),
				fn(mixed $value): bool => $value !== null,
			));
			if (!empty($values)) $normalizedFilters[$handle] = $values;
		}

		return $normalizedFilters;
	}

	/**
	 * @param array<int, array{elementId: int, siteId: int, score: float}> $hits
	 * @return ElementInterface[] The hits' live elements, in order
	 */
	private function getHitElements(array $hits): array
	{
		if (empty($hits)) return [];

		$elementsByKey = [];
		foreach ($this->groupIdsByElementType(array_column($hits, 'elementId')) as $elementType => $ids) {
			foreach (array_unique(array_column($hits, 'siteId')) as $siteId) {
				foreach ($elementType::find()->id($ids)->siteId($siteId)->all() as $element) {
					$elementsByKey["$element->id:$siteId"] = $element;
				}
			}
		}

		$elements = [];
		foreach ($hits as $hit) {
			$element = $elementsByKey["{$hit['elementId']}:{$hit['siteId']}"] ?? null;
			if ($element) $elements[] = $element;
		}

		return $elements;
	}

	/**
	 * @param string[] $values
	 * @return array<string, string> The titles of the elements whose IDs the values are, in the current site
	 */
	private function getElementTitles(array $values): array
	{
		$ids = array_values(array_filter($values, fn(string $value): bool => ctype_digit($value)));
		if (empty($ids)) return [];

		$titles = [];
		foreach ($this->groupIdsByElementType($ids) as $elementType => $elementIds) {
			foreach ($elementType::find()->id($elementIds)->status(null)->all() as $element) {
				$titles[(string)$element->id] = (string)$element;
			}
		}

		return $titles;
	}

	/**
	 * @param array<int|string> $ids
	 * @return array<class-string<ElementInterface>, int[]>
	 */
	private function groupIdsByElementType(array $ids): array
	{
		$elementTypes = (new Query())
			->select(['id', 'type'])
			->from(Table::ELEMENTS)
			->where(['id' => $ids])
			->pairs();

		$idsByType = [];
		foreach ($elementTypes as $id => $elementType) {
			$idsByType[$elementType][] = (int)$id;
		}

		return $idsByType;
	}

	private function getSourceLabel(string $sourceKey): string
	{
		[$sourceType, $uid] = array_pad(explode(':', $sourceKey, 2), 2, '');

		$name = match ($sourceType) {
			'section' => Craft::$app->getEntries()->getSectionByUid($uid)?->name,
			'group' => Craft::$app->getCategories()->getGroupByUid($uid)?->name ?? Craft::$app->getUserGroups()->getGroupByUid($uid)?->name,
			'volume' => Craft::$app->getVolumes()->getVolumeByUid($uid)?->name,
			'taggroup' => Craft::$app->getTags()->getTagGroupByUid($uid)?->name,
			default => null,
		};

		return $name !== null ? Craft::t('site', $name) : $sourceKey;
	}

	/**
	 * Content changes are only worked out for someone listening, and not for drafts, revisions or saves propagating other ones
	 */
	private function isTracked(ElementInterface $element): bool
	{
		return ($this->getIndex() !== null || $this->hasEventHandlers(self::EVENT_AFTER_CONTENT_CHANGE))
			&& $element->id
			&& !$element->propagating
			&& !ElementHelper::isDraftOrRevision($element);
	}

	/**
	 * @param array<int, array<int, ElementInterface>> $pages
	 */
	private function triggerContentChange(ElementInterface $element, array $pages, bool $deleted): void
	{
		if (empty($pages)) return;

		$event = new SearchableContentChangeEvent([
			'element' => $element,
			'pages' => $pages,
			'deleted' => $deleted,
		]);
		$this->trigger(self::EVENT_AFTER_CONTENT_CHANGE, $event);

		// Whether there's an index is decided as content changes, rather than when the plugin boots, so it can be set up while running
		if ($this->getIndex() !== null) $this->queueReindex($event);
	}

	/**
	 * The settings search depends on, which the plugin hosting search provides
	 */
	private function getSearchSettings(): SearchSettings
	{
		return Plugin::getInstance()->getSettings();
	}

	private function getElementKey(ElementInterface $element): string
	{
		return "$element->id:$element->siteId";
	}

	/**
	 * @param array<mixed> $parsed
	 * @return array<string, string> Non-empty keywords, indexed by the path of the value they come from
	 */
	private function flatten(array $parsed, string $prefix = ''): array
	{
		$keywords = [];
		foreach ($parsed as $key => $value) {
			if (is_array($value)) {
				$keywords += $this->flatten($value, "$prefix$key.");
				continue;
			}

			$value = trim((string)(is_scalar($value) ? $value : ''));
			if ($value !== '') $keywords["$prefix$key"] = $value;
		}

		return $keywords;
	}
}

<?php
declare(strict_types=1);

namespace ContentReactor\Search\Models;

use Closure;
use ContentReactor\Search\Enums\{
	FacetType,
	FacetWidth,
};
use ContentReactor\Search\Indexes\SearchIndexInterface;
use Craft;
use craft\base\{
	ElementInterface,
	Model,
};
use craft\elements\Entry;
use MarcusGaius\FieldValueParser\FieldValueParser;
use MarcusGaius\FieldValueParser\Settings\ConfigFileSettings;
use yii\base\InvalidConfigException;

/**
 * Search settings, stored in the project config and managed in the control panel with the system settings permission,
 * where administrative changes are allowed. `config/cr-search.php` overrides them.
 */
class SearchSettings extends Model implements ConfigFileSettings
{
	/**
	 * The search index storing pages' search documents, as a component config, e.g. `['class' => DatabaseIndex::class]`.
	 * Pages are reindexed from the queue once their searchable content changes.
	 * `null` leaves storing searchable content to others, through [[\ContentReactor\Search\Services\Search::EVENT_AFTER_CONTENT_CHANGE]].
	 *
	 * @var array<string, mixed>|class-string<SearchIndexInterface>|null
	 */
	public array|string|null $searchIndex = null;

	/**
	 * The element types whose pages are indexed, all element types with URIs when empty
	 *
	 * @var class-string<ElementInterface>[]
	 */
	public array $indexedElementTypes = [];

	/**
	 * Sources whose pages aren't indexed, as the `source` facet has them, e.g. `section:{uid}`, `group:{uid}` or `volume:{uid}`
	 *
	 * @var string[]
	 */
	public array $excludedSources = [];

	/**
	 * UIDs of the entry types whose entries aren't indexed
	 *
	 * @var string[]
	 */
	public array $excludedEntryTypes = [];

	/** Whether the search results endpoint is registered, once a search index is configured */
	public bool $searchEndpointEnabled = true;

	/** The site URI of the search results endpoint */
	public string $searchEndpointUri = 'search';

	public int $searchResultsPerPage = 20;

	/** A site template rendering the search results endpoint instead of the baseline one */
	public ?string $searchResultsTemplate = null;

	/** A site template rendering search forms instead of the baseline one */
	public ?string $searchFormTemplate = null;

	/** Whether searches from the search endpoint and GraphQL are logged for search analytics: their terms, sites and result counts */
	public bool $logSearches = true;

	/** How many days logged searches are kept, forever when 0. Older ones are deleted with Craft's garbage collection. */
	public int $searchLogDays = 90;

	/**
	 * The facets search results can be filtered and counted by, indexed by handle. They can only be set in `config/cr-search.php`,
	 * as changing them requires rebuilding the search index.
	 *
	 * ```php
	 * 'facets' => [
	 *     // A path of field and attribute handles resolved on each page, as a keyword
	 *     'units' => 'units.id',
	 *     // `width` is how much of a row the facet's filter wants in a search form, e.g. `full` for a date range
	 *     'published' => ['type' => 'date', 'value' => 'postDate', 'label' => 'Published', 'elementTypes' => [Entry::class], 'width' => 'full'],
	 *     'year' => ['type' => 'number', 'value' => fn(ElementInterface $page) => $page->postDate?->format('Y')],
	 * ],
	 * ```
	 *
	 * @var array<string, string|Closure|Facet|array{type?: string|FacetType, value?: string|Closure, elementTypes?: class-string<ElementInterface>[], label?: string, labels?: Closure, width?: string|FacetWidth}>
	 */
	public array $facets = [];

	/**
	 * Whether saving an element only reindexes pages when its searchable values, status, URI or facet values changed.
	 * They're compared before and after saving, which makes saves take longer.
	 */
	public bool $reindexChangedContentOnly = false;

	/**
	 * @return array<string, mixed> The settings `config/cr-search.php` sets, overriding the stored ones
	 */
	public function getConfigFileSettings(): array
	{
		return Craft::$app->getConfig()->getConfigFromFile('cr-search');
	}

	public function isOverriddenByConfig(string $attribute): bool
	{
		return array_key_exists($attribute, $this->getConfigFileSettings());
	}

	/**
	 * @return class-string<SearchIndexInterface>|null
	 */
	public function getSearchIndexType(): ?string
	{
		return match (true) {
			is_string($this->searchIndex) => $this->searchIndex,
			is_array($this->searchIndex) => $this->searchIndex['class'] ?? $this->searchIndex['__class'] ?? null,
			default => null,
		};
	}

	/**
	 * @return array<string, Facet> The built-in facets and the configured ones, indexed by handle
	 * @throws InvalidConfigException if a configured facet is invalid or uses a built-in facet's handle
	 */
	public function getFacets(): array
	{
		$facets = [
			Facet::ELEMENT_TYPE => new Facet(
				Facet::ELEMENT_TYPE,
				FacetType::KEYWORD,
				fn(ElementInterface $page): string => $page::class,
				label: Craft::t('cr-search', 'Type'),
			),
			Facet::SOURCE => new Facet(
				Facet::SOURCE,
				FacetType::KEYWORD,
				fn(ElementInterface $page): array => FieldValueParser::getInstance()->getPages()->getSourceKeys($page),
				label: Craft::t('cr-search', 'Source'),
			),
		];

		foreach ($this->facets as $handle => $config) {
			$handle = (string)$handle;
			if (isset($facets[$handle])) {
				throw new InvalidConfigException("The `$handle` facet handle is reserved.");
			}

			$facets[$handle] = $config instanceof Facet ? $config : Facet::create($handle, $config);
		}

		return $facets;
	}

	public function fields(): array
	{
		$fields = parent::fields();
		// Facets can have functions, which the project config can't store
		unset($fields['facets']);

		return $fields;
	}

	public function attributeLabels(): array
	{
		return [
			'reindexChangedContentOnly' => Craft::t('cr-search', 'Only Reindex Changed Content'),
			'searchIndex' => Craft::t('cr-search', 'Search Index'),
			'indexedElementTypes' => Craft::t('cr-search', 'Indexed Element Types'),
			'searchEndpointEnabled' => Craft::t('cr-search', 'Search Results Endpoint'),
			'searchEndpointUri' => Craft::t('cr-search', 'Search Results URI'),
			'searchResultsPerPage' => Craft::t('cr-search', 'Results Per Page'),
			'searchResultsTemplate' => Craft::t('cr-search', 'Search Results Template'),
			'searchFormTemplate' => Craft::t('cr-search', 'Search Form Template'),
			'excludedSources' => Craft::t('cr-search', 'Excluded Sources'),
			'excludedEntryTypes' => Craft::t('cr-search', 'Excluded Entry Types'),
			'logSearches' => Craft::t('cr-search', 'Log Searches'),
			'searchLogDays' => Craft::t('cr-search', 'Keep Logged Searches For'),
		];
	}

	/**
	 * @return array<mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['searchResultsPerPage'], 'integer', 'min' => 1],
			[['searchEndpointEnabled'], 'boolean'],
			[['searchEndpointUri'], 'filter', 'filter' => fn(mixed $uri): string => trim((string)$uri, " \t\n\r\0\x0B/")],
			[['searchEndpointUri'], 'required'],
			[['searchEndpointUri'], 'match', 'pattern' => '/^[a-zA-Z0-9\-_.~\/]+$/'],
			[['searchResultsTemplate', 'searchFormTemplate'], 'string'],
			[['searchIndex', 'indexedElementTypes', 'facets', 'excludedSources', 'excludedEntryTypes'], 'safe'],
			[['reindexChangedContentOnly', 'logSearches'], 'boolean'],
			[['searchLogDays'], 'integer', 'min' => 0],
		];
	}
}

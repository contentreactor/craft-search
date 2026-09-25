<?php
declare(strict_types=1);

namespace ContentReactor\Search\Indexes;

use ContentReactor\Search\Enums\FacetType;
use ContentReactor\Search\Models\{
	Facet,
	SearchDocument,
	SearchQuery,
	SearchResults,
};
use ContentReactor\Search\Plugin;
use Craft;
use craft\base\Component;
use craft\helpers\{
	App,
	Json,
};
use craft\web\View;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use yii\base\Exception;

/**
 * Stores search documents in an Elasticsearch index, one document per page and site.
 * Settings can be environment variables, e.g. `'url' => '$ELASTICSEARCH_URL'`.
 */
class ElasticsearchIndex extends Component implements SearchIndexInterface
{
	private const DATE_FORMAT = 'strict_date_time_no_millis';

	public string $url = 'http://elasticsearch:9200';

	/** The index name, which Elasticsearch requires to be lowercase */
	public string $index = 'craft-search';

	public ?string $username = null;

	public ?string $password = null;

	public ?string $apiKey = null;

	/** When changes become searchable: `false` with the index's next refresh, `wait_for` once they're searchable, `true` right away */
	public bool|string $refresh = false;

	/** How many values of each facet are counted */
	public int $facetSize = 100;

	/** @var array<string, mixed> Guzzle client options, merged over the ones built from the settings */
	public array $clientOptions = [];

	private ?Client $client = null;

	private bool $isPrepared = false;

	public static function displayName(): string
	{
		return 'Elasticsearch';
	}

	public function getSettingsHtml(): ?string
	{
		return Craft::$app->getView()->renderTemplate('cr-search/_components/search-indexes/elasticsearch.twig', [
			'index' => $this,
		], View::TEMPLATE_MODE_CP);
	}

	public function getConnectionError(): ?string
	{
		try {
			$response = $this->getClient()->request('GET', '', [
				'http_errors' => false,
				'timeout' => 5,
			]);
		} catch (Throwable $e) {
			return Craft::t('cr-search', 'Couldn’t connect to Elasticsearch: {error}', ['error' => $e->getMessage()]);
		}

		if ($response->getStatusCode() >= 300) {
			return Craft::t('cr-search', 'Elasticsearch responded with {status}: {body}', [
				'status' => $response->getStatusCode(),
				'body' => (string)$response->getBody(),
			]);
		}

		return null;
	}

	public function getDocumentCount(?int $siteId = null): int
	{
		$body = $siteId !== null ? ['query' => ['term' => ['siteId' => $siteId]]] : null;
		$response = $this->request('POST', "{$this->getIndexName()}/_count", $body, allowedStatuses: [404]);
		if ($response->getStatusCode() === 404) return 0;

		return (int)(Json::decode((string)$response->getBody())['count'] ?? 0);
	}

	public function prepare(array $facets): void
	{
		$index = $this->getIndexName();
		$mappings = ['properties' => $this->getProperties($facets)];

		// Existing fields' types can't change, which requires clearing the index first
		if ($this->request('HEAD', $index, allowedStatuses: [404])->getStatusCode() === 404) {
			$this->request('PUT', $index, ['mappings' => $mappings]);
		} else {
			$this->request('PUT', "$index/_mapping", $mappings);
		}

		$this->isPrepared = true;
	}

	public function save(SearchDocument $document): void
	{
		$this->ensureIndex();
		$page = $document->page;

		$this->request('PUT', "{$this->getIndexName()}/_doc/{$this->getDocumentId((int)$page->id, (int)$page->siteId)}", [
			'elementId' => $page->id,
			'siteId' => $page->siteId,
			'elementType' => $page::class,
			'keywords' => $document->getText(),
			'postDate' => Facet::formatDate($document->getPostDate()),
			'expiryDate' => Facet::formatDate($document->getExpiryDate()),
			'facets' => (object)$document->facets,
		], $this->getRefreshQuery());
	}

	public function delete(int $elementId, int $siteId): void
	{
		$this->request('DELETE', "{$this->getIndexName()}/_doc/{$this->getDocumentId($elementId, $siteId)}", query: $this->getRefreshQuery(), allowedStatuses: [404]);
	}

	public function clear(?int $siteId = null): void
	{
		$index = $this->getIndexName();

		if ($siteId === null) {
			$this->request('DELETE', $index, allowedStatuses: [404]);
			$this->isPrepared = false;
			return;
		}

		$this->request('POST', "$index/_delete_by_query", [
			'query' => ['term' => ['siteId' => $siteId]],
		], ['refresh' => 'true'], [404]);
	}

	public function search(SearchQuery $query, array $facets): SearchResults
	{
		$filters = [
			$this->getMissingOrInRange('postDate', ['lte' => 'now']),
			$this->getMissingOrInRange('expiryDate', ['gt' => 'now']),
		];
		if ($query->siteId !== null) {
			$filters[] = ['term' => ['siteId' => $query->siteId]];
		}
		if ($query->elementIds !== null) {
			$filters[] = ['terms' => ['elementId' => array_values($query->elementIds)]];
		}
		foreach ($query->filters as $handle => $filter) {
			$filters[] = SearchQuery::isRange($filter)
				? ['range' => ["facets.$handle" => array_filter([
					'gte' => $filter['min'] ?? null,
					'lte' => $filter['max'] ?? null,
				], fn(mixed $value): bool => $value !== null)]]
				: ['terms' => ["facets.$handle" => array_values((array)$filter)]];
		}

		$body = [
			'from' => $query->offset,
			'size' => $query->limit,
			'track_total_hits' => true,
			'_source' => ['elementId', 'siteId'],
			'query' => [
				'bool' => [
					'must' => $query->term !== '' ? $this->getTermClauses($query) : [['match_all' => (object)[]]],
					'filter' => $filters,
					'must_not' => !empty($query->excludedElementIds) ? [['terms' => ['elementId' => array_values($query->excludedElementIds)]]] : [],
				],
			],
			'sort' => [['_score' => 'desc'], ['elementId' => 'asc']],
		];

		foreach ($query->facets as $handle) {
			$body['aggs'][$handle] = ['terms' => ['field' => "facets.$handle", 'size' => $this->facetSize]];
		}

		$response = $this->request('POST', "{$this->getIndexName()}/_search", $body, allowedStatuses: [404]);
		if ($response->getStatusCode() === 404) return new SearchResults(facetCounts: array_fill_keys($query->facets, []));

		$data = Json::decode((string)$response->getBody());

		$hits = array_map(fn(array $hit): array => [
			'elementId' => (int)$hit['_source']['elementId'],
			'siteId' => (int)$hit['_source']['siteId'],
			'score' => (float)($hit['_score'] ?? 0),
		], $data['hits']['hits'] ?? []);

		$facetCounts = [];
		foreach ($query->facets as $handle) {
			$facetCounts[$handle] = [];
			foreach ($data['aggregations'][$handle]['buckets'] ?? [] as $bucket) {
				$facetCounts[$handle][(string)($bucket['key_as_string'] ?? $bucket['key'])] = (int)$bucket['doc_count'];
			}
		}

		return new SearchResults($hits, (int)($data['hits']['total']['value'] ?? 0), $facetCounts);
	}

	/**
	 * Uses the Guzzle client instead of one created from the settings with Craft's Guzzle client factory, e.g. to mock responses in tests
	 */
	public function setClient(Client $client): void
	{
		$this->client = $client;
	}

	public function getIndexName(): string
	{
		return strtolower((string)App::parseEnv($this->index));
	}

	/**
	 * @return array<mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['url', 'index'], 'required'],
			[['facetSize'], 'integer', 'min' => 1],
		];
	}

	/**
	 * Creates the index with the defined facets' mappings before documents are first stored in it,
	 * as Elasticsearch would otherwise guess the facets' types
	 */
	private function ensureIndex(): void
	{
		if ($this->isPrepared) return;

		if ($this->request('HEAD', $this->getIndexName(), allowedStatuses: [404])->getStatusCode() === 404) {
			$this->prepare(Plugin::getInstance()->getSettings()->getFacets());
			return;
		}

		$this->isPrepared = true;
	}

	/**
	 * @param array<string, string> $range
	 * @return array<string, mixed> A filter for documents without the date, or with it in the range
	 */
	private function getMissingOrInRange(string $field, array $range): array
	{
		return [
			'bool' => [
				'should' => [
					['bool' => ['must_not' => ['exists' => ['field' => $field]]]],
					['range' => [$field => $range]],
				],
				'minimum_should_match' => 1,
			],
		];
	}

	/**
	 * @param array<string, Facet> $facets
	 * @return array<string, mixed>
	 */
	private function getProperties(array $facets): array
	{
		$facetProperties = [];
		foreach ($facets as $handle => $facet) {
			$facetProperties[$handle] = match ($facet->type) {
				FacetType::KEYWORD => ['type' => 'keyword'],
				FacetType::NUMBER => ['type' => 'double'],
				FacetType::BOOLEAN => ['type' => 'boolean'],
				FacetType::DATE => ['type' => 'date', 'format' => self::DATE_FORMAT],
			};
		}

		return [
			'elementId' => ['type' => 'integer'],
			'siteId' => ['type' => 'integer'],
			'elementType' => ['type' => 'keyword'],
			'keywords' => ['type' => 'text'],
			'postDate' => ['type' => 'date', 'format' => self::DATE_FORMAT],
			'expiryDate' => ['type' => 'date', 'format' => self::DATE_FORMAT],
			'facets' => ['properties' => (object)$facetProperties],
		];
	}

	private function getDocumentId(int $elementId, int $siteId): string
	{
		return "$elementId-$siteId";
	}

	/**
	 * @return array<string, string>
	 */
	private function getRefreshQuery(): array
	{
		// The control panel saves the setting as a string
		return match ($this->refresh) {
			false, 'false', '' => [],
			true, 'true' => ['refresh' => 'true'],
			default => ['refresh' => (string)$this->refresh],
		};
	}

	/**
	 * @param array<string, mixed>|null $body Sent as JSON
	 * @param array<string, string> $query
	 * @param int[] $allowedStatuses Error statuses that aren't exceptions, e.g. 404 for things that don't exist
	 * @throws Exception if Elasticsearch responds with an error
	 */
	private function request(string $method, string $path, ?array $body = null, array $query = [], array $allowedStatuses = []): ResponseInterface
	{
		$options = [
			'http_errors' => false,
			'query' => $query,
		];
		if ($body !== null) {
			$options['body'] = Json::encode($body);
		}

		$response = $this->getClient()->request($method, $path, $options);
		$status = $response->getStatusCode();

		if ($status >= 300 && !in_array($status, $allowedStatuses, true)) {
			throw new Exception(sprintf('Elasticsearch responded to %s %s with %s: %s', $method, $path, $status, (string)$response->getBody()));
		}

		return $response;
	}

	private function getClient(): Client
	{
		if ($this->client !== null) return $this->client;

		$options = [
			'base_uri' => rtrim((string)App::parseEnv($this->url), '/') . '/',
			'headers' => ['Content-Type' => 'application/json'],
		];

		if ($this->apiKey !== null) {
			$options['headers']['Authorization'] = 'ApiKey ' . App::parseEnv($this->apiKey);
		} elseif ($this->username !== null) {
			$options['auth'] = [App::parseEnv($this->username), App::parseEnv((string)$this->password)];
		}

		return $this->client = Craft::createGuzzleClient(array_replace_recursive($options, $this->clientOptions));
	}

	/**
	 * Every word of the term has to match, each by itself or one of its synonyms
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function getTermClauses(SearchQuery $query): array
	{
		if (empty($query->synonyms)) {
			return [['match' => ['keywords' => ['query' => $query->term, 'operator' => 'and']]]];
		}

		$normalize = fn(string $term): string => mb_strtolower(trim($term));
		$clauses = [];
		foreach (preg_split('/\s+/u', $normalize($query->term), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
			$alternatives = $query->getWordAlternatives($word, $normalize);
			$clauses[] = count($alternatives) === 1
				? ['match' => ['keywords' => ['query' => $word, 'operator' => 'and']]]
				: ['bool' => [
					'should' => array_map(fn(string $alternative): array => ['match' => ['keywords' => ['query' => $alternative, 'operator' => 'and']]], $alternatives),
					'minimum_should_match' => 1,
				]];
		}

		return $clauses;
	}
}

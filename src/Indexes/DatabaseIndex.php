<?php
declare(strict_types=1);

namespace ContentReactor\Search\Indexes;

use ContentReactor\Search\migrations\Install;
use ContentReactor\Search\Models\{
	SearchDocument,
	SearchQuery,
	SearchResults,
};
use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\{
	Db,
	Search as SearchHelper,
};
use craft\web\View;
use DateTime;
use Throwable;
use yii\db\Expression;

/**
 * Stores search documents in the database: their normalized keywords in one table, their facet values in another.
 * On MySQL and PostgreSQL, words are matched with the database's full-text search where it has them.
 * On PostgreSQL, each site's documents are stemmed and searched in its language, see [[getTextSearchConfig()]].
 */
class DatabaseIndex extends Component implements SearchIndexInterface
{
	/**
	 * The words InnoDB full-text indexes leave out by default
	 */
	private const MYSQL_STOPWORDS = [
		'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it',
		'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'und', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'www',
	];

	/**
	 * Whether words are matched with the database's full-text search, `null` using it on MySQL and PostgreSQL.
	 * MySQL's full-text index only sees committed changes, so it can't find documents stored in a transaction that's still going on.
	 */
	public ?bool $useFullText = null;

	/** The shortest word matched with full-text search, shorter ones are looked for anywhere in documents. On MySQL, see `innodb_ft_min_token_size`. */
	public int $minFullTextWordLength = 3;

	/**
	 * PostgreSQL text search configurations to use instead of the ones matching sites' languages, indexed by language,
	 * e.g. `['de' => 'german_unaccent', 'pl-PL' => 'polish']`. The index needs rebuilding once they change.
	 *
	 * @var array<string, string>
	 */
	public array $textSearchConfigs = [];

	/**
	 * PostgreSQL's built-in text search configurations, by the languages they're for
	 */
	private const LANGUAGE_TEXT_SEARCH_CONFIGS = [
		'ar' => 'arabic', 'ca' => 'catalan', 'da' => 'danish', 'de' => 'german', 'el' => 'greek', 'en' => 'english', 'es' => 'spanish',
		'eu' => 'basque', 'fi' => 'finnish', 'fr' => 'french', 'ga' => 'irish', 'hi' => 'hindi', 'hu' => 'hungarian', 'hy' => 'armenian',
		'id' => 'indonesian', 'it' => 'italian', 'lt' => 'lithuanian', 'nb' => 'norwegian', 'ne' => 'nepali', 'nl' => 'dutch',
		'nn' => 'norwegian', 'no' => 'norwegian', 'pt' => 'portuguese', 'ro' => 'romanian', 'ru' => 'russian', 'sr' => 'serbian',
		'sv' => 'swedish', 'ta' => 'tamil', 'tr' => 'turkish', 'yi' => 'yiddish',
	];

	/** The text search configuration for languages without their own, which doesn't stem or drop words */
	public const DEFAULT_TEXT_SEARCH_CONFIG = 'simple';

	private int $paramCount = 0;

	/** @var array<int, string> Text search configurations by site ID */
	private array $siteTextSearchConfigs = [];

	/** @var string[]|null The text search configurations the database has */
	private ?array $availableTextSearchConfigs = null;

	public static function displayName(): string
	{
		return Craft::t('cr-search', 'Database');
	}

	public function getSettingsHtml(): ?string
	{
		return Craft::$app->getView()->renderTemplate('cr-search/_components/search-indexes/database.twig', [
			'index' => $this,
		], View::TEMPLATE_MODE_CP);
	}

	public function getConnectionError(): ?string
	{
		if (Craft::$app->getDb()->tableExists(Install::DOCUMENTS)) return null;

		return Craft::t('cr-search', 'The search index tables don’t exist. Reinstalling the plugin creates them.');
	}

	public function getDocumentCount(?int $siteId = null): int
	{
		return (int)(new Query())
			->from(Install::DOCUMENTS)
			->filterWhere(['siteId' => $siteId])
			->count();
	}

	public function prepare(array $facets): void
	{
		// The tables are created with the plugin, and store facet values the same way whatever their type
	}

	public function save(SearchDocument $document): void
	{
		$page = $document->page;
		$db = Craft::$app->getDb();
		$transaction = $db->beginTransaction();

		try {
			$this->delete((int)$page->id, (int)$page->siteId);

			Db::insert(Install::DOCUMENTS, [
				'elementId' => $page->id,
				'siteId' => $page->siteId,
				'elementType' => $page::class,
				'keywords' => $document->getNormalizedText(),
				'postDate' => Db::prepareDateForDb($document->getPostDate()),
				'expiryDate' => Db::prepareDateForDb($document->getExpiryDate()),
			]);
			// Not the last insert ID, which PostgreSQL only has by sequence name
			$documentId = (int)(new Query())
				->select(['id'])
				->from(Install::DOCUMENTS)
				->where(['elementId' => $page->id, 'siteId' => $page->siteId])
				->scalar();

			if ($db->getIsPgsql()) {
				$db->createCommand(sprintf(
					'UPDATE %s SET %s = %s WHERE %s = :id',
					$db->quoteTableName(Install::DOCUMENTS),
					$db->quoteColumnName('searchVector'),
					self::getSearchVectorSql(),
					$db->quoteColumnName('id'),
				), [':config' => $this->getTextSearchConfig((int)$page->siteId), ':id' => $documentId])->execute();
			}

			$rows = [];
			foreach ($document->facets as $handle => $values) {
				foreach ($values as $value) {
					$rows[] = [$documentId, $handle, $this->toFacetValue($value), $this->toNumericValue($value)];
				}
			}
			if (!empty($rows)) {
				Db::batchInsert(Install::FACETS, ['documentId', 'facet', 'value', 'numericValue'], $rows);
			}

			$transaction->commit();
		} catch (Throwable $e) {
			$transaction->rollBack();
			throw $e;
		}
	}

	public function delete(int $elementId, int $siteId): void
	{
		// Facet values are deleted with their documents
		Db::delete(Install::DOCUMENTS, [
			'elementId' => $elementId,
			'siteId' => $siteId,
		]);
	}

	public function clear(?int $siteId = null): void
	{
		Db::delete(Install::DOCUMENTS, $siteId === null ? '' : ['siteId' => $siteId]);
	}

	public function search(SearchQuery $query, array $facets): SearchResults
	{
		$now = Db::prepareDateForDb(new DateTime());
		$documents = (new Query())
			->from(['documents' => Install::DOCUMENTS])
			->where(['or', ['documents.postDate' => null], ['<=', 'documents.postDate', $now]])
			->andWhere(['or', ['documents.expiryDate' => null], ['>', 'documents.expiryDate', $now]]);

		if ($query->siteId !== null) {
			$documents->andWhere(['documents.siteId' => $query->siteId]);
		}
		if ($query->elementIds !== null) {
			$documents->andWhere(['documents.elementId' => $query->elementIds]);
		}
		if (!empty($query->excludedElementIds)) {
			$documents->andWhere(['not', ['documents.elementId' => $query->excludedElementIds]]);
		}

		foreach ($query->filters as $handle => $filter) {
			$documents->andWhere($this->getFilterCondition($handle, $filter));
		}

		$language = $query->siteId !== null ? Craft::$app->getSites()->getSiteById($query->siteId, true)?->language : null;
		$config = $this->getTextSearchConfig($query->siteId ?? (int)Craft::$app->getSites()->getPrimarySite()->id);
		$scores = [];
		$scoreParams = [];
		$normalize = fn(string $synonym): string => implode(' ', $this->getWords($synonym, $language));
		foreach ($this->getWords($query->term, $language) as $word) {
			// A word matches any of its synonyms. A stopword doesn't narrow the search, and its synonyms don't either.
			$matches = [];
			foreach ($query->getWordAlternatives($word, $normalize) as $alternative) {
				$match = $this->getWordMatch($alternative, $config);
				if ($match === null && $alternative === $word) {
					$matches = [];
					break;
				}
				if ($match !== null) {
					$matches[] = $match;
				}
			}
			if (empty($matches)) continue;

			$params = array_merge(...array_column($matches, 2));
			$documents->andWhere('(' . implode(' OR ', array_column($matches, 0)) . ')', $params);
			$scores[] = '(' . implode(' + ', array_column($matches, 1)) . ')';
			$scoreParams += $params;
		}

		$total = (int)(clone $documents)->count('*');

		$hits = array_map(fn(array $row): array => [
			'elementId' => (int)$row['elementId'],
			'siteId' => (int)$row['siteId'],
			'score' => (float)$row['score'],
		], (clone $documents)
			->select([
				'elementId' => 'documents.elementId',
				'siteId' => 'documents.siteId',
				'score' => new Expression(empty($scores) ? '0' : '(' . implode(' + ', $scores) . ')', $scoreParams),
			])
			->orderBy(['score' => SORT_DESC, 'documents.id' => SORT_ASC])
			->limit($query->limit)
			->offset($query->offset)
			->all());

		$facetCounts = [];
		$documentIds = (clone $documents)->select(['documents.id']);
		foreach ($query->facets as $handle) {
			$facetCounts[$handle] = array_map('intval', (new Query())
				->select(['facets.value', 'total' => 'COUNT(DISTINCT [[facets.documentId]])'])
				->from(['facets' => Install::FACETS])
				->where([
					'facets.facet' => $handle,
					'facets.documentId' => $documentIds,
				])
				->groupBy(['facets.value'])
				->orderBy(['total' => SORT_DESC, 'facets.value' => SORT_ASC])
				->pairs());
		}

		return new SearchResults($hits, $total, $facetCounts);
	}

	/**
	 * @return array<mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['minFullTextWordLength'], 'integer', 'min' => 1],
		];
	}

	/**
	 * @return string[] The term's distinct words, normalized the way document keywords are
	 */
	private function getWords(string $term, ?string $language): array
	{
		$normalized = SearchHelper::normalizeKeywords($term, language: $language);
		// Characters with a meaning in full-text boolean mode
		$normalized = preg_replace('/[+\-<>()~*"@]+/u', ' ', $normalized) ?? '';

		return array_values(array_unique(preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: []));
	}

	/**
	 * The PostgreSQL text search configuration a site's documents are stemmed and searched with: the `textSearchConfigs` setting's
	 * for its language, or the built-in one for it, falling back to `simple`
	 */
	public function getTextSearchConfig(int $siteId): string
	{
		if (isset($this->siteTextSearchConfigs[$siteId])) return $this->siteTextSearchConfigs[$siteId];

		$language = strtolower((string)Craft::$app->getSites()->getSiteById($siteId, true)?->language);
		$primaryLanguage = explode('-', $language)[0];
		$overrides = array_change_key_case($this->textSearchConfigs);
		$config = $overrides[$language] ?? $overrides[$primaryLanguage] ?? self::LANGUAGE_TEXT_SEARCH_CONFIGS[$primaryLanguage] ?? self::DEFAULT_TEXT_SEARCH_CONFIG;

		// Databases can leave some out, and overrides can name ones that don't exist
		if (!in_array($config, $this->getAvailableTextSearchConfigs(), true)) {
			Craft::warning("The `$config` text search configuration doesn't exist, so `" . self::DEFAULT_TEXT_SEARCH_CONFIG . "` is used for site $siteId.", __METHOD__);
			$config = self::DEFAULT_TEXT_SEARCH_CONFIG;
		}

		return $this->siteTextSearchConfigs[$siteId] = $config;
	}

	/**
	 * The SQL for a document's search vector, with a `:config` param. Only the start of long keywords fits in a vector.
	 */
	public static function getSearchVectorSql(): string
	{
		return sprintf('to_tsvector(CAST(:config AS regconfig), left([[keywords]], %d))', Install::TEXT_SEARCH_LENGTH);
	}

	/**
	 * @return string[]
	 */
	private function getAvailableTextSearchConfigs(): array
	{
		if (!Craft::$app->getDb()->getIsPgsql()) return [];

		return $this->availableTextSearchConfigs ??= (new Query())->select(['cfgname'])->from('pg_catalog.pg_ts_config')->column();
	}

	/**
	 * @return array{0: string, 1: string, 2: array<string, string>}|null The condition documents containing the word match, the SQL scoring them,
	 * and their params, or `null` for a word the text search configuration drops, like `the` in English
	 */
	private function getWordMatch(string $word, string $config): ?array
	{
		$param = ':fvpWord' . $this->paramCount++;

		if ($this->isFullTextWord($word)) {
			$db = Craft::$app->getDb();
			if ($db->getIsPgsql()) {
				// Words with characters a text search query gives a meaning to are looked for anywhere instead
				$lexeme = preg_replace('/[^\p{L}\p{N}_]+/u', '', $word);
				if ($lexeme === $word) {
					$configParam = ':fvpConfig' . $this->paramCount++;
					$params = [$param => "$word:*", $configParam => $config];
					$query = "to_tsquery(CAST($configParam AS regconfig), $param)";

					// Stopwords make an empty query, which nothing would match
					if ((int)$db->createCommand("SELECT numnode($query)", $params)->queryScalar() === 0) {
						return null;
					}

					return ["[[documents.searchVector]] @@ $query", "ts_rank([[documents.searchVector]], $query)", $params];
				}
			} else {
				$match = "MATCH([[documents.keywords]]) AGAINST($param IN BOOLEAN MODE)";
				return [$match, $match, [$param => "+$word*"]];
			}
		}

		return [
			"[[documents.keywords]] LIKE $param",
			"CASE WHEN [[documents.keywords]] LIKE $param THEN 1 ELSE 0 END",
			[$param => '%' . addcslashes($word, '%_\\') . '%'],
		];
	}

	private function isFullTextWord(string $word): bool
	{
		$db = Craft::$app->getDb();

		return ($this->useFullText ?? ($db->getIsMysql() || $db->getIsPgsql()))
			&& mb_strlen($word) >= $this->minFullTextWordLength
			// PostgreSQL's stopwords are left out of searches instead
			&& ($db->getIsPgsql() || !in_array($word, self::MYSQL_STOPWORDS, true));
	}

	/**
	 * @param array<int|string, string|float|bool> $filter
	 * @return array<mixed>
	 */
	private function getFilterCondition(string $handle, array $filter): array
	{
		$facetValues = (new Query())
			->from(['facets' => Install::FACETS])
			->where('[[facets.documentId]] = [[documents.id]]')
			->andWhere(['facets.facet' => $handle]);

		if (SearchQuery::isRange($filter)) {
			if (isset($filter['min'])) {
				$facetValues->andWhere(['>=', 'facets.numericValue', $this->toNumericValue($filter['min'])]);
			}
			if (isset($filter['max'])) {
				$facetValues->andWhere(['<=', 'facets.numericValue', $this->toNumericValue($filter['max'])]);
			}
		} else {
			$facetValues->andWhere(['facets.value' => array_map($this->toFacetValue(...), array_values($filter))]);
		}

		return ['exists', $facetValues];
	}

	private function toFacetValue(string|float|bool $value): string
	{
		if (is_bool($value)) return $value ? 'true' : 'false';

		return mb_substr((string)$value, 0, 255);
	}

	/**
	 * Numbers and dates, as timestamps, are also stored as numbers, so they can be filtered by ranges
	 */
	private function toNumericValue(string|float|bool $value): ?float
	{
		if (is_bool($value)) return null;
		if (is_float($value) || is_numeric($value)) return (float)$value;

		$timestamp = strtotime($value);
		return $timestamp === false ? null : (float)$timestamp;
	}
}

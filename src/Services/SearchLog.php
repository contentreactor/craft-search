<?php
declare(strict_types=1);

namespace ContentReactor\Search\Services;

use ContentReactor\Search\migrations\Install;
use ContentReactor\Search\Models\{
	SearchQuery,
	SearchResults,
};
use ContentReactor\Search\Plugin;
use Craft;
use craft\db\Query;
use craft\helpers\{
	DateTimeHelper,
	Db,
};
use DateInterval;
use DateTime;
use DateTimeInterface;
use Throwable;
use yii\base\Component;

/**
 * Logs searches for search analytics: what's searched for on each site, and what finds nothing.
 * Only terms, sites, result counts and times are logged, nothing identifying who searched.
 */
class SearchLog extends Component
{
	/**
	 * Logs a tracked search with a term, unless logging is turned off. Failing to log never fails the search.
	 */
	public function record(SearchQuery $query, SearchResults $results): void
	{
		$term = self::normalizeTerm($query->term);
		if (!$query->track || $term === '' || !Plugin::getInstance()->getSettings()->logSearches) return;

		try {
			Db::insert(Install::SEARCH_LOG, [
				'siteId' => $query->siteId ?? Craft::$app->getSites()->getCurrentSite()->id,
				'term' => $term,
				'resultCount' => $results->total,
				'dateCreated' => Db::prepareDateForDb(new DateTime()),
			]);
		} catch (Throwable $e) {
			Craft::warning("Couldn't log a search: {$e->getMessage()}", __METHOD__);
		}
	}

	/**
	 * How many searches were logged in the period, for how many distinct terms, and how many of them found nothing
	 *
	 * @return array{searches: int, terms: int, withoutResults: int}
	 */
	public function getSummary(?int $siteId, int $days): array
	{
		$row = $this->createQuery($siteId, $days)
			->select([
				'searches' => 'COUNT(*)',
				'terms' => 'COUNT(DISTINCT [[term]])',
				'withoutResults' => 'SUM(CASE WHEN [[resultCount]] = 0 THEN 1 ELSE 0 END)',
			])
			->one() ?: [];

		return [
			'searches' => (int)($row['searches'] ?? 0),
			'terms' => (int)($row['terms'] ?? 0),
			'withoutResults' => (int)($row['withoutResults'] ?? 0),
		];
	}

	/**
	 * The terms searched for most in the period
	 *
	 * @return array<int, array{term: string, searches: int, averageResults: float, lastSearched: DateTimeInterface}>
	 */
	public function getTopSearches(?int $siteId, int $days, int $limit = 25): array
	{
		return $this->getTermRows($this->createQuery($siteId, $days), $limit);
	}

	/**
	 * The terms searched for in the period that found nothing, the most searched first. They show what content,
	 * or which words for it, visitors are missing.
	 *
	 * @return array<int, array{term: string, searches: int, averageResults: float, lastSearched: DateTimeInterface}>
	 */
	public function getSearchesWithoutResults(?int $siteId, int $days, int $limit = 25): array
	{
		return $this->getTermRows($this->createQuery($siteId, $days)->andWhere(['resultCount' => 0]), $limit);
	}

	/**
	 * Deletes logged searches older than the search settings keep them for
	 *
	 * @return int How many were deleted
	 */
	public function prune(): int
	{
		$days = Plugin::getInstance()->getSettings()->searchLogDays;
		if ($days <= 0) return 0;

		return Db::delete(Install::SEARCH_LOG, ['<', 'dateCreated', Db::prepareDateForDb(self::getPeriodStart($days))]);
	}

	/**
	 * Lowercases a term and collapses its whitespace, so the same search is counted as one term
	 */
	public static function normalizeTerm(string $term): string
	{
		$term = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $term) ?? ''));

		return mb_substr($term, 0, 255);
	}

	private function createQuery(?int $siteId, int $days): Query
	{
		return (new Query())
			->from(Install::SEARCH_LOG)
			->where(['>=', 'dateCreated', Db::prepareDateForDb(self::getPeriodStart($days))])
			->andFilterWhere(['siteId' => $siteId]);
	}

	/**
	 * @return array<int, array{term: string, searches: int, averageResults: float, lastSearched: DateTimeInterface}>
	 */
	private function getTermRows(Query $query, int $limit): array
	{
		$rows = $query
			->select([
				'term',
				'searches' => 'COUNT(*)',
				'averageResults' => 'AVG([[resultCount]])',
				'lastSearched' => 'MAX([[dateCreated]])',
			])
			->groupBy(['term'])
			->orderBy(['searches' => SORT_DESC, 'lastSearched' => SORT_DESC, 'term' => SORT_ASC])
			->limit($limit)
			->all();

		return array_map(fn(array $row): array => [
			'term' => (string)$row['term'],
			'searches' => (int)$row['searches'],
			'averageResults' => round((float)$row['averageResults'], 1),
			// Dates are stored in UTC
			'lastSearched' => DateTimeHelper::toDateTime($row['lastSearched']) ?: new DateTime(),
		], $rows);
	}

	private static function getPeriodStart(int $days): DateTime
	{
		return (new DateTime())->sub(new DateInterval('P' . max(0, $days) . 'D'));
	}
}

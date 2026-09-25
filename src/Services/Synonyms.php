<?php
declare(strict_types=1);

namespace ContentReactor\Search\Services;

use ContentReactor\Search\migrations\Install;
use Craft;
use craft\db\Query;
use craft\helpers\{
	Db,
	Json,
};
use Throwable;
use yii\base\Component;

/**
 * Synonyms: groups of words that match each other in searches, e.g. `concert, gig, show`, for all sites or one.
 * They're stored in the database rather than the project config, so they can be managed wherever searches are analyzed.
 */
class Synonyms extends Component
{
	/** @var array<int, array<int, string[]>> Groups by site ID, loaded once per request */
	private array $groups = [];

	/**
	 * The synonym groups that apply to a site: its own, and the ones for all sites
	 *
	 * @return array<int, string[]>
	 */
	public function getGroups(int $siteId): array
	{
		return $this->groups[$siteId] ??= array_map(
			fn(string $terms): array => Json::decodeIfJson($terms) ?: [],
			(new Query())
				->select(['terms'])
				->from(Install::SYNONYMS)
				->where(['or', ['siteId' => null], ['siteId' => $siteId]])
				->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
				->column(),
		);
	}

	/**
	 * Every synonym group, for editing
	 *
	 * @return array<int, array{terms: string, siteId: string}> `terms` separated by commas, `siteId` empty for all sites
	 */
	public function getRows(): array
	{
		return array_map(fn(array $row): array => [
			'terms' => implode(', ', Json::decodeIfJson($row['terms']) ?: []),
			'siteId' => $row['siteId'] === null ? '' : (string)$row['siteId'],
		], (new Query())
			->select(['terms', 'siteId'])
			->from(Install::SYNONYMS)
			->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
			->all());
	}

	/**
	 * Replaces every synonym group with the given ones. Rows without terms are left out.
	 *
	 * @param array<int|string, array{terms?: string, siteId?: string|int|null}> $rows
	 * @return array<int, string> Errors by row index, nothing saved while there are any
	 */
	public function saveRows(array $rows): array
	{
		$groups = [];
		$errors = [];
		$sites = Craft::$app->getSites();

		foreach (array_values($rows) as $index => $row) {
			$terms = self::parseTerms((string)($row['terms'] ?? ''));
			if (empty($terms)) continue;

			$siteId = (int)($row['siteId'] ?? 0) ?: null;
			$multiWord = array_filter($terms, fn(string $term): bool => preg_match('/\s/u', $term) === 1);

			$errors[$index] = match (true) {
				count($terms) < 2 => Craft::t('cr-search', 'Synonyms need at least two words.'),
				!empty($multiWord) => Craft::t('cr-search', 'Synonyms are single words, so “{term}” can’t be one.', ['term' => reset($multiWord)]),
				$siteId !== null && $sites->getSiteById($siteId) === null => Craft::t('cr-search', 'The site doesn’t exist.'),
				default => '',
			};
			if ($errors[$index] === '') {
				unset($errors[$index]);
				$groups[] = ['siteId' => $siteId, 'terms' => $terms];
			}
		}

		if (!empty($errors)) return $errors;

		$transaction = Craft::$app->getDb()->beginTransaction();
		try {
			Db::delete(Install::SYNONYMS);
			foreach ($groups as $sortOrder => $group) {
				Db::insert(Install::SYNONYMS, [
					'siteId' => $group['siteId'],
					'terms' => Json::encode($group['terms']),
					'sortOrder' => $sortOrder,
				]);
			}
			$transaction->commit();
		} catch (Throwable $e) {
			$transaction->rollBack();
			throw $e;
		}

		$this->groups = [];

		return [];
	}

	/**
	 * Splits terms separated by commas or new lines, lowercased and without duplicates
	 *
	 * @return string[]
	 */
	public static function parseTerms(string $terms): array
	{
		$parsed = array_map(
			fn(string $term): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $term) ?? '')),
			preg_split('/[,\n]+/u', $terms) ?: [],
		);

		return array_values(array_unique(array_filter($parsed, fn(string $term): bool => $term !== '')));
	}
}

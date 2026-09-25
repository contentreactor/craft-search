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
use yii\base\Component;
use yii\db\Expression;

/**
 * Pinned results: pages shown first, in a set order, for a search term on all sites or one. Terms are matched whole,
 * ignoring case and extra whitespace, the way search analytics counts them.
 * They're stored in the database rather than the project config, so they can be managed wherever searches are analyzed.
 */
class Pins extends Component
{
	/** @var array<string, int[]> Pinned element IDs by site ID and term, loaded once per request */
	private array $elementIds = [];

	/**
	 * The pages pinned for a term on a site: its own pins first, then the ones for all sites
	 *
	 * @return int[]
	 */
	public function getElementIds(string $term, int $siteId): array
	{
		$term = SearchLog::normalizeTerm($term);
		if ($term === '') return [];

		return $this->elementIds["$siteId:$term"] ??= array_values(array_unique(array_merge(...array_map(
			fn(string $elementIds): array => array_map('intval', Json::decodeIfJson($elementIds) ?: []),
			(new Query())
				->select(['elementIds'])
				->from(Install::PINS)
				->where(['term' => $term])
				->andWhere(['or', ['siteId' => $siteId], ['siteId' => null]])
				// Rows with a site first
				->orderBy(new Expression('CASE WHEN [[siteId]] IS NULL THEN 1 ELSE 0 END, [[id]]'))
				->column(),
		) ?: [[]])));
	}

	/**
	 * @return array<int, array{id: int, term: string, siteId: int|null, elementIds: int[]}>
	 */
	public function getAll(): array
	{
		return array_map($this->toPin(...), (new Query())
			->select(['id', 'term', 'siteId', 'elementIds'])
			->from(Install::PINS)
			->orderBy(['term' => SORT_ASC, 'siteId' => SORT_ASC])
			->all());
	}

	/**
	 * @return array{id: int, term: string, siteId: int|null, elementIds: int[]}|null
	 */
	public function getById(int $id): ?array
	{
		$row = (new Query())
			->select(['id', 'term', 'siteId', 'elementIds'])
			->from(Install::PINS)
			->where(['id' => $id])
			->one();

		return $row ? $this->toPin($row) : null;
	}

	/**
	 * Saves pinned results, new ones without an `id`
	 *
	 * @param array{id?: int|null, term: string, siteId?: int|null, elementIds: int[]} $pin
	 * @return array<string, string> Errors by attribute, nothing saved while there are any
	 */
	public function save(array $pin): array
	{
		$id = isset($pin['id']) ? (int)$pin['id'] : null;
		$term = SearchLog::normalizeTerm($pin['term']);
		$siteId = !empty($pin['siteId']) ? (int)$pin['siteId'] : null;
		$elementIds = array_values(array_unique(array_map('intval', $pin['elementIds'])));

		$errors = [];
		if ($term === '') {
			$errors['term'] = Craft::t('cr-search', 'Enter the search term the results are pinned for.');
		} elseif ($this->exists($term, $siteId, $id)) {
			$errors['term'] = Craft::t('cr-search', 'Results are already pinned for “{term}” there.', ['term' => $term]);
		}
		if (empty($elementIds)) {
			$errors['elementIds'] = Craft::t('cr-search', 'Choose the pages to pin.');
		}
		if ($siteId !== null && Craft::$app->getSites()->getSiteById($siteId) === null) {
			$errors['siteId'] = Craft::t('cr-search', 'The site doesn’t exist.');
		}
		if (!empty($errors)) return $errors;

		$row = ['term' => $term, 'siteId' => $siteId, 'elementIds' => Json::encode($elementIds)];
		if ($id !== null) {
			Db::update(Install::PINS, $row, ['id' => $id]);
		} else {
			Db::insert(Install::PINS, $row);
		}
		$this->elementIds = [];

		return [];
	}

	public function delete(int $id): void
	{
		Db::delete(Install::PINS, ['id' => $id]);
		$this->elementIds = [];
	}

	private function exists(string $term, ?int $siteId, ?int $exceptId): bool
	{
		$query = (new Query())->from(Install::PINS)->where(['term' => $term, 'siteId' => $siteId]);
		if ($exceptId !== null) {
			$query->andWhere(['not', ['id' => $exceptId]]);
		}

		return $query->exists();
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{id: int, term: string, siteId: int|null, elementIds: int[]}
	 */
	private function toPin(array $row): array
	{
		return [
			'id' => (int)$row['id'],
			'term' => (string)$row['term'],
			'siteId' => $row['siteId'] !== null ? (int)$row['siteId'] : null,
			'elementIds' => array_map('intval', Json::decodeIfJson($row['elementIds']) ?: []),
		];
	}
}

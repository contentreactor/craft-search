<?php
declare(strict_types=1);

namespace ContentReactor\Search\Jobs;

use ContentReactor\Search\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\queue\BaseJob;

/**
 * Reindexes pages, removing the ones that were deleted or can no longer be indexed
 */
class IndexPages extends BaseJob
{
	/** @var array<int, array{elementId: int, elementType: class-string<ElementInterface>, siteId: int}> */
	public array $pages = [];

	public function execute($queue): void
	{
		$search = Plugin::getInstance()->getSearch();
		if ($search->getIndex() === null) return;

		$total = count($this->pages);
		foreach (array_values($this->pages) as $i => $page) {
			$this->setProgress($queue, $i / $total, Craft::t('cr-search', '{step, number} of {total, number}', [
				'step' => $i + 1,
				'total' => $total,
			]));
			$search->indexPageById($page['elementId'], $page['elementType'], $page['siteId']);
		}
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t('cr-search', 'Updating the search index');
	}
}

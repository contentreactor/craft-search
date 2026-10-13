<?php
declare(strict_types=1);

namespace ContentReactor\Search\Jobs;

use ContentReactor\Search\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\queue\BaseJob;

/**
 * Rebuilds the search index from every page of the indexed element types
 */
class RebuildIndex extends BaseJob
{
	/** The site to rebuild the index for, all of them when `null` */
	public ?int $siteId = null;

	public function execute($queue): void
	{
		$search = Plugin::getInstance()->getSearch();
		if ($search->getIndex() === null) return;

		$search->rebuild($this->siteId, function (ElementInterface $page, int $processed, int $total) use ($queue): void {
			$this->setProgress($queue, $processed / max(1, $total), Craft::t('cr-search', '{step, number} of {total, number}', [
				'step' => $processed,
				'total' => $total,
			]));
		});
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t('cr-search', 'Rebuilding the search index');
	}
}

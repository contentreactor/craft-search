<?php
declare(strict_types=1);

namespace ContentReactor\Search\Console\Controllers;

use ContentReactor\Search\Plugin;
use craft\base\ElementInterface;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Manages the search index
 */
class IndexController extends Controller
{
	public $defaultAction = 'rebuild';

	/** The ID of the site to rebuild or clear the index for, all sites when left out */
	public ?int $siteId = null;

	public function options($actionID): array
	{
		return [...parent::options($actionID), 'siteId'];
	}

	/**
	 * Rebuilds the search index from every page of the indexed element types
	 */
	public function actionRebuild(): int
	{
		$search = Plugin::getInstance()->getSearch();
		if ($search->getIndex() === null) {
			$this->stderr('No search index is configured, see the `searchIndex` setting.' . PHP_EOL, Console::FG_RED);
			return ExitCode::CONFIG;
		}

		$count = $search->rebuild($this->siteId, function (ElementInterface $page): void {
			$this->stdout("Indexed $page->uri ($page->id) in site $page->siteId" . PHP_EOL);
		});

		$this->stdout("Indexed $count pages." . PHP_EOL, Console::FG_GREEN);

		return ExitCode::OK;
	}

	/**
	 * Removes every document from the search index
	 */
	public function actionClear(): int
	{
		$index = Plugin::getInstance()->getSearch()->getIndex();
		if ($index === null) {
			$this->stderr('No search index is configured, see the `searchIndex` setting.' . PHP_EOL, Console::FG_RED);
			return ExitCode::CONFIG;
		}

		$index->clear($this->siteId);
		$this->stdout('Cleared the search index.' . PHP_EOL, Console::FG_GREEN);

		return ExitCode::OK;
	}
}

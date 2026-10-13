<?php
declare(strict_types=1);

namespace ContentReactor\Search\Controllers;

use ContentReactor\Search\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * The search analytics screen of the control panel
 */
class AnalyticsController extends Controller
{
	/** The periods analytics can be shown for, in days */
	public const PERIODS = [7, 30, 90, 365];

	protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) return false;

		$this->requireCpRequest();
		$this->requirePermission(Plugin::getAccessPermission());
		$this->requirePermission(Plugin::PERMISSION_ANALYTICS);
		Plugin::getInstance()->requirePro(Craft::t(Plugin::HANDLE, 'Search analytics'));

		return true;
	}

	/**
	 * Query params: `days`, one of the periods, and `siteId`, all sites when left out
	 */
	public function actionIndex(): Response
	{
		$plugin = Plugin::getInstance();
		$log = $plugin->getSearchLog();

		$days = (int)$this->request->getQueryParam('days', 30);
		$days = in_array($days, self::PERIODS, true) ? $days : 30;
		$siteId = (int)$this->request->getQueryParam('siteId') ?: null;
		if ($siteId !== null && Craft::$app->getSites()->getSiteById($siteId) === null) {
			$siteId = null;
		}

		return $this->renderTemplate('cr-search/settings/analytics.twig', [
			'days' => $days,
			'periods' => self::PERIODS,
			'siteId' => $siteId,
			'summary' => $log->getSummary($siteId, $days),
			'topSearches' => $log->getTopSearches($siteId, $days),
			'searchesWithoutResults' => $log->getSearchesWithoutResults($siteId, $days),
			'settings' => $plugin->getSettings(),
		]);
	}
}

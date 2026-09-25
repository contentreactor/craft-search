<?php
declare(strict_types=1);

namespace ContentReactor\Search\Controllers;

use ContentReactor\Search\Indexes\SearchIndexInterface;
use ContentReactor\Search\Jobs\RebuildIndex;
use ContentReactor\Search\Models\SearchSettings;
use ContentReactor\Search\Plugin;
use Craft;
use craft\base\Model;
use craft\helpers\{
	Html,
	Queue,
	UrlHelper,
};
use craft\web\Controller;
use MarcusGaius\FieldValueParser\Helpers\SettingsHelper;
use yii\web\{
	BadRequestHttpException,
	ForbiddenHttpException,
	Response,
};

/**
 * The search screens of the control panel settings area Field Value Parser lists
 */
class SettingsController extends Controller
{
	/**
	 * Actions requiring the system settings permission
	 */
	private const SYSTEM_ACTIONS = ['setup', 'settings', 'save-setup', 'save-settings'];

	/**
	 * The system settings the setup sets, besides the search index
	 */
	private const SETUP_SETTINGS = ['indexedElementTypes', 'searchEndpointEnabled', 'searchEndpointUri', 'searchResultsPerPage'];

	public $defaultAction = 'index';

	protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) return false;

		$this->requireCpRequest();
		// Craft only requires it for the plugin's control panel section, not its actions
		$this->requirePermission(Plugin::getAccessPermission());
		$this->requirePermission(in_array($action->id, self::SYSTEM_ACTIONS, true)
			? Plugin::PERMISSION_SETTINGS_SYSTEM
			: Plugin::PERMISSION_SETTINGS);

		return true;
	}

	public function actionIndex(): Response
	{
		return $this->renderTemplate('cr-search/settings/index.twig');
	}

	public function actionFacets(): Response
	{
		return $this->renderTemplate('cr-search/settings/facets.twig');
	}

	public function actionSetup(?SearchSettings $settings = null, ?SearchIndexInterface $searchIndex = null, ?bool $searchEnabled = null): Response
	{
		$settings ??= Plugin::getInstance()->getSettings();

		return $this->renderTemplate('cr-search/settings/setup.twig', [
			'settings' => $settings,
			'searchIndex' => $searchIndex,
			'searchEnabled' => $searchEnabled ?? $settings->searchIndex !== null,
		]);
	}

	/**
	 * Saves the search setup: disabling search, or the search index, checked to be usable, the indexed pages and the search results endpoint,
	 * optionally building the search index from the queue
	 */
	public function actionSaveSetup(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAdminChanges();

		$settings = Plugin::getInstance()->getSettings();

		if (!$this->request->getBodyParam('searchEnabled')) {
			if (!SettingsHelper::savePluginSettings(Plugin::getInstance(), ['searchIndex' => null, 'searchEndpointEnabled' => false])) {
				return $this->asFailure(Craft::t('cr-search', 'Couldn’t save settings.'), routeParams: ['settings' => $settings, 'searchEnabled' => false]);
			}

			return $this->asSuccess(Craft::t('cr-search', 'Search is turned off.'), redirect: UrlHelper::cpUrl('cr-search'));
		}

		[$indexConfig, $searchIndex] = $this->getPostedSearchIndex();
		$routeParams = ['settings' => $settings, 'searchIndex' => $searchIndex, 'searchEnabled' => true];

		if ($searchIndex === null) {
			return $this->asFailure(Craft::t('cr-search', 'Choose where the search index is stored.'), routeParams: $routeParams);
		}
		if ($searchIndex instanceof Model && !$searchIndex->validate()) {
			return $this->asFailure(Craft::t('cr-search', 'Couldn’t save settings.'), routeParams: $routeParams);
		}
		if (($connectionError = $searchIndex->getConnectionError()) !== null) {
			return $this->asFailure($connectionError, routeParams: $routeParams);
		}

		$changes = SettingsHelper::castToPropertyTypes(
			SearchSettings::class,
			array_intersect_key((array)$this->request->getBodyParam('settings', []), array_flip(self::SETUP_SETTINGS)),
		);
		$changes['searchIndex'] = $indexConfig;

		if (!SettingsHelper::savePluginSettings(Plugin::getInstance(), $changes)) {
			return $this->asFailure(Craft::t('cr-search', 'Couldn’t save settings.'), routeParams: $routeParams);
		}

		if ($this->request->getBodyParam('rebuild')) {
			Queue::push(new RebuildIndex());
			$message = Craft::t('cr-search', 'Search is set up, and the search index is being built.');
		}

		return $this->asSuccess(
			$message ?? Craft::t('cr-search', 'Search is set up.'),
			redirect: UrlHelper::cpUrl('cr-search'),
		);
	}

	public function actionRebuildIndex(): Response
	{
		$this->requirePostRequest();

		if (Plugin::getInstance()->getSearch()->getIndex() === null) {
			throw new BadRequestHttpException('No search index is configured.');
		}

		Queue::push(new RebuildIndex());

		return $this->asSuccess(Craft::t('cr-search', 'The search index is being rebuilt.'));
	}

	public function actionSettings(?SearchSettings $settings = null, ?SearchIndexInterface $searchIndex = null): Response
	{
		return $this->renderTemplate('cr-search/settings/settings.twig', [
			'settings' => $settings ?? Plugin::getInstance()->getSettings(),
			'searchIndex' => $searchIndex,
		]);
	}

	public function actionSaveSettings(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAdminChanges();

		$settings = Plugin::getInstance()->getSettings();
		$before = ['excludedSources' => $settings->excludedSources, 'excludedEntryTypes' => $settings->excludedEntryTypes];
		$changes = SettingsHelper::castToPropertyTypes(SearchSettings::class, (array)$this->request->getBodyParam('settings', []));
		unset($changes['facets']);
		[$changes['searchIndex'], $searchIndex] = $this->getPostedSearchIndex();

		if (($searchIndex instanceof Model && !$searchIndex->validate()) || !SettingsHelper::savePluginSettings(Plugin::getInstance(), $changes)) {
			return $this->asFailure(
				Craft::t('cr-search', 'Couldn’t save settings.'),
				routeParams: [
					'settings' => $settings,
					'searchIndex' => $searchIndex,
				],
			);
		}

		// Pages that are excluded now, or not anymore, are removed from the index or added to it
		$saved = Plugin::getInstance()->getSettings();
		if ($saved->searchIndex !== null && ($before['excludedSources'] != $saved->excludedSources || $before['excludedEntryTypes'] != $saved->excludedEntryTypes)) {
			Queue::push(new RebuildIndex());
			return $this->asSuccess(Craft::t('cr-search', 'Settings saved, and the search index is being rebuilt.'));
		}

		return $this->asSuccess(Craft::t('cr-search', 'Settings saved.'));
	}

	/**
	 * @throws ForbiddenHttpException where the project config can't be changed
	 */
	private function requireAdminChanges(): void
	{
		if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
			throw new ForbiddenHttpException('Administrative changes are disallowed in this environment.');
		}
	}

	/**
	 * @return array{0: array<string, mixed>|null, 1: SearchIndexInterface|null} The posted search index's config, and the index created from it
	 * @throws BadRequestHttpException for search index types that aren't registered
	 */
	private function getPostedSearchIndex(): array
	{
		$indexType = $this->request->getBodyParam('searchIndexType') ?: null;
		if ($indexType === null) return [null, null];

		if (!in_array($indexType, Plugin::getInstance()->getSearch()->getIndexTypes(), true)) {
			throw new BadRequestHttpException('Invalid search index type.');
		}

		$config = [
			'class' => $indexType,
			...SettingsHelper::castToPropertyTypes($indexType, (array)$this->request->getBodyParam('searchIndexTypes.' . Html::id($indexType), [])),
		];

		return [$config, Craft::createObject($config)];
	}
}

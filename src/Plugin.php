<?php
declare(strict_types=1);

namespace ContentReactor\Search;

use ContentReactor\Search\Fields\HideFromSearch;
use ContentReactor\Search\Gql\SearchQueries;
use ContentReactor\Search\Models\SearchSettings;
use ContentReactor\Search\Services\{
	Pins,
	Search,
	SearchLog,
	Synonyms,
};
use ContentReactor\Search\Web\Twig\{
	Extension,
	Variable,
};
use Craft;
use craft\base\{
	Model,
	Plugin as BasePlugin,
};
use craft\events\{
	RegisterComponentTypesEvent,
	RegisterGqlQueriesEvent,
	RegisterGqlSchemaComponentsEvent,
	RegisterTemplateRootsEvent,
	RegisterUrlRulesEvent,
	RegisterUserPermissionsEvent,
};
use craft\helpers\UrlHelper;
use craft\services\{
	Elements,
	Fields,
	Gc,
	Gql,
	UserPermissions,
};
use craft\web\{
	UrlManager,
	View,
};
use craft\web\twig\variables\CraftVariable;
use MarcusGaius\FieldValueParser\FieldValueParser;
use MarcusGaius\FieldValueParser\Traits\Editions;
use yii\base\Event;
use yii\queue\Queue;
use yii\web\Response;

/**
 * Search for the pages Field Value Parser parses, kept up to date as their content changes
 *
 * @property-read Search $search
 * @property-read SearchLog $searchLog
 * @property-read Synonyms $synonyms
 * @property-read Pins $pins
 * @method static Plugin getInstance()
 * @method SearchSettings getSettings()
 * @author ContentReactor <support@contentreactor.com>
 * @license MIT
 */
class Plugin extends BasePlugin
{
	// Lite: the database index, facets, excluded pages, the search results endpoint and GraphQL. Pro: Elasticsearch, synonyms,
	// pinned results and search analytics.
	use Editions;

	public const HANDLE = 'cr-search';

	public const PERMISSION_SETTINGS = 'cr-search:settings';

	public const PERMISSION_SETTINGS_SYSTEM = 'cr-search:settings:system';

	public const PERMISSION_ANALYTICS = 'cr-search:analytics';

	public const PERMISSION_SYNONYMS = 'cr-search:synonyms';

	public const PERMISSION_PINS = 'cr-search:pins';

	/**
	 * The control panel screens, in the order they're listed, with the controller actions rendering them
	 */
	private const SCREENS = [
		'search' => ['label' => 'Search', 'icon' => 'magnifying-glass', 'url' => 'cr-search', 'action' => 'cr-search/settings/index', 'permission' => self::PERMISSION_SETTINGS],
		'analytics' => ['label' => 'Analytics', 'icon' => 'chart-simple', 'url' => 'cr-search/analytics', 'action' => 'cr-search/analytics/index', 'permission' => self::PERMISSION_ANALYTICS, 'pro' => true],
		'synonyms' => ['label' => 'Synonyms', 'icon' => 'arrows-left-right', 'url' => 'cr-search/synonyms', 'action' => 'cr-search/synonyms/index', 'permission' => self::PERMISSION_SYNONYMS, 'pro' => true],
		'pins' => ['label' => 'Pinned Results', 'icon' => 'thumbtack', 'url' => 'cr-search/pins', 'action' => 'cr-search/pins/index', 'permission' => self::PERMISSION_PINS, 'pro' => true],
		'facets' => ['label' => 'Facets', 'icon' => 'filter', 'url' => 'cr-search/facets', 'action' => 'cr-search/settings/facets', 'permission' => self::PERMISSION_SETTINGS],
		'setup' => ['label' => 'Setup', 'icon' => 'wand-magic-sparkles', 'url' => 'cr-search/setup', 'action' => 'cr-search/settings/setup', 'permission' => self::PERMISSION_SETTINGS_SYSTEM],
		'settings' => ['label' => 'Settings', 'icon' => 'sliders', 'url' => 'cr-search/settings', 'action' => 'cr-search/settings/settings', 'permission' => self::PERMISSION_SETTINGS_SYSTEM],
	];

	public string $schemaVersion = '1.0.4';
	public bool $hasCpSection = true;
	public bool $hasCpSettings = true;

	/**
	 * @return array{components: array<string, class-string>}
	 */
	public static function config(): array
	{
		return [
			'components' => [
				'search' => Search::class,
				'searchLog' => SearchLog::class,
				'synonyms' => Synonyms::class,
				'pins' => Pins::class,
			],
		];
	}

	public function init(): void
	{
		parent::init();
		Craft::setAlias('@cr-search', __DIR__);

		FieldValueParser::boot();
		Craft::$app->getView()->registerTwigExtension(new Extension());

		$this->controllerNamespace = Craft::$app->getRequest()->getIsConsoleRequest()
			? __NAMESPACE__ . '\\Console\\Controllers'
			: __NAMESPACE__ . '\\Controllers';

		Craft::$app->onInit(function (): void {
			$this->attachEventHandlers();
		});
	}

	private function attachEventHandlers(): void
	{
		Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, $this->getSearch()->handleElementSaving(...));
		Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $this->getSearch()->handleElementSaved(...));
		Event::on(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT, $this->getSearch()->handleElementDeleting(...));
		Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, $this->getSearch()->handleElementDeleted(...));

		// Queue workers can keep running for longer than a request, pushing the pages their jobs changed after each job
		Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, fn() => $this->getSearch()->pushPendingPages());

		Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event): void {
			/** @var CraftVariable $variable */
			$variable = $event->sender;
			$variable->set('crSearch', Variable::class);
		});

		Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, function (RegisterTemplateRootsEvent $event): void {
			$event->roots[self::HANDLE] = __DIR__ . '/Templates';
		});
		Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, function (RegisterTemplateRootsEvent $event): void {
			$event->roots[self::HANDLE] = __DIR__ . '/Templates/site';
		});

		Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event): void {
			foreach (self::SCREENS as $screen) {
				$event->rules[$screen['url']] = $screen['action'];
			}
			$event->rules['cr-search/pins/new'] = 'cr-search/pins/edit';
			$event->rules['cr-search/pins/<pinId:\\d+>'] = 'cr-search/pins/edit';
		});
		Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function (RegisterUrlRulesEvent $event): void {
			// Left out while turned off or without a search index, freeing up the URI
			if ($this->getSearch()->isEndpointEnabled()) {
				$event->rules[$this->getSettings()->searchEndpointUri] = 'cr-search/search/index';
			}
		});

		// Editors can keep pages out of search with a field
		Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function (RegisterComponentTypesEvent $event): void {
			$event->types[] = HideFromSearch::class;
		});

		// Logged searches are kept for as long as the settings say
		Event::on(Gc::class, Gc::EVENT_RUN, fn() => $this->getSearchLog()->prune());

		// GraphQL searches are allowed per schema
		Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS, function (RegisterGqlSchemaComponentsEvent $event): void {
			$event->queries[$this->getPluginName()] = [
				SearchQueries::COMPONENT . ':read' => ['label' => Craft::t(self::HANDLE, 'Search the pages of the sections, volumes and groups the schema can read')],
			];
		});
		Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_QUERIES, function (RegisterGqlQueriesEvent $event): void {
			$event->queries = [...$event->queries, ...SearchQueries::getQueries()];
		});

		Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function (RegisterUserPermissionsEvent $event): void {
			$pluginName = $this->getPluginName();
			$settingsPermissions = [
				self::PERMISSION_ANALYTICS => [
					'label' => Craft::t(self::HANDLE, 'View search analytics'),
					'info' => Craft::t(self::HANDLE, 'What’s searched for, and what finds nothing.'),
				],
				self::PERMISSION_SYNONYMS => [
					'label' => Craft::t(self::HANDLE, 'Manage synonyms'),
					'info' => Craft::t(self::HANDLE, 'Words that match each other in searches.'),
				],
				self::PERMISSION_PINS => [
					'label' => Craft::t(self::HANDLE, 'Manage pinned results'),
					'info' => Craft::t(self::HANDLE, 'Pages shown first for search terms.'),
				],
				self::PERMISSION_SETTINGS => [
					'label' => Craft::t(self::HANDLE, 'Manage settings'),
					'nested' => [
						self::PERMISSION_SETTINGS_SYSTEM => [
							'label' => Craft::t(self::HANDLE, 'Manage system settings'),
							'info' => Craft::t(self::HANDLE, 'The search setup, the search index and the search results endpoint, where administrative changes are allowed.'),
						],
					],
				],
			];

			// Craft's permission to access the plugin shows its nav item, so the plugin's own permissions are nested under it
			$accessPermission = self::getAccessPermission();
			foreach (array_keys($event->permissions) as $group) {
				if (!isset($event->permissions[$group]['permissions'][$accessPermission])) continue;

				$event->permissions[$group]['permissions'][$accessPermission] = [
					...$event->permissions[$group]['permissions'][$accessPermission],
					'label' => Craft::t('app', 'Access {plugin}', ['plugin' => $pluginName]),
					'nested' => [...($event->permissions[$group]['permissions'][$accessPermission]['nested'] ?? []), ...$settingsPermissions],
				];

				return;
			}

			// Editions without control panel permissions for other users
			$event->permissions[] = [
				'heading' => $pluginName,
				'permissions' => $settingsPermissions,
			];
		});
	}

	public function getSearch(): Search
	{
		return $this->get('search');
	}

	public function getSearchLog(): SearchLog
	{
		return $this->get('searchLog');
	}

	public function getSynonyms(): Synonyms
	{
		return $this->get('synonyms');
	}

	public function getPins(): Pins
	{
		return $this->get('pins');
	}

	/**
	 * The name of the control panel section
	 */
	public function getPluginName(): string
	{
		return Craft::t(self::HANDLE, 'Search');
	}

	/**
	 * Craft's permission to access the plugin's control panel section, which the plugin's own permissions are nested under
	 */
	public static function getAccessPermission(): string
	{
		return 'accessPlugin-' . self::HANDLE;
	}

	public function getSettingsResponse(): Response
	{
		return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('cr-search'));
	}

	public function getCpNavItem(): ?array
	{
		$subnav = $this->getNavItems();
		if (empty($subnav)) return null;

		$item = parent::getCpNavItem();
		$item['label'] = $this->getPluginName();
		$item['url'] = reset($subnav)['url'];
		$item['subnav'] = $subnav;

		return $item;
	}

	/**
	 * The screens the current user can access
	 *
	 * @return array<string, array{label: string, url: string, icon: string}> Indexed by screen handle
	 */
	public function getNavItems(): array
	{
		$user = Craft::$app->getUser();
		$items = [];

		foreach (self::SCREENS as $handle => $screen) {
			if (!$user->checkPermission($screen['permission'])) continue;
			if (($screen['pro'] ?? false) && !$this->isPro()) continue;

			$items[$handle] = [
				'label' => Craft::t(self::HANDLE, $screen['label']),
				'url' => $screen['url'],
				'icon' => $screen['icon'],
			];
		}

		return $items;
	}

	public function afterSaveSettings(): void
	{
		parent::afterSaveSettings();

		// The search index follows the settings
		$this->getSearch()->resetIndex();
	}

	protected function createSettingsModel(): ?Model
	{
		return new SearchSettings();
	}
}

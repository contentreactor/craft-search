<?php
declare(strict_types=1);

namespace ContentReactor\Search\Console\Controllers;

use ContentReactor\Search\Indexes\{
	ElasticsearchIndex,
	SearchIndexInterface,
};
use ContentReactor\Search\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\console\Controller;
use craft\helpers\{
	Console,
	StringHelper,
};
use MarcusGaius\FieldValueParser\Helpers\SettingsHelper;
use yii\console\ExitCode;

/**
 * Sets up search: whether it's enabled, where the search index is stored, which pages are indexed, and the search results endpoint
 */
class SetupController extends Controller
{
	public $defaultAction = 'index';

	/**
	 * Sets up search, asking for its settings
	 */
	public function actionIndex(): int
	{
		if (!$this->interactive) {
			$this->stderr('The search setup asks for its settings, so it can’t run non-interactively.' . PHP_EOL, Console::FG_RED);
			return ExitCode::USAGE;
		}

		if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
			$this->stderr('Administrative changes are disallowed in this environment, so search can only be set up where they’re allowed.' . PHP_EOL, Console::FG_RED);
			return ExitCode::CONFIG;
		}

		$plugin = Plugin::getInstance();
		$settings = $plugin->getSettings();
		$search = $plugin->getSearch();

		if (!$this->confirm('Enable search?', $settings->searchIndex !== null)) {
			return $this->save(['searchIndex' => null, 'searchEndpointEnabled' => false], 'Search is turned off.');
		}

		// Where the search index is stored
		$indexTypes = [];
		foreach ($search->getIndexTypes() as $indexType) {
			$indexTypes[StringHelper::toKebabCase($indexType::displayName())] = $indexType;
		}
		$indexTypeKey = $this->select('Where should the search index be stored?', array_map(
			fn(string $indexType): string => $indexType::displayName(),
			$indexTypes,
		));
		$indexType = $indexTypes[$indexTypeKey];

		$indexConfig = ['class' => $indexType];
		if ($settings->getSearchIndexType() === $indexType && is_array($settings->searchIndex)) {
			$indexConfig = $settings->searchIndex;
		}

		do {
			$indexConfig = [...$indexConfig, ...$this->promptIndexSettings($indexType, $indexConfig)];
			/** @var SearchIndexInterface $index */
			$index = Craft::createObject($indexConfig);
			$connectionError = $index->getConnectionError();

			if ($connectionError !== null) {
				$this->stderr($connectionError . PHP_EOL, Console::FG_RED);
				if (!$this->confirm('Change the settings and try again?', true)) {
					return ExitCode::UNSPECIFIED_ERROR;
				}
			}
		} while ($connectionError !== null);

		// Which pages are indexed
		$uriElementTypes = array_values(array_filter(
			Craft::$app->getElements()->getAllElementTypes(),
			fn(string $elementType): bool => $elementType::hasUris(),
		));
		$indexedElementTypes = [];
		$allTypesQuestion = sprintf(
			'Index the pages of every element type with URIs (%s)?',
			implode(', ', array_map(fn(string $elementType): string => $elementType::pluralDisplayName(), $uriElementTypes)),
		);
		if (!$this->confirm($allTypesQuestion, empty($settings->indexedElementTypes))) {
			foreach ($uriElementTypes as $elementType) {
				$isIndexed = empty($settings->indexedElementTypes) || in_array($elementType, $settings->indexedElementTypes, true);
				if ($this->confirm("Index {$elementType::pluralDisplayName()}?", $isIndexed)) {
					$indexedElementTypes[] = $elementType;
				}
			}
		}

		// The search results endpoint
		$changes = [
			'searchIndex' => $indexConfig,
			'indexedElementTypes' => $indexedElementTypes,
			'searchEndpointEnabled' => $this->confirm('Register the search results endpoint?', $settings->searchEndpointEnabled),
		];
		if ($changes['searchEndpointEnabled']) {
			$changes['searchEndpointUri'] = trim($this->prompt('Search results URI:', [
				'default' => $settings->searchEndpointUri,
				'required' => true,
				'pattern' => '/^\/?[a-zA-Z0-9\-_.~\/]+$/',
			]), '/');
			$changes['searchResultsPerPage'] = (int)$this->prompt('Results per page:', [
				'default' => (string)$settings->searchResultsPerPage,
				'pattern' => '/^[1-9]\d*$/',
			]);
		}

		$exitCode = $this->save($changes, 'Search is set up.');
		if ($exitCode !== ExitCode::OK) return $exitCode;

		if ($this->confirm('Build the search index now?', true)) {
			$count = $search->rebuild(null, function (ElementInterface $page, int $processed, int $total): void {
				if ($processed === 1) Console::startProgress(0, $total);
				Console::updateProgress($processed, $total);
			});
			Console::endProgress();
			$this->stdout("Indexed $count pages." . PHP_EOL, Console::FG_GREEN);
		}

		return ExitCode::OK;
	}

	/**
	 * @param class-string<SearchIndexInterface> $indexType
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	private function promptIndexSettings(string $indexType, array $config): array
	{
		if ($indexType !== ElasticsearchIndex::class) {
			// Other index types' settings are managed in the control panel
			return [];
		}

		$defaults = new ElasticsearchIndex();
		$settings = [
			'url' => $this->prompt('Elasticsearch URL, or an environment variable like $ELASTICSEARCH_URL:', [
				'default' => $config['url'] ?? $defaults->url,
				'required' => true,
			]),
			'index' => $this->prompt('Index name:', [
				'default' => $config['index'] ?? $defaults->index,
				'required' => true,
			]),
			'apiKey' => $this->prompt('API key, preferably an environment variable (leave empty for none):', [
				'default' => $config['apiKey'] ?? '',
			]) ?: null,
		];

		if ($settings['apiKey'] === null) {
			$settings['username'] = $this->prompt('Username (leave empty for none):', ['default' => $config['username'] ?? '']) ?: null;
			$settings['password'] = $settings['username'] !== null
				? ($this->prompt('Password, preferably an environment variable:', ['default' => $config['password'] ?? '']) ?: null)
				: null;
		}

		return $settings;
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	private function save(array $changes, string $message): int
	{
		$overridden = array_keys(array_intersect_key($changes, Plugin::getInstance()->getSettings()->getConfigFileSettings()));
		foreach ($overridden as $setting) {
			$this->stdout("`$setting` is set in config/cr-search.php, which overrides it." . PHP_EOL, Console::FG_YELLOW);
		}

		if (!SettingsHelper::savePluginSettings(Plugin::getInstance(), $changes)) {
			$errors = Plugin::getInstance()->getSettings()->getFirstErrors();
			$this->stderr('Couldn’t save the settings: ' . implode(' ', $errors) . PHP_EOL, Console::FG_RED);
			return ExitCode::UNSPECIFIED_ERROR;
		}

		$this->stdout($message . PHP_EOL, Console::FG_GREEN);

		return ExitCode::OK;
	}
}

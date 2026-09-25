<?php
declare(strict_types=1);

namespace ContentReactor\Search\Controllers;

use ContentReactor\Search\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * The synonyms screen of the control panel
 */
class SynonymsController extends Controller
{
	protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) return false;

		$this->requireCpRequest();
		$this->requirePermission(Plugin::getAccessPermission());
		$this->requirePermission(Plugin::PERMISSION_SYNONYMS);

		return true;
	}

	/**
	 * @param array<int, array{terms: string, siteId: string}>|null $rows Posted rows, when they couldn't be saved
	 * @param array<int, string> $rowErrors
	 */
	public function actionIndex(?array $rows = null, array $rowErrors = []): Response
	{
		return $this->renderTemplate('cr-search/settings/synonyms.twig', [
			'rows' => $rows ?? Plugin::getInstance()->getSynonyms()->getRows(),
			'rowErrors' => $rowErrors,
		]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$rows = array_values((array)$this->request->getBodyParam('synonyms', []));
		$errors = Plugin::getInstance()->getSynonyms()->saveRows($rows);

		if (!empty($errors)) {
			return $this->asFailure(Craft::t('cr-search', 'Couldn’t save synonyms.'), routeParams: [
				'rows' => $rows,
				'rowErrors' => $errors,
			]);
		}

		return $this->asSuccess(Craft::t('cr-search', 'Synonyms saved.'));
	}
}

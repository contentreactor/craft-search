<?php
declare(strict_types=1);

namespace ContentReactor\Search\Controllers;

use ContentReactor\Search\Plugin;
use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\{
	NotFoundHttpException,
	Response,
};

/**
 * The pinned results screens of the control panel. Entries can be pinned there, other pages from code.
 */
class PinsController extends Controller
{
	protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) return false;

		$this->requireCpRequest();
		$this->requirePermission(Plugin::getAccessPermission());
		$this->requirePermission(Plugin::PERMISSION_PINS);
		Plugin::getInstance()->requirePro(Craft::t(Plugin::HANDLE, 'Pinned results'));

		return true;
	}

	public function actionIndex(): Response
	{
		$pins = Plugin::getInstance()->getPins()->getAll();
		$elementIds = array_merge([], ...array_column($pins, 'elementIds'));
		$elements = [];
		foreach (Craft::$app->getElements()->createElementQuery(Entry::class)->id($elementIds)->site('*')->unique()->status(null)->all() as $element) {
			$elements[$element->id] = $element;
		}

		return $this->renderTemplate('cr-search/settings/pins.twig', [
			'pins' => array_map(fn(array $pin): array => [
				...$pin,
				'elements' => array_values(array_filter(array_map(fn(int $id) => $elements[$id] ?? null, $pin['elementIds']))),
			], $pins),
		]);
	}

	/**
	 * @param array{id?: int|null, term: string, siteId?: int|null, elementIds: int[]}|null $pin Posted values, when they couldn't be saved
	 * @param array<string, string> $errors
	 * @throws NotFoundHttpException
	 */
	public function actionEdit(?int $pinId = null, ?array $pin = null, array $errors = []): Response
	{
		if ($pin === null && $pinId !== null) {
			$pin = Plugin::getInstance()->getPins()->getById($pinId) ?? throw new NotFoundHttpException('Pinned results not found.');
		}
		// New pins can start from a term, e.g. one search analytics lists
		$pin ??= ['term' => (string)$this->request->getQueryParam('term', ''), 'siteId' => null, 'elementIds' => []];

		$elements = !empty($pin['elementIds'])
			? Entry::find()->id($pin['elementIds'])->fixedOrder()->site('*')->unique()->status(null)->all()
			: [];

		return $this->renderTemplate('cr-search/settings/pin.twig', [
			'pin' => $pin,
			'elements' => $elements,
			'errors' => $errors,
		]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$pin = [
			'id' => ((int)$this->request->getBodyParam('pinId')) ?: null,
			'term' => (string)$this->request->getBodyParam('term', ''),
			'siteId' => ((int)$this->request->getBodyParam('siteId')) ?: null,
			'elementIds' => array_map('intval', array_filter((array)$this->request->getBodyParam('elementIds', []))),
		];
		$errors = Plugin::getInstance()->getPins()->save($pin);

		if (!empty($errors)) {
			return $this->asFailure(Craft::t('cr-search', 'Couldn’t save the pinned results.'), routeParams: [
				'pin' => $pin,
				'errors' => $errors,
			]);
		}

		return $this->asSuccess(Craft::t('cr-search', 'Pinned results saved.'), redirect: UrlHelper::cpUrl('cr-search/pins'));
	}

	public function actionDelete(): Response
	{
		$this->requirePostRequest();
		Plugin::getInstance()->getPins()->delete((int)$this->request->getRequiredBodyParam('pinId'));

		return $this->asSuccess(Craft::t('cr-search', 'Pinned results deleted.'), redirect: UrlHelper::cpUrl('cr-search/pins'));
	}
}

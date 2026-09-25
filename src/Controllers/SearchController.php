<?php
declare(strict_types=1);

namespace ContentReactor\Search\Controllers;

use ContentReactor\Search\Plugin;
use craft\base\ElementInterface;
use craft\web\{
	Controller,
	View,
};
use yii\web\{
	NotFoundHttpException,
	Response,
};

/**
 * The search results endpoint, registered at the configured URI while it's enabled and a search index is configured
 */
class SearchController extends Controller
{
	public $defaultAction = 'index';

	protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

	public function beforeAction($action): bool
	{
		if (!Plugin::getInstance()->getSearch()->isEndpointEnabled()) {
			throw new NotFoundHttpException();
		}

		return parent::beforeAction($action);
	}

	/**
	 * Renders a page of search results, or returns them as JSON for requests accepting it.
	 * Query params: `q`, `filters[facetHandle]` (a value, values, or `min` and `max`), and `page`.
	 */
	public function actionIndex(): Response
	{
		$plugin = Plugin::getInstance();
		$search = $plugin->getSearch();
		$settings = $plugin->getSettings();

		$query = $search->createQuery($this->request->getQueryParams());
		$query->track = true;
		$results = $search->search($query);
		$page = intdiv($query->offset, $query->limit) + 1;
		$pageCount = max(1, (int)ceil($results->total / $query->limit));

		$facets = $settings->getFacets();
		$facetOptions = [];
		foreach ($results->facetCounts as $handle => $counts) {
			$facetOptions[$handle] = $search->getFacetOptions($facets[$handle], $counts);
		}

		if ($this->request->getAcceptsJson()) {
			return $this->asJson([
				'term' => $query->term,
				'filters' => $query->filters,
				'total' => $results->total,
				'page' => $page,
				'pageCount' => $pageCount,
				'results' => array_map(fn(ElementInterface $element): array => [
					'id' => $element->id,
					'title' => (string)$element,
					'url' => $element->getUrl(),
				], $results->elements),
				'facets' => $facetOptions,
			]);
		}

		return $this->renderTemplate($settings->searchResultsTemplate ?: 'cr-search/results', [
			'query' => $query,
			'results' => $results,
			'page' => $page,
			'pageCount' => $pageCount,
			'facets' => $facets,
			'facetOptions' => $facetOptions,
		], View::TEMPLATE_MODE_SITE);
	}
}

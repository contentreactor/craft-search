<?php
declare(strict_types=1);

namespace ContentReactor\Search\Web\Twig;

use ContentReactor\Search\Plugin;
use Craft;
use craft\web\View;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class Extension extends AbstractExtension
{
	public function getFunctions(): array
	{
		return [
			new TwigFunction('crSearchForm', $this->searchForm(...), ['is_safe' => ['html']]),
		];
	}

	/**
	 * Renders a search form, keeping the current request's term and filters, e.g. `{{ crSearchForm({facets: ['units']}) }}`.
	 * Renders nothing while no search index is configured.
	 *
	 * @param array<string, mixed> $options `facets`: handles of the facets to filter by; `action`: the URL the form is submitted to,
	 * the search endpoint's by default; `placeholder`; anything else is passed on to the template
	 */
	public function searchForm(array $options = []): string
	{
		$plugin = Plugin::getInstance();
		$search = $plugin->getSearch();
		if ($search->getIndex() === null) return '';

		$settings = $plugin->getSettings();
		$facets = $settings->getFacets();
		$handles = array_values(array_filter((array)($options['facets'] ?? []), fn(mixed $handle): bool => is_string($handle) && isset($facets[$handle])));

		$request = Craft::$app->getRequest();
		$query = $search->createQuery($request->getIsConsoleRequest() ? [] : $request->getQueryParams());

		// The options are the values the site's live pages have, counted with the request's term and other filters
		$counts = $search->getFilterCounts($query, $handles);

		$formFacets = array_map(fn(string $handle): array => [
			'facet' => $facets[$handle],
			'options' => $search->getFacetOptions($facets[$handle], $counts[$handle] ?? []),
			'selected' => (array)($query->filters[$handle] ?? []),
		], $handles);

		return Craft::$app->getView()->renderTemplate($settings->searchFormTemplate ?: 'cr-search/form', [
			...$options,
			'action' => $options['action'] ?? $search->getSearchUrl(),
			'term' => $query->term,
			'facets' => $formFacets,
			'placeholder' => $options['placeholder'] ?? Craft::t('cr-search', 'Search'),
		], View::TEMPLATE_MODE_SITE);
	}
}

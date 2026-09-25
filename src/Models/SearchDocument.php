<?php
declare(strict_types=1);

namespace ContentReactor\Search\Models;

use craft\base\{
	Component,
	ElementInterface,
};
use craft\helpers\Search as SearchHelper;
use DateTimeInterface;

/**
 * The searchable content of a page, in the page's site: its own, its nested elements', and that of the elements displayed on it
 */
final class SearchDocument
{
	/**
	 * @param ElementInterface $page An element with a URI
	 * @param array<string, string> $keywords Indexed by the path of the value they come from, e.g. `fields.blocks.0.fields.text`
	 * @param array<string, array<int, string|float|bool>> $facets The page's facet values, indexed by facet handle
	 */
	public function __construct(
		public readonly ElementInterface $page,
		public readonly array $keywords,
		public readonly array $facets = [],
	) {}

	/**
	 * The keywords as plain text, without the HTML of rich text values
	 */
	public function getText(): string
	{
		$text = preg_replace('/<[^>]*>/', ' ', implode(' ', $this->keywords)) ?? '';

		return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
	}

	/**
	 * The keywords normalized the way Craft normalizes its own search index keywords
	 */
	public function getNormalizedText(): string
	{
		return SearchHelper::normalizeKeywords($this->getText(), language: $this->page->getSite()->language);
	}

	/**
	 * The date the page goes live, for pages of element types that have one
	 */
	public function getPostDate(): ?DateTimeInterface
	{
		return $this->getPageDate('postDate');
	}

	/**
	 * The date the page stops being live, for pages of element types that have one
	 */
	public function getExpiryDate(): ?DateTimeInterface
	{
		return $this->getPageDate('expiryDate');
	}

	private function getPageDate(string $attribute): ?DateTimeInterface
	{
		$date = $this->page instanceof Component && $this->page->canGetProperty($attribute) ? $this->page->$attribute : null;

		return $date instanceof DateTimeInterface ? $date : null;
	}
}

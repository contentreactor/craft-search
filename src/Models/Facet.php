<?php
declare(strict_types=1);

namespace ContentReactor\Search\Models;

use BackedEnum;
use Closure;
use ContentReactor\Search\Enums\{
	FacetType,
	FacetWidth,
};
use craft\base\ElementInterface;
use craft\fields\data\OptionData;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use MarcusGaius\FieldValueParser\FieldValueParser;
use Stringable;
use yii\base\InvalidConfigException;
use yii\helpers\Inflector;

/**
 * A value of pages that search results can be filtered and counted by
 */
final class Facet
{
	/** The page's element type, which every document has */
	public const ELEMENT_TYPE = 'elementType';

	/** The keys of the page's element sources, e.g. `section:{uid}`, which every document has */
	public const SOURCE = 'source';

	/** The name shown with the facet's filters */
	public readonly string $label;

	/**
	 * @param string|Closure(ElementInterface): mixed $value A path of field and attribute handles resolved on the page, e.g. `units.title`,
	 * or a function returning the page's values
	 * @param class-string<ElementInterface>[] $elementTypes The element types of the pages the facet applies to, all of them when empty
	 * @param Closure(string[]): array<string, string>|null $labels Returns labels for the facet's values, indexed by value.
	 * Without it, values that are element IDs are labeled by their elements.
	 * @param FacetWidth $width How much of a row the facet's filter wants in a search form
	 * @throws InvalidConfigException if the handle can't be used as a field name by search indexes
	 */
	public function __construct(
		public readonly string $handle,
		public readonly FacetType $type,
		public readonly string|Closure $value,
		public readonly array $elementTypes = [],
		?string $label = null,
		public readonly ?Closure $labels = null,
		public readonly FacetWidth $width = FacetWidth::AUTO,
	) {
		if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $handle)) {
			throw new InvalidConfigException("Invalid facet handle `$handle`: facet handles can only contain letters, numbers and underscores.");
		}

		$this->label = $label ?? Inflector::camel2words($handle);
	}

	/**
	 * @param string|Closure|array{type?: string|FacetType, value?: string|Closure, elementTypes?: class-string<ElementInterface>[], label?: string, labels?: Closure, width?: string|FacetWidth} $config
	 * A path or function for keyword facets, or the facet's config. The path defaults to the handle.
	 */
	public static function create(string $handle, string|Closure|array $config): self
	{
		if (!is_array($config)) {
			$config = ['value' => $config];
		}
		$type = $config['type'] ?? FacetType::KEYWORD;
		$width = $config['width'] ?? FacetWidth::AUTO;
		if (!$width instanceof FacetWidth && !($width = FacetWidth::tryFrom($width))) {
			throw new InvalidConfigException(sprintf(
				'Invalid width for facet `%s`: it can be %s.',
				$handle,
				implode(', ', array_map(fn(FacetWidth $case): string => "`$case->value`", FacetWidth::cases())),
			));
		}

		return new self(
			$handle,
			$type instanceof FacetType ? $type : FacetType::from($type),
			$config['value'] ?? $handle,
			$config['elementTypes'] ?? [],
			$config['label'] ?? null,
			$config['labels'] ?? null,
			$width,
		);
	}

	public function appliesTo(ElementInterface $element): bool
	{
		if (empty($this->elementTypes)) return true;

		foreach ($this->elementTypes as $elementType) {
			if ($element instanceof $elementType) return true;
		}

		return false;
	}

	/**
	 * @return array<int, string|float|bool> The page's distinct values
	 */
	public function getValues(ElementInterface $element): array
	{
		$values = FieldValueParser::getInstance()->getValues();
		$rawValues = is_string($this->value)
			? $values->resolvePath($element, $this->value)
			: $values->expand(($this->value)($element));

		$normalizedValues = [];
		foreach ($rawValues as $rawValue) {
			$value = $this->normalizeValue($rawValue);
			if ($value !== null && !in_array($value, $normalizedValues, true)) {
				$normalizedValues[] = $value;
			}
		}

		return $normalizedValues;
	}

	/**
	 * Turns a value into one of the facet's type: elements into their IDs, options into their values, dates into ISO 8601 strings in UTC.
	 *
	 * @return string|float|bool|null `null` for values the facet's type can't have
	 */
	public function normalizeValue(mixed $value): string|float|bool|null
	{
		$value = match (true) {
			$value instanceof ElementInterface => $value->id,
			$value instanceof OptionData => $value->value,
			$value instanceof BackedEnum => $value->value,
			default => $value,
		};

		return match ($this->type) {
			FacetType::KEYWORD => (is_scalar($value) || $value instanceof Stringable) && (string)$value !== '' ? (string)$value : null,
			FacetType::NUMBER => is_numeric($value) ? (float)$value : null,
			// Strings like `false`, `0`, `no` and `off` are false, as filters arrive from request params as strings
			FacetType::BOOLEAN => match (true) {
				is_string($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
				is_scalar($value) => (bool)$value,
				default => null,
			},
			FacetType::DATE => self::formatDate($value),
		};
	}

	/**
	 * Formats a date the way date facet values and document dates are stored: ISO 8601 in UTC, without fractions of seconds
	 */
	public static function formatDate(mixed $value): ?string
	{
		if ($value === null || $value === '') return null;

		// A copy, so the element's own date keeps its time zone
		$date = $value instanceof DateTimeInterface ? DateTime::createFromInterface($value) : DateTimeHelper::toDateTime($value);
		if (!$date) return null;

		return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}
}

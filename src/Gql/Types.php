<?php
declare(strict_types=1);

namespace ContentReactor\Search\Gql;

use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element as ElementInterface;
use GraphQL\Type\Definition\{
	InputObjectType,
	ObjectType,
	Type,
};

/**
 * The GraphQL types of the `crSearch` query
 */
final class Types
{
	public static function results(): ObjectType
	{
		return GqlEntityRegistry::getOrCreate('CrSearchResults', fn() => new ObjectType([
			'name' => 'CrSearchResults',
			'description' => 'A page of search results',
			'fields' => fn() => [
				'total' => [
					'type' => Type::nonNull(Type::int()),
					'description' => 'How many pages match, on all the pages of results',
				],
				'page' => Type::nonNull(Type::int()),
				'pageCount' => Type::nonNull(Type::int()),
				'elements' => [
					'type' => Type::nonNull(Type::listOf(Type::nonNull(ElementInterface::getType()))),
					'description' => 'The matching pages on this page of results, the most relevant first',
				],
				'pinnedIds' => [
					'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::int()))),
					'description' => 'The IDs of the pages shown first because they’re pinned for the term',
				],
				'facets' => [
					'type' => Type::nonNull(Type::listOf(Type::nonNull(self::facet()))),
					'description' => 'The values of the matching pages, per facet',
				],
			],
		]));
	}

	public static function facet(): ObjectType
	{
		return GqlEntityRegistry::getOrCreate('CrSearchFacet', fn() => new ObjectType([
			'name' => 'CrSearchFacet',
			'description' => 'A value of pages that search results can be filtered and counted by',
			'fields' => fn() => [
				'handle' => Type::nonNull(Type::string()),
				'label' => Type::nonNull(Type::string()),
				'type' => [
					'type' => Type::nonNull(Type::string()),
					'description' => '`keyword`, `number`, `boolean` or `date`. Numbers and dates can be filtered by ranges.',
				],
				'width' => [
					'type' => Type::nonNull(Type::string()),
					'description' => 'How much of a row the facet’s filter wants in a search form: `auto`, `quarter`, `third`, `half` or `full`',
				],
				'options' => Type::nonNull(Type::listOf(Type::nonNull(self::facetOption()))),
			],
		]));
	}

	public static function facetOption(): ObjectType
	{
		return GqlEntityRegistry::getOrCreate('CrSearchFacetOption', fn() => new ObjectType([
			'name' => 'CrSearchFacetOption',
			'fields' => fn() => [
				'value' => Type::nonNull(Type::string()),
				'label' => Type::nonNull(Type::string()),
				'count' => [
					'type' => Type::nonNull(Type::int()),
					'description' => 'How many matching pages have the value',
				],
			],
		]));
	}

	public static function filterInput(): InputObjectType
	{
		return GqlEntityRegistry::getOrCreate('CrSearchFilterInput', fn() => new InputObjectType([
			'name' => 'CrSearchFilterInput',
			'description' => 'Pages have to have one of the values, or a value in the range',
			'fields' => fn() => [
				'facet' => Type::nonNull(Type::string()),
				'values' => Type::listOf(Type::string()),
				'min' => Type::string(),
				'max' => Type::string(),
			],
		]));
	}
}

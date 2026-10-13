<?php
declare(strict_types=1);

namespace ContentReactor\Search\Gql;

use Craft;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;

/**
 * The `crSearch` query, for schemas with the Search component
 */
class SearchQueries extends Query
{
	/** The schema component that allows searching */
	public const COMPONENT = 'crSearch.all';

	public static function getQueries(bool $checkToken = true): array
	{
		if ($checkToken && !GqlHelper::canSchema(self::COMPONENT)) {
			return [];
		}

		return [
			'crSearch' => [
				'type' => Type::nonNull(Types::results()),
				'args' => [
					'q' => [
						'type' => Type::string(),
						'description' => 'Words the pages have to contain, all of them',
					],
					'site' => [
						'type' => Type::string(),
						'description' => 'The handle of the site to search in, the current one by default',
					],
					'filters' => [
						'type' => Type::listOf(Type::nonNull(Types::filterInput())),
						'description' => 'Values the pages have to have, per facet',
					],
					'facets' => [
						'type' => Type::listOf(Type::nonNull(Type::string())),
						'description' => 'Handles of the facets to count values of, all of them by default',
					],
					'page' => Type::int(),
					'limit' => [
						'type' => Type::int(),
						'description' => 'Results per page, the search settings’ by default',
					],
				],
				'resolve' => SearchResolver::resolve(...),
				'description' => Craft::t('cr-search', 'Searches the pages in the search index the schema can read.'),
				'complexity' => GqlHelper::relatedArgumentComplexity(),
			],
		];
	}
}

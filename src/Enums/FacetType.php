<?php
declare(strict_types=1);

namespace ContentReactor\Search\Enums;

/**
 * How facet values are stored, filtered by and counted
 */
enum FacetType: string
{
	/** Exact values, e.g. IDs, handles or option values */
	case KEYWORD = 'keyword';

	/** Numbers, which can be filtered by ranges */
	case NUMBER = 'number';

	case BOOLEAN = 'boolean';

	/** Dates, stored as ISO 8601 strings in UTC, which can be filtered by ranges */
	case DATE = 'date';
}

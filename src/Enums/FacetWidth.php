<?php
declare(strict_types=1);

namespace ContentReactor\Search\Enums;

/**
 * How much of a row a facet's filter wants in a search form. Templates are free to lay facets out their own way,
 * the baseline search page follows it.
 */
enum FacetWidth: string
{
	/** Whatever the template gives a facet by default */
	case AUTO = 'auto';

	case QUARTER = 'quarter';

	case THIRD = 'third';

	case HALF = 'half';

	case FULL = 'full';
}

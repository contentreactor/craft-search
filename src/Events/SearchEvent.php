<?php
declare(strict_types=1);

namespace ContentReactor\Search\Events;

use ContentReactor\Search\Models\{
	SearchQuery,
	SearchResults,
};
use yii\base\Event;

/**
 * Triggered around every search, including the ones counting facet values for search forms, which have a `limit` of 0.
 *
 * Before searching, the query can be changed: its filters are validated and normalized afterwards, so they can be added the way
 * requests send them. Setting `results` then skips the search index, e.g. for results from a cache.
 * After searching, `results` can be changed or replaced.
 */
class SearchEvent extends Event
{
	public SearchQuery $query;

	public ?SearchResults $results = null;
}

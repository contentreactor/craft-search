<?php
declare(strict_types=1);

namespace ContentReactor\Search\Events;

use craft\base\ElementInterface;
use yii\base\Event;

/**
 * Triggered when deciding whether a page belongs in the search index, once the search settings and its Hide from Search field
 * have decided. Pages that stop being indexable are removed from the index once they're reindexed.
 */
class DefineIndexableEvent extends Event
{
	public ElementInterface $page;

	public bool $isIndexable;
}

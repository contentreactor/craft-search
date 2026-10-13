<?php
declare(strict_types=1);

namespace ContentReactor\Search\Events;

use craft\base\ElementInterface;
use yii\base\Event;

/**
 * Triggered once an element is saved or deleted, with the pages whose searchable content includes the element's.
 * Search engines can reindex those pages, preferably from a queue job, as one save can trigger several of these events.
 */
class SearchableContentChangeEvent extends Event
{
	public ElementInterface $element;

	/** @var array<int, array<int, ElementInterface>> Pages indexed by site ID, then by element ID */
	public array $pages = [];

	public bool $deleted = false;
}

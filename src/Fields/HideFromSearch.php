<?php
declare(strict_types=1);

namespace ContentReactor\Search\Fields;

use Craft;
use craft\fields\Lightswitch;

/**
 * A switch editors turn on to keep a page out of search. The page is removed from the search index once it's saved.
 */
class HideFromSearch extends Lightswitch
{
	public static function displayName(): string
	{
		return Craft::t('cr-search', 'Hide from Search');
	}

	public static function icon(): string
	{
		return 'eye-slash';
	}
}

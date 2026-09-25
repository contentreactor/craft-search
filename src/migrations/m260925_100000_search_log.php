<?php
declare(strict_types=1);

namespace ContentReactor\Search\migrations;

use craft\db\Migration;

/**
 * Adds the search log, for search analytics
 */
class m260925_100000_search_log extends Migration
{
	public function safeUp(): bool
	{
		if (!$this->db->tableExists(Install::SEARCH_LOG)) {
			Install::createSearchLogTable($this);
		}

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(Install::SEARCH_LOG);

		return true;
	}
}

<?php
declare(strict_types=1);

namespace ContentReactor\Search\migrations;

use craft\db\Migration;

/**
 * Adds pinned results, pages shown first for search terms
 */
class m260925_300000_pins extends Migration
{
	public function safeUp(): bool
	{
		if (!$this->db->tableExists(Install::PINS)) {
			Install::createPinsTable($this);
		}

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(Install::PINS);

		return true;
	}
}

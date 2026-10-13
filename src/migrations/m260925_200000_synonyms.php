<?php
declare(strict_types=1);

namespace ContentReactor\Search\migrations;

use craft\db\Migration;

/**
 * Adds synonyms, groups of words that match each other in searches
 */
class m260925_200000_synonyms extends Migration
{
	public function safeUp(): bool
	{
		if (!$this->db->tableExists(Install::SYNONYMS)) {
			Install::createSynonymsTable($this);
		}

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(Install::SYNONYMS);

		return true;
	}
}

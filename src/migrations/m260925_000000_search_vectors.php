<?php
declare(strict_types=1);

namespace ContentReactor\Search\migrations;

use ContentReactor\Search\Indexes\DatabaseIndex;
use ContentReactor\Search\Plugin;
use craft\db\{
	Migration,
	Query,
};

/**
 * Stems PostgreSQL's full-text search in each site's language: documents get a search vector made with their site's
 * text search configuration, replacing the index over their keywords' `simple` vector
 */
class m260925_000000_search_vectors extends Migration
{
	public function safeUp(): bool
	{
		if (!$this->db->getIsPgsql() || $this->db->columnExists(Install::DOCUMENTS, 'searchVector')) {
			return true;
		}

		$indexNames = (new Query())
			->select(['indexname'])
			->from('pg_catalog.pg_indexes')
			->where(['tablename' => $this->db->getSchema()->getRawTableName(Install::DOCUMENTS)])
			->andWhere(['like', 'indexdef', 'to_tsvector'])
			->column($this->db);
		foreach ($indexNames as $indexName) {
			$this->dropIndex($indexName, Install::DOCUMENTS);
		}

		$this->addColumn(Install::DOCUMENTS, 'searchVector', 'tsvector');

		// The settings of the configured database index, for their text search configurations
		$index = Plugin::getInstance()?->getSearch()->getIndex();
		$index = $index instanceof DatabaseIndex ? $index : new DatabaseIndex();

		$siteIds = (new Query())->select(['siteId'])->distinct()->from(Install::DOCUMENTS)->column($this->db);
		foreach ($siteIds as $siteId) {
			$this->db->createCommand(sprintf(
				'UPDATE %s SET %s = %s WHERE %s = :siteId',
				$this->db->quoteTableName(Install::DOCUMENTS),
				$this->db->quoteColumnName('searchVector'),
				DatabaseIndex::getSearchVectorSql(),
				$this->db->quoteColumnName('siteId'),
			), [':config' => $index->getTextSearchConfig((int)$siteId), ':siteId' => $siteId])->execute();
		}

		Install::createSearchVectorIndex($this);

		return true;
	}

	public function safeDown(): bool
	{
		echo "m260925_000000_search_vectors cannot be reverted.\n";
		return false;
	}
}

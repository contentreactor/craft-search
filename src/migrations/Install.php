<?php
declare(strict_types=1);

namespace ContentReactor\Search\migrations;

use craft\db\{
	Migration,
	Table,
};

class Install extends Migration
{
	public const DOCUMENTS = '{{%contentreactor_search_documents}}';

	public const FACETS = '{{%contentreactor_search_facets}}';

	/** Searches logged for search analytics */
	public const SEARCH_LOG = '{{%contentreactor_search_log}}';

	/** Groups of words that match each other in searches */
	public const SYNONYMS = '{{%contentreactor_search_synonyms}}';

	/** Pages shown first for search terms */
	public const PINS = '{{%contentreactor_search_pins}}';

	/** How many characters of a document's keywords PostgreSQL indexes, as a text search vector can't hold more than 1 MB of words */
	public const TEXT_SEARCH_LENGTH = 400_000;

	public function safeUp(): bool
	{
		$this->createTables();
		$this->addIndexes();
		$this->addForeignKeys();
		self::createSearchLogTable($this);
		self::createSynonymsTable($this);
		self::createPinsTable($this);

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(self::PINS);
		$this->dropTableIfExists(self::SYNONYMS);
		$this->dropTableIfExists(self::SEARCH_LOG);
		$this->dropTableIfExists(self::FACETS);
		$this->dropTableIfExists(self::DOCUMENTS);

		return true;
	}

	private function createTables(): void
	{
		// Search documents of the database search index
		$this->createTable(self::DOCUMENTS, [
			'id' => $this->primaryKey(),
			'elementId' => $this->integer()->notNull(),
			'siteId' => $this->integer()->notNull(),
			'elementType' => $this->string()->notNull(),
			'keywords' => $this->longText()->notNull(),
			// Only live documents are searched
			'postDate' => $this->dateTime(),
			'expiryDate' => $this->dateTime(),
			// PostgreSQL's full-text search, stemmed in the site's language
			...($this->db->getIsPgsql() ? ['searchVector' => 'tsvector'] : []),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createTable(self::FACETS, [
			'id' => $this->primaryKey(),
			'documentId' => $this->integer()->notNull(),
			'facet' => $this->string()->notNull(),
			'value' => $this->string()->notNull(),
			// Numbers and dates as timestamps, for ranges
			'numericValue' => $this->decimal(24, 6),
		]);
	}

	/**
	 * Creates the search log: a row per logged search, without anything identifying who searched
	 */
	public static function createSearchLogTable(Migration $migration): void
	{
		$migration->createTable(self::SEARCH_LOG, [
			'id' => $migration->primaryKey(),
			'siteId' => $migration->integer()->notNull(),
			'term' => $migration->string()->notNull(),
			'resultCount' => $migration->integer()->notNull(),
			'dateCreated' => $migration->dateTime()->notNull(),
		]);
		$migration->createIndex(null, self::SEARCH_LOG, ['siteId', 'dateCreated']);
		$migration->createIndex(null, self::SEARCH_LOG, ['dateCreated']);
		$migration->addForeignKey(null, self::SEARCH_LOG, ['siteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
	}

	/**
	 * Creates the synonyms table: a row per group of words, for one site or all of them
	 */
	public static function createSynonymsTable(Migration $migration): void
	{
		$migration->createTable(self::SYNONYMS, [
			'id' => $migration->primaryKey(),
			// All sites when null
			'siteId' => $migration->integer(),
			// A JSON array of words
			'terms' => $migration->text()->notNull(),
			'sortOrder' => $migration->smallInteger()->unsigned()->notNull()->defaultValue(0),
			'dateCreated' => $migration->dateTime()->notNull(),
			'dateUpdated' => $migration->dateTime()->notNull(),
			'uid' => $migration->uid(),
		]);
		$migration->createIndex(null, self::SYNONYMS, ['siteId']);
		$migration->addForeignKey(null, self::SYNONYMS, ['siteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
	}

	/**
	 * Creates the pinned results table: a row per search term, for one site or all of them
	 */
	public static function createPinsTable(Migration $migration): void
	{
		$migration->createTable(self::PINS, [
			'id' => $migration->primaryKey(),
			// All sites when null
			'siteId' => $migration->integer(),
			// Normalized like logged search terms
			'term' => $migration->string()->notNull(),
			// A JSON array of element IDs, in the order they're shown
			'elementIds' => $migration->text()->notNull(),
			'dateCreated' => $migration->dateTime()->notNull(),
			'dateUpdated' => $migration->dateTime()->notNull(),
			'uid' => $migration->uid(),
		]);
		$migration->createIndex(null, self::PINS, ['term', 'siteId']);
		$migration->addForeignKey(null, self::PINS, ['siteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
	}

	private function addIndexes(): void
	{
		$this->createIndex(null, self::DOCUMENTS, ['elementId', 'siteId'], true);
		$this->createIndex(null, self::DOCUMENTS, ['siteId', 'postDate', 'expiryDate']);
		if ($this->db->getIsMysql()) {
			$this->db->createCommand(sprintf(
				'CREATE FULLTEXT INDEX %s ON %s (%s)',
				$this->db->quoteTableName($this->db->getIndexName()),
				$this->db->quoteTableName(self::DOCUMENTS),
				$this->db->quoteColumnName('keywords'),
			))->execute();
		} elseif ($this->db->getIsPgsql()) {
			self::createSearchVectorIndex($this);
		}

		$this->createIndex(null, self::FACETS, ['documentId']);
		$this->createIndex(null, self::FACETS, ['facet', 'value']);
		$this->createIndex(null, self::FACETS, ['facet', 'numericValue']);
	}

	/**
	 * Creates PostgreSQL's full-text index, on documents' search vectors
	 */
	public static function createSearchVectorIndex(Migration $migration): void
	{
		$migration->db->createCommand(sprintf(
			'CREATE INDEX %s ON %s USING GIN (%s)',
			$migration->db->quoteTableName($migration->db->getIndexName()),
			$migration->db->quoteTableName(self::DOCUMENTS),
			$migration->db->quoteColumnName('searchVector'),
		))->execute();
	}

	private function addForeignKeys(): void
	{
		$this->addForeignKey(null, self::DOCUMENTS, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, self::DOCUMENTS, ['siteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
		$this->addForeignKey(null, self::FACETS, ['documentId'], self::DOCUMENTS, ['id'], 'CASCADE');
	}
}

<?php
/**
 * @package     ${NAMESPACE}
 * @subpackage
 *
 * @copyright   A copyright
 * @license     A "Slug" license name e.g. GPL2
 */

/**
 * @package     Joomla.Platform
 * @subpackage  Database
 *
 * @copyright   Copyright (C) 2005 - 2020 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('JPATH_PLATFORM') or die;

/**
 * MySQLi database driver
 *
 * @link   https://mariadb.com/
 * @since  3.0.0
 */
class JDatabaseDriverMariaDB extends JDatabaseDriver
{
	/**
	 * The name of the database driver.
	 *
	 * @var    string
	 * @since  3.0.0
	 */
	public $name = 'mariadb';

	/**
	 * The type of the database server family supported by this driver.
	 *
	 * @var    string
	 * @since  CMS 3.5.0
	 */
	public $serverType = 'mariadb';

	/**
	 * @var    mariadb  The database connection resource.
	 * @since  1.7.0
	 */
	protected $connection;

	/**
	 * The character(s) used to quote SQL statement names such as table names or field names,
	 * etc. The child classes should define this as necessary.  If a single character string the
	 * same character is used for both sides of the quoted name, else the first character will be
	 * used for the opening quote and the second for the closing quote.
	 *
	 * @var    string
	 * @since  3.0.1
	 */
	protected $nameQuote = '`';

	/**
	 * The null or zero representation of a timestamp for the database driver.  This should be
	 * defined in child classes to hold the appropriate value for the engine.
	 *
	 * @var    string
	 * @since  3.0.1
	 */
	protected $nullDate = '1000-01-01 00:00:00';

	/**
	 * @var    string  The minimum supported database version.
	 * @since  3.0.1
	 */
	protected static $dbMinimum = '5.0.4';

	/**
	 * Constructor.
	 *
	 * @param array $options List of options used to configure the connection
	 *
	 * @since   3.0.0
	 */
	public function __construct($options)
	{
		// Get some basic values from the options.
		$options['host'] = (isset($options['host'])) ? $options['host'] : 'localhost';
		$options['user'] = (isset($options['user'])) ? $options['user'] : '';
		$options['password'] = (isset($options['password'])) ? $options['password'] : '';
		$options['database'] = (isset($options['database'])) ? $options['database'] : '';
		$options['select'] = (isset($options['select'])) ? (bool)$options['select'] : true;
		$options['port'] = (isset($options['port'])) ? (int)$options['port'] : null;
		$options['socket'] = (isset($options['socket'])) ? $options['socket'] : null;

		// Finalize initialisation.
		parent::__construct($options);
	}

	/**
	 * Connects to the database if needed.
	 *
	 * @return  void  Returns void if the database connected successfully.
	 *
	 * @throws  RuntimeException
	 * @since   3.0.0
	 */
	public function connect()
	{
		if ($this->connection) {
			return;
		}

		/*
		 * Unlike mariadb_connect() takes the port and socket as separate arguments. Therefore, we
		 * have to extract them from the host string.
		 * Port 3308 is defined on my system for MariaDB, you need to change it on your system or provide additional box for port
		 */
		$port = isset($this->options['port']) ? $this->options['port'] : 3308;
		$regex = '/^(?P<host>((25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?))(:(?P<port>.+))?$/';

		if (preg_match($regex, $this->options['host'], $matches)) {
			// It's an IPv4 address with or without port
			$this->options['host'] = $matches['host'];

			if (!empty($matches['port'])) {
				$port = $matches['port'];
			}
		} elseif (preg_match('/^(?P<host>\[.*\])(:(?P<port>.+))?$/', $this->options['host'], $matches)) {
			// We assume square-bracketed IPv6 address with or without port, e.g. [fe80:102::2%eth1]:3306
			$this->options['host'] = $matches['host'];

			if (!empty($matches['port'])) {
				$port = $matches['port'];
			}
		} elseif (preg_match('/^(?P<host>(\w+:\/{2,3})?[a-z0-9\.\-]+)(:(?P<port>[^:]+))?$/i', $this->options['host'], $matches)) {
			// Named host (e.g example.com or localhost) with or without port
			$this->options['host'] = $matches['host'];

			if (!empty($matches['port'])) {
				$port = $matches['port'];
			}
		} elseif (preg_match('/^:(?P<port>[^:]+)$/', $this->options['host'], $matches)) {
			// Empty host, just port, e.g. ':3308'
			$this->options['host'] = 'localhost';
			$port = $matches['port'];
		}
		// ... else we assume normal (naked) IPv6 address, so host and port stay as they are or default

		// Get the port number or socket name
		if (is_numeric($port)) {
			$this->options['port'] = (int)$port;
		} else {
			$this->options['socket'] = $port;
		}

		// Make sure the mariadb extension for PHP is installed and enabled.
		if (!self::isSupported()) {
			throw new JDatabaseExceptionUnsupported('The MySQLi extension for PHP is not installed or enabled.');
		}

		$this->connection = @mysqli_connect(
			$this->options['host'], $this->options['user'], $this->options['password'], null, $this->options['port'], $this->options['socket']
		);

		// Attempt to connect to the server.
		if (!$this->connection) {
			throw new JDatabaseExceptionConnecting('Could not connect to MySQL server.');
		}

		// Set sql_mode to non_strict mode
		mysqli_query($this->connection, "SET @@SESSION.sql_mode = '';");

		// If auto-select is enabled select the given database.
		if ($this->options['select'] && !empty($this->options['database'])) {
			$this->select($this->options['database']);
		}

		// Pre-populate the UTF-8 Multibyte compatibility flag based on server version
		$this->utf8mb4 = $this->serverClaimsUtf8mb4Support();

		// Set the character set (needed for mariaDB 4.1.2+).
		$this->utf = $this->setUtf();

		// Disable query cache and turn profiling ON in debug mode.
		if ($this->debug) {
			if ($this->hasQueryCacheEnabled()) {
				mysqli_query($this->connection, 'SET query_cache_type = 0;');
			}

			if ($this->hasProfiling()) {
				mysqli_query($this->connection, 'SET profiling_history_size = 100, profiling = 1;');
			}
		}
	}

	/**
	 * Disconnects the database.
	 *
	 * @return  void
	 *
	 * @since   3.0.0
	 */
	public function disconnect()
	{
		// Close the connection.
		if ($this->connection instanceof mysqli && $this->connection->stat() !== false) {
			foreach ($this->disconnectHandlers as $h) {
				call_user_func_array($h, array(&$this));
			}

			mysqli_close($this->connection);
		}

		$this->connection = null;
	}

	/**
	 * Method to escape a string for usage in an SQL statement.
	 *
	 * @param string $text The string to be escaped.
	 * @param boolean $extra Optional parameter to provide extra escaping.
	 *
	 * @return  string  The escaped string.
	 *
	 * @since   3.0.0
	 */
	public function escape($text, $extra = false)
	{
		if (is_int($text)) {
			return $text;
		}

		if (is_float($text)) {
			// Force the dot as a decimal point.
			return str_replace(',', '.', $text);
		}

		$this->connect();

		$result = mysqli_real_escape_string($this->getConnection(), $text);

		if ($extra) {
			$result = addcslashes($result, '%_');
		}

		return $result;
	}

	/**
	 * Test to see if the MySQL connector is available.
	 *
	 * @return  boolean  True on success, false otherwise.
	 *
	 * @since   3.0.0
	 */
	public static function isSupported()
	{
		return function_exists('mysqli_connect');
	}

	/**
	 * Determines if the connection to the server is active.
	 *
	 * @return  boolean  True if connected to the database engine.
	 *
	 * @since   3.0.0
	 */
	public function connected()
	{
		if (is_object($this->connection)) {
			return mysqli_ping($this->connection);
		}

		return false;
	}

	/**
	 * Drops a table from the database.
	 *
	 * @param string $tableName The name of the database table to drop.
	 * @param boolean $ifExists Optionally specify that the table must exist before it is dropped.
	 *
	 * @return  JDatabaseDriverMysqli  Returns this object to support chaining.
	 *
	 * @throws  RuntimeException
	 * @since   3.0.1
	 */
	public function dropTable($tableName, $ifExists = true)
	{
		$this->connect();

		$query = $this->getQuery(true);

		$this->setQuery('DROP TABLE ' . ($ifExists ? 'IF EXISTS ' : '') . $query->quoteName($tableName));

		$this->execute();

		return $this;
	}

	/**
	 * Get the number of affected rows by the last INSERT, UPDATE, REPLACE or DELETE for the previous executed SQL statement.
	 *
	 * @return  integer  The number of affected rows.
	 *
	 * @since   3.0.0
	 */
	public function getAffectedRows()
	{
		$this->connect();

		return mysqli_affected_rows($this->connection);
	}

	/**
	 * Method to get the database collation.
	 *
	 * @return  mixed  The collation in use by the database (string) or boolean false if not supported.
	 *
	 * @throws  RuntimeException
	 * @since   3.0.1
	 */
	public function getCollation()
	{
		$this->connect();

		// Attempt to get the database collation by accessing the server system variable.
		$this->setQuery('SHOW VARIABLES LIKE "collation_database"');
		$result = $this->loadObject();

		if (property_exists($result, 'Value')) {
			return $result->Value;
		} else {
			return false;
		}
	}

	/**
	 * Method to get the database connection collation, as reported by the driver. If the connector doesn't support
	 * reporting this value please return an empty string.
	 *
	 * @return  string
	 */
	public function getConnectionCollation()
	{
		$this->connect();

		// Attempt to get the database collation by accessing the server system variable.
		$this->setQuery('SHOW VARIABLES LIKE "collation_connection"');
		$result = $this->loadObject();

		if (property_exists($result, 'Value')) {
			return $result->Value;
		} else {
			return false;
		}
	}

	/**
	 * Get the number of returned rows for the previous executed SQL statement.
	 * This command is only valid for statements like SELECT or SHOW that return an actual result set.
	 * To retrieve the number of rows affected by an INSERT, UPDATE, REPLACE or DELETE query, use getAffectedRows().
	 *
	 * @param resource $cursor An optional database cursor resource to extract the row count from.
	 *
	 * @return  integer   The number of returned rows.
	 *
	 * @since   3.0.0
	 */
	public function getNumRows($cursor = null)
	{
		return mysqli_num_rows($cursor ? $cursor : $this->cursor);
	}

	/**
	 * Shows the table CREATE statement that creates the given tables.
	 *
	 * @param mixed $tables A table name or a list of table names.
	 *
	 * @return  array  A list of the create SQL for the tables.
	 *
	 * @throws  RuntimeException
	 * @since   3.0.0
	 */
	public function getTableCreate($tables)
	{
		$this->connect();

		$result = array();

		// Sanitize input to an array and iterate over the list.
		settype($tables, 'array');

		foreach ($tables as $table) {
			// Set the query to get the table CREATE statement.
			$this->setQuery('SHOW CREATE table ' . $this->quoteName($this->escape($table)));
			$row = $this->loadRow();

			// Populate the result array based on the create statements.
			$result[$table] = $row[1];
		}

		return $result;
	}

	/**
	 * Retrieves field information about a given table.
	 *
	 * @param string $table The name of the database table.
	 * @param boolean $typeOnly True to only return field types.
	 *
	 * @return  array  An array of fields for the database table.
	 *
	 * @throws  RuntimeException
	 * @since   3.0.1
	 */
	public function getTableColumns($table, $typeOnly = true)
	{
		$this->connect();

		$result = array();

		// Set the query to get the table fields statement.
		$this->setQuery('SHOW FULL COLUMNS FROM ' . $this->quoteName($this->escape($table)));
		$fields = $this->loadObjectList();

		// If we only want the type as the value add just that to the list.
		if ($typeOnly) {
			foreach ($fields as $field) {
				$result[$field->Field] = preg_replace('/[(0-9)]/', '', $field->Type);
			}
		} // If we want the whole field data object add that to the list.
		else {
			foreach ($fields as $field) {
				$result[$field->Field] = $field;
			}
		}

		return $result;
	}

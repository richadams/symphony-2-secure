<?php
// A custom class to use PDO instead of mysql_*.

// For adding extra information when a DB query goes wrong.
class DatabaseException extends Exception
{
    // Members
    private $_error = array();

    // Ctor
    public function __construct($message, array $error = null)
    {
        parent::__construct($message);
        $this->_error = $error;
    }

    // Getters
    public function getQuery()                { return $this->_error["query"]; }
    public function getDatabaseErrorCode()    { return $this->_error["num"]; }
    public function getDatabaseErrorMessage() { return $this->_error["msg"]; }
}

// The main database class.
class MySQL
{
    // Constants
    const __WRITE_OPERATION__ = 0;
    const __READ_OPERATION__  = 1;

    // Static Members
    private static $_instance     = null;    // Don't like this, but for backwards compat I need to be able to access some members staticly.
    private static $_log          = array(); // A log of all queries executed.
    private static $_queryCount   = 0;       // Keep track of the number of queries executed.
    private static $_cacheEnabled = true;    // Is query caching enabled or not.

    // Members
    private $_connProps           = array(); // An array of connection properties.
    private $_handle              = null;    // The database resource handle.
    private $_connected           = false;   // Are we connected or not.
    private $_lastQuery           = null;    // The last query that was executed by the class
    private $_lastQueryHash       = null;    //  The hash value of the last query that was executed
    private $_lastInsertID        = null;    // The autoinc value returned by the last query.
    private $_lastResult          = array(); // An array representing the last result set.

    // Singleton
    public static function instance()
    {
        if (self::$_instance == null) { self::$_instance = new MySQL(); }
        return self::$_instance;
    }

    // Ctor
    public function __construct()
    {
        self::$_instance = $this;
    }

    // Dtor
    public function __destruct()
    {
        $this->flush();
        $this->close();
    }

    // Member Related Functions
    ////////////////////////////////////////////////////////////////////////////////////////////////

    // Resets the result members
    public function flush()
    {
        $this->_result        = null;
        $this->_lastResult    = array();
        $this->_lastQuery     = null;
        $this->_lastQueryHash = null;
    }

    // Clears the log
    public function flushLog()                     { self::$_log = array(); }

    // Returns the number of queries that have been executed
    public static function queryCount()            { return self::$_queryCount; }

    // Gets the current handle
    public function getHandle()                    { return $this->_handle; }
    public static function getConnectionResource() { return self::instance()->getHandle(); }

    // Set the caching flag. Appends all READ qeruesi with SQL_CACHE.
    public static function enableCaching()         { self::$_cacheEnabled = true; }
    public static function disableCaching()        { self::$_cacheEnabled = false; }
    public static function isCachingEnabled()      { return self::$_cacheEnabled; }

    // A prefix is used for all db tables. Here's where that info is set.
    public function setPrefix($prefix)             { $this->_connProps["prefix"] = $prefix; }

    // Determine if a connection has been made or not
    public function isConnected()                  { return $this->_connected; }

    // Close the MySQL connection
    public function close()                        { $this->_handle = null; }

    // Gets the last inserted ID from the previous query.
    public function getInsertID()                  { return $this->_lastInsertID; }

    // Connect to the database.
    public function connect($host = null, $user = null, $pass = null, $port = "3306", $db = null)
    {
        // Load properties from configuration if they're null.
        if ($host == null || $user == null)
        {
            $host = Symphony::Configuration()->get("host",     "database");
            $user = Symphony::Configuration()->get("user",     "database");
            $pass = Symphony::Configuration()->get("password", "database");
            $port = Symphony::Configuration()->get("port",     "database");
            $db   = Symphony::Configuration()->get("db",       "database");
        }
        $pre = Symphony::Configuration()->get("tbl_prefix", "database");

        // Set the properties
        $this->_connProps = array("host"   => $host,
                                  "user"   => $user,
                                  "pass"   => $pass,
                                  "port"   => $port,
                                  "db"     => $db,
                                  "prefix" => $pre);

        if ($this->isConnected()) { return; } // Already connected

        // Construct the PDO connection string.
        $pdo_string = "mysql:dbname=" . $db . ";host=" . $host;

        try
        {
            $this->_handle = new PDO($pdo_string,
                                     $user,
                                     $pass,
                                     array(PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
                                           PDO::ATTR_PERSISTENT               => true,
                                           PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                                           PDO::MYSQL_ATTR_INIT_COMMAND       => "SET NAMES utf8"
                                     ));
            $this->_connected = true;
        }
        catch (PDOException $e)
        {
            $this->__error($e->getCode(), $e->getMessage());
            return false;
        }
        return true;
    }

    // Select a MySQL database. Doesn't perform a SELECT query, despite the name.
    public function select($db = null)
    {
        if ($db == null) return;
        $this->query("USE :db", "",
                     array("db" => $db));
    }

    // Set the char encoding for sending/receiving data. UTF-8 assumed.
    // No needed with PDO, this is set at connection time. Providing code anyway.
    public function setCharacterEncoding($set = "utf8")
    {
        $this->query("SET NAMES :name", "",
                     array("name" => $set));
    }

    //  Set the character encoding of the database for new tables. Again, UTF-8 is assumed.
    public function setCharacterSet($set = "utf8")
    {
        $this->query("SET character_set_connection = :name, character_set_database = :name, character_set_server = :name", "",
                     array("name" => $set));
        $this->query("SET CHARACTER SET :name", "",
                     array("name" => $set));
    }

    // Determine whether it's a write or read operation. Read operations can have caching, writes
    // obviously can't.
    public function determineQueryType($query)
    {
        return (preg_match("/^(create|insert|replace|alter|delete|update|optimize|truncate|set)/i", $query) ? self::__WRITE_OPERATION__ : self::__READ_OPERATION__);
    }

    // Sanitization
    ////////////////////////////////////////////////////////////////////////////////////////////////

    // Clean one value
    public static function cleanValue($value)
    {
        // Prepared statements should *really* be used instead. But for backwards compatibility
        // we need a way to clean input. Remove the first/last chars so that only internal quotes
        // are added.
        return substr(self::instance()->getHandle()->quote($value), 1, -1);
    }

    // Clean an n-dimension array, only values are cleaned, not keys.
    public static function cleanFields(array &$fields)
    {
        foreach ($fields as $key => &$value)
        {
            if (is_array($value)) { self::cleanFields($value); continue; }
            $value = (strlen($value) == 0) ? "NULL" : self::cleanValue($value);
        }
    }

    // PDO Specific Functions
    ////////////////////////////////////////////////////////////////////////////////////////////////

    // Prepares the statement with PDO.
    private function __prepareStatement($query)
    {
        $this->prepareQuery($query);
        return $this->getHandle()->prepare($query);
    }

    // Binds the passed in parameters to the query.
    private function __bindParameters(&$stmt, $params)
    {
        foreach ($params as $key => $value)
        {
            $stmt->bindValue(":" . $key, $value);
        }
    }

    // Executes the statement, calling __error() if something goes wrong.
    private function __executeStatement($stmt)
    {
        // Defensive
        if (!is_object($stmt)) $this->__error("-1", "Internal Error");

        try
        {
            $stmt->execute();
        }
        catch (PDOException $e)
        {
            $this->__error($e->getCode(), $e->getMessage());
        }
    }

    // Executes the statement and retrieves the result set.
    private function __getResults($stmt, $type = "OBJECT")
    {
        // Defensive
        if (!is_object($stmt)) $this->__error("-1", "Internal Error");

        $this->__executeStatement($stmt);
        $result = null;
        switch ($type)
        {
            case "OBJECT": $result = $stmt->fetchAll(PDO::FETCH_OBJ);   break;
            case "ASSOC":  $result = $stmt->fetchAll(PDO::FETCH_ASSOC); break;
            default:       $result = $stmt->fetchAll(PDO::FETCH_BOTH);  break;
        }
        $stmt->closeCursor();

        return $result;
    }

    // Symphony Modification Functions
    ////////////////////////////////////////////////////////////////////////////////////////////////

    // Prepares a query by doing some replacements
    public function prepareQuery(&$query)
    {
        // Replace the prefix with the correct one.
        if ($this->_connProps["prefix"] != "tbl_")
        {
            $query = preg_replace("/tbl_(\S+?)([\s\.,]|$)/", $this->_connProps["prefix"] . "\\1\\2", $query);
        }

        // Work out the caching stuff, TYPE is deprecated since MySQL 4.0.18, ENGINE is preferred
        if ($queryType == self::__WRITE_OPERATION__)
        {
            $query = preg_replace("/TYPE=(MyISAM|InnoDB)/i", "ENGINE=$1", $query);
        }
        else if ($queryType == self::__READ_OPERATION__
                 && !preg_match("/^SELECT\s+SQL(_NO)?_CACHE/i", $query))
        {
            $query = ($this->isCachingEnabled()) ? preg_replace("/^SELECT\s+/i", "SELECT SQL_CACHE ", $query)
                                                 : preg_replace("/^SELECT\s+/i", "SELECT SQL_NO_CACHE ", $query);
        }
    }

    // This updates the statistics after a query has executed, and calls the extension triggers.
    public function postQueryExecute($query = "", $startTime = "")
    {
        // Update the last insert ID
        $this->_lastInsertID = $this->getHandle()->lastInsertId();

        // Update statistics and internal members.
		self::$_queryCount++;
        $this->_lastQuery     = $query;
        $this->_lastQueryHash = md5($query . $startTime);
        $executionTime        = precision_timer("stop", $startTime);

        if (Symphony::ExtensionManager() instanceof ExtensionManager)
        {
	        Symphony::ExtensionManager()->notifyMembers("PostQueryExecution", class_exists('Administration') ? "/backend/" : "/frontend/", array(
		        "query"          => $query,
		        "query_hash"     => $this->_lastQueryHash,
		        "execution_time" => $executionTime
	        ));

	        // Log it
	        if(GenericExceptionHandler::$enabled)
	        {
		        self::$_log[$queryHash] = array(
			        "query"          => $query,
			        "query_hash"     => $this->_lastQueryHash,
			        "execution_time" => $executionTime
		        );
	        }
        }
        // Symphony isn't ready yet. Log internally
        else
        {
	        self::$_log[$queryHash] = array(
		        "query"          => $query,
		        "query_hash"     => $this->_lastQueryHash,
		        "execution_time" => $executionTime
	        );
        }
    }

    // Symphony Interface Functions
    ////////////////////////////////////////////////////////////////////////////////////////////////

    // Performs an SQL query. Pass in the fields as associative array in order to use a prepared
    // statement. Use insert() or update() for INSERT/UPDATE if you can, since they use prepared 
    // statements.
    public function query($query, $type = "OBJECT", array $fields = array())
    {
        if (!$this->isConnected()) { $this->connect(); }

        // Metadata
        $startTime = precision_timer();
        $query     = trim($query);
        $queryType = $this->determineQueryType($query);

        // Reset members
        $this->flush();

        // Now perform the query, and get the results
        $stmt = $this->__prepareStatement($query);
        $this->__bindParameters($stmt, $fields);
        switch($queryType)
        {
            case self::__WRITE_OPERATION__:
                $this->__executeStatement($stmt);
                break;
            case self::__READ_OPERATION__:
            default:
                $this->_lastResult = $this->__getResults($stmt, $type);
                break;
        }
        $stmt->closeCursor();

        // Finally, do the post processing
        $this->postQueryExecute($query, $startTime);

        return true;
    }

    // Performs an INSERT operation on the database. Uses PDO prepared statements.
    public function insert(array $fields, $table, $updateOnDuplicate = false)
    {
        if (!$this->isConnected()) { $this->connect(); }

        // If this has multiple inserts, needs to be handled differently
        if (is_array(current($fields))) { return $this->insertMultiple($fields, $table); }

        // Metadata
        $startTime = precision_timer();

        // Used to store our bind parameters. Would just use $fields, but sometimes we need more
        // due to the ON DUPLICATE stuff.
        $bindParams = array();

        // Construct the query
        $query = "INSERT INTO `". $table . "` (";

        // Columns
        foreach ($fields as $key => $value)
        {
            $query .= " `" . $key . "`,";
        }
        $query = substr($query, 0, -1); // Remove extra , at end.

        $query .= ") VALUES (";

        // Params
        foreach ($fields as $key => $value)
        {
            $query .= " :" . $key . ",";
            $bindParams[$key] = $value;
        }
        $query = substr($query, 0, -1); // Remove extra , at end.

        $query .= ")";

        // ON DUPLICATE KEY UPDATE handling
        if ($updateOnDuplicate)
        {
            $query .= " ON DUPLICATE KEY UPDATE ";
            foreach ($fields as $key => $value)
            {
                $query .= " `" . $key . "` = :u_" . $key . ",";
                $bindParams["u_".$key] = $value;
            }
            $query = substr($query, 0, -1); // Remove extra , at end.
        }

        // Now prepare the statement, bind the parameters and execute it.
        $stmt = $this->__prepareStatement($query);
        $this->__bindParameters($stmt, $bindParams);
        $this->__executeStatement($stmt);

        // Finally, do the post processing
        $this->postQueryExecute($query, $startTime);

        return true;
    }

    // Performs multiple INSERT operations in the same query. Uses PDO prepared statements.
    public function insertMultiple(array $fields, $table)
    {
        // Metadata
        $startTime = precision_timer();

        // Used to store our bind parameters. Would just use $fields, but there would be dupes.
        $bindParams = array();

        // Construct the query
        $query = "INSERT INTO `". $table . "` (";

        // Columns
        foreach (current($fields) as $key => $value)
        {
            $query .= " `" . $key . "`,";
        }
        $query = substr($query, 0, -1); // Remove extra , at end.

        $query .= ") VALUES ";

        // Multiple fields
        foreach ($fields as $key => $array)
        {
            if (!is_array($array)) continue; // Prevent ",()" in the SQL

            $row = "(";
            foreach ($array as $value)
            {
                // Pick a unique bind value
                $bind = substr(md5($value . microtime()), 0, 10);
                $row .= " :" . $bind . ",";
                $bindParams[$bind] = $value;
            }
            $row = substr($row, 0, -1); // Remove extra , at end.
            $row .= ")";
            $rows[] = $row;
        }
        $query .= implode(", ", $rows);

        // Now prepare the statement, bind the parameters and execute it.
        $stmt = $this->__prepareStatement($query);
        $this->__bindParameters($stmt, $bindParams);
        $this->__executeStatement($stmt);

        // Finally, do the post processing
        $this->postQueryExecute($query, $startTime);

        return true;
    }

    // Performs an UPDATE operation on the database. Again, uses PDO prepared statements.
    public function update(array $fields, $table, $where = null)
    {
        if (!$this->isConnected()) { $this->connect(); }

        // Metadata
        $startTime = precision_timer();

        // First things first, construct the query
        $query = "UPDATE `". $table . "` SET ";

        // Setup parameters
        foreach ($fields as $key => $value)
        {
            $query .= " `" . $key . "` = :" . $key . ",";
        }
        $query = substr($query, 0, -1); // Remove extra , at end.

        // WHERE clause
        if ($where != null) { $query .= " WHERE " . $where; }

        // Now prepare the statement, bind the parameters and execute it.
        $stmt = $this->__prepareStatement($query);
        $this->__bindParameters($stmt, $fields);
        $this->__executeStatement($stmt);

        // Finally, do the post processing
        $this->postQueryExecute($query, $startTime);

        return true;
    }

    // Performs a DELETE operation.
    // NOTE: Not parameterised!!
    public function delete($table, $where = null)
    {
        $table = str_replace("`", "", $table);
        return $this->query("DELETE FROM `".$table."` WHERE " . $where);
    }

    // Returns an associative array that contains the result of a given query. Can optionally be
    // indexed by a specific column.
    public function fetch($query = null, $indexColumn = null)
    {
        if ($query != null)
        {
            $this->query($query, "ASSOC");
        }
        else if ($this->_lastResult == null)
        {
            return array();
        }

        $result = $this->_lastResult;

        // Index by a specific column.
        if ($indexColumn != null
            && isset($result[0][$indexColumn]))
        {
            $tmp = array();
            foreach ($result as $row) { $tmp[$row[$indexColumn]] = $row; }
            $result = $tmp;
        }

        return $result;
    }

    // Returns the row at a specified index from the given query. No query means it will use
    // the last result. No offset means it will return the first row. No LIMIT is implied.
    public function fetchRow($offset = 0, $query = null)
    {
        $result = $this->fetch($query);
        return (empty($result) ? array() : $result[$offset]);
    }

    // Returns an array of values for a specified column in the given query. Uses last result if
    // no query provided
    public function fetchCol($column, $query = null)
    {
        $result = $this->fetch($query);
        if(empty($result)) return array();
        foreach ($result as $row) { $return[] = $row[$column]; }
        return $return;
    }

    // Returns the value for a specified column at a specified offset. First row and last result
    // used if input not provided.
    public function fetchVar($column, $offset = 0, $query = null)
    {
        $result = $this->fetch($query);
        return (empty($result) ? null : $result[$offset][$column]);
    }

    // Boolean, tells if table contains a field or not.
    public function tableContainsField($table, $field)
    {
        $params = array("table" => $table,
                        "field" => $field);

        // Metadata
        $startTime = precision_timer();

        // Run the query
        $stmt = $this->__prepareStatement("DESC :table :field");
        $this->__bindParameters($stmt, $params);
        $results = $this->__getResults($stmt, "ASSOC");

        // Finally, do the post processing
        $this->postQueryExecute("DESC :table :field", $startTime);

        return (is_array($results) && !empty($results));
    }

    // Error & Debugging Functions
    ////////////////////////////////////////////////////////////////////////////////////////////////

    // If an error occurs, this function is called which logs the last query and error message from
    // MySQL before throwing a DatabaseException.
    private function __error($code, $message)
    {
        if(Symphony::ExtensionManager() instanceof ExtensionManager) {
            Symphony::ExtensionManager()->notifyMembers("QueryExecutionError", class_exists("Administration") ? "/backend/" : "/frontend/", array(
                "query"      => $this->_lastQuery,
                "query_hash" => $this->_lastQueryHash,
                "msg"        => $message,
                "num"        => $code
            ));
        }

        throw new DatabaseException(__("MySQL Error (%1$s): %2$s in query: %3$s", array($code, $message, $this->_lastQuery)), array(
            "msg"   => $message,
            "num"   => $code,
            "query" => $this->_lastQuery
        ));
    }

    // Returns all the log entries.
    public function debug($type = null)
    {
        if (!$type) return self::$_log;
        return ($type == "error" ? self::$_log["error"] : self::$_log["query"]);
    }

    // Returns some statistics for the profile devkit.
    public function getStatistics()
    {
        $stats        = array();
        $query_timer  = 0.0;
        $slow_queries = array();

        foreach(self::$_log as $key => $val)
        {
            $query_timer += $val["execution_time"];
            if($val["execution_time"] > 0.0999) $slow_queries[] = $val;
        }

        return array(
            "queries"          => self::queryCount(),
            "slow-queries"     => $slow_queries,
            "total-query-time" => number_format($query_timer, 4, ".", "")
        );
    }

    // Convenience function to allow you to execute multiple SQL queries at once.
    public function import($sql, $forceEngine = false)
    {
        // Silently attempt to change the storage engine. This prevents INNOdb errors.
        if ($forceEngine) { $this->query("SET storage_engine=MYISAM"); }

        $queries = preg_split("/;[\\r\\n]+/", $sql, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($queries) || empty($queries) || count($queries) <= 0)
        {
	        throw new Exception("The SQL string contains no queries.");
        }

        foreach ($queries as $sql)
        {
	        if (trim($sql) != "") $result = $this->query($sql);
	        if (!$result) return false;
        }

        return true;
    }
}

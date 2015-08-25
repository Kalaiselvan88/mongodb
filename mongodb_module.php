<?php

/**
 * @file
 * Contains the main module connecting Drupal to MongoDB.
 */

/**
 * Implements hook_help().
 */
function mongodb_help($path) {
  switch ($path) {
    case 'admin/help#mongodb':
      return '<p>' . t('<a href="!project">MongoDB</a> implements a generic <a href="!mongo">MongoDB</a> interface.', array(
        '!project' => 'http://drupal.org/project/mongodb',
        '!mongo' => 'http://www.mongodb.org/',
      ));
  }
}

/**
 * Returns an MongoDB object.
 *
 * @param string $alias
 *   The name of a MongoDB connection alias. If it is not passed, or if the
 *   alias does not match a connection definition, the function will fall back
 *   to the 'default' alias.
 * @param int $retry
 *   The number of retry attemps when a connection fails.
 *
 * @return \MongoDB|\MongodbDummy
 *   A MongodbDummy is returned in case a MongoConnectionException is thrown.
 *
 * @throws \MongoConnectionException
 *   If the connection cannot be estaslished even after retries.
 * @throws \InvalidArgumentException
 *   If the database cannot be selected.
 * @throws \MongoConnectionException
 *   If the connection cannot be established.
 *
 * @see MongodbDummy
 */
function mongodb($alias = 'default', $retry = 3) {
  static $mongo_objects;
  $connections = variable_get('mongodb_connections', array());
  if (!isset($connections[$alias])) {
    $alias = 'default';
  }
  $connection = isset($connections[$alias]) ? $connections[$alias] : array();
  $connection += array(
    'host' => 'localhost',
    'db' => 'drupal',
    'connection_options' => array(),
  );
  $host = $connection['host'];
  $database = $connection['db'];
  $options = $connection['connection_options'] + array(
    'connect' => TRUE,
    'db' => $database,
  );
  if (!isset($mongo_objects[$host][$database])) {
    try {
      // Use the 1.3 client if available.
      if (class_exists('MongoClient')) {
        $mongo = new MongoClient($host, $options);
        // Enable read preference and tags if provided. This can also be
        // controlled on a per query basis at the cursor level if more control
        // is required.
        if (!empty($connection['read_preference'])) {
          $tags = !empty($connection['read_preference']['tags']) ? $connection['read_preference']['tags'] : array();
          $mongo->setReadPreference($connection['read_preference']['preference'], $tags);
        }
      }
      else {
        $mongo = new Mongo($host, $options);
        if (!empty($connection['slave_ok'])) {
          $mongo->setSlaveOkay(TRUE);
        }
      }
      $mongo_objects[$host][$database] = $mongo->selectDB($database);
      $mongo_objects[$host][$database]->connection = $mongo;
    }
    catch (MongoConnectionException $e) {
      if ($retry > 0) {
        return mongodb($alias, --$retry);
      }
      $mongo_objects[$host][$database] = new MongodbDummy();
      throw $e;
    }
  }
  return $mongo_objects[$host][$database];
}

/**
 * Returns a MongoCollection object.
 *
 * @param mixed $collection_name
 *   Can be either a plain collection name or 0-based array containing a
 *   collection name and a prefix.
 *
 * @return \MongoCollection|\MongoDebugCollection|\MongodbDummy
 *   Return a MongoCollection in normal situations, a MongoDebugCollection if
 *   mongodb_debug is enabled, or a MongodbDummy if the MongoDB connection could
 *   not be established.
 *
 * @throws \InvalidArgumentException
 *   If the database cannot be selected.
 * @throws \MongoConnectionException
 *   If the connection cannot be established.
 */
function mongodb_collection() {
  $args = array_filter(func_get_args());
  if (is_array($args[0])) {
    list($collection_name, $prefixed) = $args[0];
    $prefixed .= $collection_name;
  }
  else {
    // Avoid something. collection names if NULLs are passed in.
    $collection_name = implode('.', array_filter($args));
    $prefixed = mongodb_collection_name($collection_name);
  }
  $collections = variable_get('mongodb_collections', array());
  if (isset($collections[$collection_name])) {
    // We might be dealing with an array or string because of need to preserve
    // backwards compatibility.
    $alias = is_array($collections[$collection_name]) && !empty($collections[$collection_name]['db_connection'])
      ? $collections[$collection_name]['db_connection']
      : $collections[$collection_name];
  }
  else {
    $alias = 'default';
  }
  // Prefix the collection name for simpletest. It will be in the same DB as the
  // non-prefixed version so it's enough to prefix after choosing the mongodb
  // object.
  $mongodb_object = mongodb($alias);
  $collection = $mongodb_object->selectCollection(mongodb_collection_name($collection_name));

  // Enable read preference and tags at a collection level if we have 1.3
  // client.
  if (!empty($collections[$alias]['read_preference']) && get_class($mongodb_object->connection) == 'MongoClient') {
    $tags = !empty($collections[$alias]['read_preference']['tags']) ? $collections[$alias]['read_preference']['tags'] : array();
    $collection->setReadPreference($collections[$alias]['read_preference']['preference'], $tags);
  }

  $collection->connection = $mongodb_object->connection;
  return variable_get('mongodb_debug', FALSE) ? new MongoDebugCollection($collection) : $collection;
}

/**
 * Class MongoDebugCollection is a debug decorator for MongoCollection.
 */
class MongoDebugCollection {

  /**
   * The decorated collection.
   *
   * @var MongoCollection
   */
  protected $collection;

  /**
   * Constructor.
   *
   * @param \MongoCollection $collection
   *   The collection to decorate.
   */
  public function __construct(\MongoCollection $collection) {
    $this->collection = $collection;
  }

  /**
   * A decorator for the MongoCollection::find() method adding debug info.
   *
   * @param array|null $query
   *   A MongoCollection;:find()-compatible query array.
   * @param array|null $fields
   *   A MongoCollection;:find()-compatible fields array.
   *
   * @return \MongoDebugCursor
   *   A debug cursor wrapping the decorated find() results.
   */
  public function find($query = array(), $fields = array()) {
    debug('find');
    debug($query);
    debug($fields);
    return new MongoDebugCursor($this->collection->find($query, $fields));
  }

  /**
   * Decorates the standard __call() by debug()-ing its arguments.
   *
   * @param string $name
   *   The name of the called method.
   * @param array $arguments
   *   The arguments for the decorated __call().
   *
   * @return mixed
   *   The result of the decorated __call().
   */
  public function __call($name, array $arguments) {
    debug($name);
    debug($arguments);
    return call_user_func_array(array($this->collection, $name), $arguments);
  }

}

/**
 * Class MongoDebugCursor is a debug decorator for MongoCursor.
 *
 * @see mongoDebugCollection::find()
 */
class MongoDebugCursor {

  /**
   * The decorated cursor.
   *
   * @var \MongoCursor
   */
  protected $cursor;

  /**
   * Constructor.
   *
   * @param \MongoCursor $cursor
   *   The cursor to decorate.
   */
  public function __construct(\MongoCursor $cursor) {
    $this->cursor = $cursor;
  }

  /**
   * Decorates the standard __call() by debug()-ing its arguments.
   *
   * @param string $name
   *   The name of the called method.
   * @param array $arguments
   *   The arguments for the decorated __call().
   *
   * @return mixed
   *   The result of the decorated __call().
   */
  public function __call($name, array $arguments) {
    debug($name);
    debug($arguments);
    return call_user_func_array(array($this->cursor, $name), $arguments);
  }

}

/**
 * Class MongodbDummy is a mock object accepting any method and doing nothing.
 *
 * It is used to mock both databases and collections.
 */
class MongodbDummy {

  /**
   * The collection name, if applicable.
   *
   * @var string
   */
  protected $collection;

  /**
   * The Mongo or MongoClient instance, if the objects is used as a database.
   *
   * @var \MongoClient
   *
   * @see mongod()
   * @see mongodb_collection()
   */
  public $connection;

  /**
   * Constructor.
   *
   * @param string|NULL $name
   *   If this parameter is passed, the object is used as a collection,
   *   otherwise it is used as a database.
   */
  public function __construct($name = NULL) {
    if (isset($name)) {
      $this->collection = $name;
    }
  }

  /**
   * Pretend to return a collection.
   *
   * @param string $name
   *   The name of a collection.
   *
   * @return \MongodbDummy
   *   The returned value is actually a new MongodbDummy instance.
   */
  public function selectCollection() {
    return new MongodbDummy();
  }

  /**
   * Magic __call accepting any method name and doing nothing.
   *
   * @param string $name
   *   Ignored.
   * @param array $arguments
   *   All arguments are ignored.
   */
  public function __call($name, array $arguments) {
  }

}

/**
 * Returns the name to use for the collection.
 *
 * Also works with prefixes and simpletest.
 *
 * @param string $name
 *   The base name for the collection.
 *
 * @return string
 *   Unlike the base name, the returned name works with prefixes and simpletest.
 */
function mongodb_collection_name($name) {
  static $simpletest_prefix;
  // We call this function earlier than the database is initialized so we would
  // read the parent collection without this.
  if (!isset($simpletest_prefix)) {
    if (isset($_SERVER['HTTP_USER_AGENT']) && preg_match("/^(simpletest\d+);/", $_SERVER['HTTP_USER_AGENT'], $matches)) {
      $simpletest_prefix = $matches[1];
    }
    else {
      $simpletest_prefix = '';
    }
  }
  // However, once the test information is initialized, simpletest_prefix
  // is no longer needed.
  if (!empty($GLOBALS['drupal_test_info']['test_run_id'])) {
    $simpletest_prefix = $GLOBALS['drupal_test_info']['test_run_id'];
  }
  return $simpletest_prefix . $name;
}

/**
 * Implements hook_test_group_finished().
 *
 * Testing helper: cleanup after test group.
 *
 * @throws \InvalidArgumentException
 *   If the database cannot be selected.
 * @throws \MongoConnectionException
 *   If the connection cannot be established.
 */
function mongodb_test_group_finished() {
  $aliases = variable_get('mongodb_connections', array());
  $aliases['default'] = TRUE;
  foreach (array_keys($aliases) as $alias) {
    $db = mongodb($alias);
    foreach ($db->listCollections() as $collection) {
      if (preg_match('/\.simpletest\d+/', $collection)) {
        $db->dropCollection($collection);
      }
    }
  }
}

/**
 * Allow for the database connection we are using to be changed.
 *
 * @param string $alias
 *   The alias that we want to change the connection for.
 * @param string $connection_name
 *   The name of the connection we will use.
 */
function mongodb_set_active_connection($alias, $connection_name = 'default') {
  // No need to check if the connection is valid as mongodb() does this.
  $alias_exists = isset($GLOBALS['conf']['mongodb_collections'][$alias]) && is_array($GLOBALS['conf']['mongodb_collections'][$alias]);
  if ($alias_exists & !empty($GLOBALS['conf']['mongodb_collections'][$alias]['db_connection'])) {
    $GLOBALS['conf']['mongodb_collections'][$alias]['db_connection'] = $connection_name;
  }
  else {
    $GLOBALS['conf']['mongodb_collections'][$alias] = $connection_name;
  }
}

/**
 * Return the next id in a sequence.
 *
 * @param string $name
 *   The name of the sequence.
 * @param int $existing_id
 *   The maximum known existing id : the result must be greater than this.
 *
 * @return int
 *   The next id in a sequence.
 *
 * @throws \InvalidArgumentException
 *   If the database cannot be selected.
 *
 * @throws \MongoConnectionException
 *   If the connection cannot be established.
 */
function mongodb_next_id($name, $existing_id = 0) {
  // Atomically get the next id in the sequence.
  $mongo = mongodb();
  $cmd = array(
    'findandmodify' => mongodb_collection_name('sequence'),
    'query' => array('_id' => $name),
    'update' => array('$inc' => array('value' => 1)),
    'new' => TRUE,
  );
  // It's very likely that this is not necessary as command returns an array
  // not an exception. The increment will, however, will fix the problem of
  // the sequence not existing. Still, better safe than sorry.
  try {
    $sequence = $mongo->command($cmd);
    $value = isset($sequence['value']['value']) ? $sequence['value']['value'] : 0;
  }
  catch (Exception $e) {
    $value = 0;
  }
  if (0 < $existing_id - $value + 1) {
    $cmd = array(
      'findandmodify' => mongodb_collection_name('sequence'),
      'query' => array('_id' => $name),
      'update' => array('$inc' => array('value' => $existing_id - $value + 1)),
      'upsert' => TRUE,
      'new' => TRUE,
    );
    $sequence = $mongo->command($cmd);
    $value = isset($sequence['value']['value']) ? $sequence['value']['value'] : 0;
  }

  return $value;
}

/**
 * Returns default options for MongoDB write operations.
 *
 * @param bool $safe
 *   Set it to FALSE for "fire and forget" write operation.
 *
 * @return array
 *   Default options for Mongo write operations.
 */
function mongodb_default_write_options($safe = TRUE) {
  if ($safe) {
    if (version_compare(phpversion('mongo'), '1.5.0') == -1) {
      return array('safe' => TRUE);
    }
    else {
      return variable_get('mongodb_write_safe_options', array('w' => 1));
    }
  }
  else {
    if (version_compare(phpversion('mongo'), '1.3.0') == -1) {
      return array();
    }
    else {
      return variable_get('mongodb_write_nonsafe_options', array('w' => 0));
    }
  }
}

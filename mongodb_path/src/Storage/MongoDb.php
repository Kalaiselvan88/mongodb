<?php

/**
 * @file
 * Contains the MongoDB path alias storage.
 */

namespace Drupal\mongodb_path\Storage;


/**
 * Class MongoDb
 *
 * @package Drupal\mongodb_path
 */
class MongoDb implements StorageInterface {
  /**
   * Pseudo-typing: defined recognized keys for aliases.
   */
  const ALIAS_KEYS = [
    '_id' => 1,
    'alias' => 1,
    'first' => 1,
    'language' => 1,
    'pid' => 1,
    'source' => 1,
  ];

  /**
   * The MongoDB collection containing the alias storage data.
   *
   * @var \MongoCollection
   */
  protected $collection;

  /**
   * The MongoDB database holding the alias storage collection.
   *
   * @var \MongoDB
   */
  protected $mongo;

  /**
   * Storage constructor.
   *
   * @param \MongoDB|\MongoDummy $mongo
   *   A MongoDB database in which to access the alias storage collection.
   */
  public function __construct(\MongoDB $mongo) {
    _mongodb_path_trace();
    $this->mongo = $mongo;
    $this->collection = $mongo->selectCollection(static::COLLECTION_NAME);
  }

  /**
   * {@inheritdoc}
   */
  public function clear() {
    _mongodb_path_trace();
    $this->collection->drop();
    $this->collection = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(array $criteria) {
    _mongodb_path_trace();
    $criteria = array_intersect_key($criteria, static::ALIAS_KEYS);
    $this->collection->remove($criteria);
  }

  /**
   * Create the collection and its indexes if needed.
   *
   * This method has to be public because it is needed by hook_install(), since
   * the MongoDB package does not have an equivalent to hook_schema(), but it
   * MUST NOT be used in other cases, and should be considered protected for all
   * intents and purposes.
   *
   * Document minimal structure is:
   * - first : the first segment of the system path, for the whitelist
   * - langcode: the langcode for an alias
   * - source: the system path for an alias/langcode
   * - alias: the alias for a source/langcode
   */
  public function ensureSchema() {
    _mongodb_path_trace();
    $collection = $this->mongo->selectCollection(static::COLLECTION_NAME);

    // This one is just an accelerator, so there is no need to wait on it.
    $collection->createIndex([
      'first' => 1,
    ], [
      'background' => TRUE,
    ]);

    // These ones are structural: they need to be valid to ensure uniqueness,
    // so they cannot be built in the background.
    $options = [
      'unique' => TRUE,
      'background' => FALSE,
    ];
    $collection->createIndex([
      'pid' => 1,
    ], $options);

    $options = [
      'unique' => FALSE,
      'background' => FALSE,
    ];
    $collection->createIndex([
      'alias' => 1,
      'language' => 1,
      'pid' => 1,
    ], $options);

    $collection->createIndex([
      'source' => 1,
      'language' => 1,
      'pid' => 1,
    ], $options);
  }

  /**
   * {@inheritdoc}
   */
  public function getWhitelist() {
    _mongodb_path_trace();
    $result = (array) $this->collection->distinct('first');
    $result = array_combine($result, array_fill(0, count($result), 1));
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function load(array $conditions) {
    _mongodb_path_trace();

    /* This specific instance of findOne() does not return a generic array, but
     * a string[], because _id is removed from the results, and all other
     * document properties are integer, hence the more specific doc-ing.
     */

    /** @var string[]|NULL $result */
    $result = $this->collection->findOne($conditions, ['first' => 0, '_id' => 0]);
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function lookupAliases(array $paths, $language, $first_pass = FALSE) {
    $languages = ($language == LANGUAGE_NONE)
      ? [LANGUAGE_NONE]
      : [LANGUAGE_NONE, $language];

    $criteria = [
      'source' => ['$in' => $paths],
      'language' => ['$in' => $languages],
    ];

    if ($first_pass) {
      $sort = $language <= LANGUAGE_NONE
        ? ['language' => 1, 'pid' => 1]
        : ['language' => -1, 'pid' => 1];
    }
    else {
      $sort = $language >= LANGUAGE_NONE
        ? ['language' => -1, 'pid' => -1]
        : ['language' => 1, 'pid' => -1];
    }

    $fields = ['source' => 1, 'alias' => 1, 'language' => 1, 'pid' => 1, '_id' => 0];
    $cursor = $this->collection->find($criteria, $fields)->sort($sort);
    $result = [];

    // For a single path, only use the first alias to respect the core ordering.
    if (!$first_pass) {
      $rows = iterator_to_array($cursor);
      $cursor = array_slice($rows, 0, 1);
    }

    foreach ($cursor as $row) {
      $result[$row['source']] = $row['alias'];
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array &$path) {
    _mongodb_path_trace();
    $options = [
      // This should not matter, as alias are presumed to match uniquely.
      'multiple' => FALSE,

      'upsert' => TRUE,
      'w' => 1,
    ];

    $criterium = array_intersect_key($path, ['pid' => 1]);
    $path = array_intersect_key($path, static::ALIAS_KEYS);
    if (!isset($path['first'])) {
      $path['first'] = strtok($path['source'], '/');
    }

    $this->collection->update($criterium, $path, $options);
  }

}

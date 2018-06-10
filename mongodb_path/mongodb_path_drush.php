<?php

/**
 * @file
 * Drush plugin for MongoDB Path plugin.
 */

use Drupal\mongodb_path\ResolverFactory;

/**
 * Implements hook_drush_command().
 */
function mongodb_path_drush_command() {

  $items = [];

  $items['mongodb-path-import'] = [
    'description' => 'Import the aliases from {url_alias} to MongoDB',
    'aliases' => ['mpi'],
    'options' => [
      'drop' => [
        'description' => 'Drop existing aliases in MongoDB prior to import ? Defaults to 1, meaning Yes.',
        'value' => 'optional',
        'example-value' => '0|1',
      ],
    ],
  ];

  return $items;
}

/**
 * Command callback for mongodb-path-import.
 *
 * @throws \InvalidArgumentException
 *   If the database cannot be selected.
 * @throws \MongoConnectionException
 *   If the connection cannot be established.
 */
function drush_mongodb_path_import() {
  $drop = (bool) (drush_get_option('drop', 1));

  $resolver = ResolverFactory::create();
  $resolver->import($drop);
}

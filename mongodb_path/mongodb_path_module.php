<?php

/**
 * @file
 * MongoDB Path.
 */

/**
 * Implements hook_exit().
 *
 * Dumps the plugin trace. Note that running this display during hook_exit()
 * causes the dump to display on the next page, not the one it is generated on.
 *
 * @global $mongodb_path_tracer
 */
function mongodb_path_exit() {
  $q = $_GET['q'];
  // Skip admin_menu normal hits.
  if (strpos($q, 'js/admin_menu/cache') === 0) {
    return;
  }

  global $_mongodb_path_tracer;
  if (!empty($_mongodb_path_tracer['enabled']) && function_exists('dpm')) {
    dpm($_mongodb_path_tracer, $q);
  }
}

/**
 * Implements hook_flush_caches().
 *
 * Core expects to flush the "path cache" during full flushes, so we may want
 * to honor this behavior, although it is costly.
 */
function mongodb_path_flush_caches() {
  $resolver = mongodb_path_resolver();
  if ($resolver->isFlushRequired()) {
    $resolver->flush();
  }

  return [];
}
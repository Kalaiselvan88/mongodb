<?php

namespace Drupal\mongodb_watchdog\Controller;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Controller\ControllerBase as CoreControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\mongodb_watchdog\Logger;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base controller class for paged reports.
 */
abstract class ControllerBase extends CoreControllerBase {

  use LoggerAwareTrait;

  /**
   * The items_per_page configuration value.
   *
   * @var int
   */
  protected $itemsPerPage;

  /**
   * The MongoDB logger, to load events.
   *
   * @var \Drupal\mongodb_watchdog\Logger
   */
  protected $watchdog;

  /**
   * ControllerBase constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.channel.mongodb_watchdog service.
   * @param \Drupal\mongodb_watchdog\Logger $watchdog
   *   The mongodb.logger service, to load stored events.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The mongodb_watchdog configuration.
   */
  public function __construct(LoggerInterface $logger, Logger $watchdog, ImmutableConfig $config) {
    $this->setLogger($logger);

    $this->itemsPerPage = $config->get('items_per_page');
    $this->watchdog = $watchdog;
  }

  /**
   * The default build() implementation.
   *
   * Cannot be a build() method because each controller build() has a
   * different signature.
   *
   * @param array $main
   *   A render array for the main table.
   * @param array|null $top
   *   A render array for the top element present on some controllers results.
   *
   * @return string[string|array]
   *   A render array for the whole controller.
   */
  protected function buildDefaults(array $main, array $top = NULL) {
    $ret = empty($top) ? [] : ['top' => $top];

    $ret += [
      'main' => $main,
      'pager' => ['#type' => 'pager'],
      '#attached' => [
        'library' => ['mongodb_watchdog/styling'],
      ],
    ];

    return $ret;
  }

  /**
   * Build markup for a message about the lack of results.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $markup
   *   The message proper.
   *
   * @return string[string|\Drupal\Core\StringTranslation\TranslatableMarkup]
   *   A render array for a message.
   */
  protected function buildEmpty(TranslatableMarkup $markup) {
    $ret = [
      '#markup' => $markup,
      '#prefix' => '<div class="mongodb-watchdog__message">',
      '#suffix' => '</div>',
    ];

    return $ret;
  }

  /**
   * Return the top element: empty by default.
   *
   * @return array
   *   A render array for the top filter form.
   */
  protected function getTop() {
    $top = NULL;
    return $top;
  }

  /**
   * Set up the pager.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param int $count
   *   The total number of possible rows.
   *
   * @return int
   *   The number of the page to display, starting at 0.
   */
  public function setupPager(Request $request, $count) {
    $height = $this->itemsPerPage;
    pager_default_initialize($count, $height);

    $requestedPage = intval($request->query->get('page', 0));
    $page = $this->getPage($count, $requestedPage, $height);

    return $page;
  }

  /**
   * Return a reliable page number based on available data.
   *
   * @param int $count
   *   The number of events templates in the collection.
   * @param int $requestedPage
   *   The page number requested by the user, starting at 0.
   * @param int $height
   *   The pager height.
   *
   * @return int
   *   The actual index of the page to display.
   */
  public static function getPage(int $count, int $requestedPage, int $height): int {
    if ($requestedPage <= 0) {
      return 0;
    }

    // There is always at least one page, even with $count === 0.
    $pageCount = max(1, intval(ceil($count / $height)));
    if ($requestedPage < $pageCount) {
      return $requestedPage;
    }

    $page = $pageCount - 1;
    return $page;
  }

}

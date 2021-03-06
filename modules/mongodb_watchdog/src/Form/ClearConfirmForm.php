<?php

namespace Drupal\mongodb_watchdog\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\mongodb_watchdog\Logger;
use MongoDB\Database;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form before clearing out the logs.
 *
 * D8 has no session API, so use of $_SESSION is required, so ignore warnings.
 *
 * @SuppressWarnings("PHPMD.Superglobals")
 */
class ClearConfirmForm extends ConfirmFormBase {

  /**
   * The logger database.
   *
   * @var \MongoDB\Database
   */
  protected $database;

  /**
   * The MongoDB watchdog "logger" service.
   *
   * @var \Drupal\mongodb_watchdog\Logger
   */
  protected $logger;

  /**
   * ClearConfirmForm constructor.
   *
   * @param \MongoDB\Database $database
   *   The MongoDB logger database.
   * @param \Drupal\mongodb_watchdog\Logger $logger
   *   The mongodb.logger service.
   */
  public function __construct(Database $database, Logger $logger) {
    $this->database = $database;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('mongodb.watchdog_storage'),
      $container->get(Logger::SERVICE_LOGGER)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mongodb_watchdog_clear_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete the recent logs?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('mongodb_watchdog.reports.overview');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $formState) {
    $_SESSION['mongodb_watchdog_overview_filter'] = [];
    $this->database->drop();
    $this->logger->ensureSchema();
    $this->messenger()->addMessage($this->t('Database log cleared.'));
    $formState->setRedirectUrl($this->getCancelUrl());
  }

}

<?php

namespace Drupal\event_database_push\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Itk\EventDatabaseClient\Client;
use Itk\EventDatabaseClient\Item\Event;
use Itk\EventDatabaseClient\ObjectTransformer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Handler class for event database push.
 */
class Handler {
  use StringTranslationTrait;

  /**
   * The configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private ImmutableConfig $configuration;

  /**
   * Constructor for Handler class.
   */
  public function __construct(protected ConfigFactoryInterface $configFactory, protected Connection $connection, protected LoggerInterface $logger, protected MessengerInterface $messenger) {
    $this->configuration = $configFactory->get('event_database_push.settings');
  }

  /**
   * Handle method.
   *
   * @param \Drupal\node\NodeInterface $node
   *   A node to handle.
   * @param string $action
   *   An action to perform on the node.
   */
  public function handle(NodeInterface $node, string $action): void {
    if (!$this->canHandle($node)) {
      return;
    }

    $this->logger->info($action . '; ' . $node->id());
    $apiData = $this->getApiData($node);
    $client = $this->getApiClient();

    switch ($action) {
      case 'delete':
        if (isset($apiData->event)) {
          $event = $apiData->event;
          $success = $client->deleteEvent($event->id);
          if ($success) {
            $this->deleteApiData($node);
            $this->logger->info(
              $this->t('Event "@title" (@id; @apiEventId) deleted from Event database', [
                '@title' => $node->getTitle(),
                '@id' => $node->id(),
                '@apiEventId' => $event->getItemId(),
              ])
            );
          }
          else {
            $this->messenger->addMessage(
              $this->t('Error deleting event "@title" from Event database', [
                '@title' => $node->getTitle(),
              ]), 'error');
            $this->logger->error(
              $this->t('Error deleting event "@title" (@id) from Event database', [
                '@title' => $node->getTitle(),
                '@id' => $node->id(),
              ])
            );
          }
        }
        break;

      case 'insert':
        $eventData = $this->getEventData($node);
        $event = $client->createEvent($eventData);
        if ($event) {
          $this->logger->info(
            $this->t('Event "@title" (@id; @apiEventId) created in Event database', [
              '@title' => $node->getTitle(),
              '@id' => $node->id(),
              '@apiEventId' => $event->getItemId(),
            ]
            )
          );
        }
        else {
          $this->messenger->addMessage(
            $this->t('Cannot create event "@title" in Event database', [
              '@title' => $node->getTitle(),
            ]), 'error');
          $this->logger->error(
            $this->t('Cannot create event "@title" (@id) in Event database', [
              '@title' => $node->getTitle(),
              '@id' => $node->id(),
            ]
            )
          );
          return;
        }
        $this->updateApiData($node, $event);
        break;

      case 'update':
        $eventData = $this->getEventData($node);
        if (isset($apiData->event)) {
          $event = $apiData->event;
          $success = $client->updateEvent($event->id, $eventData);
          if ($success) {
            $this->logger->info(
              $this->t('Event "@title" (@id; @apiEventId) updated in Event database', [
                '@title' => $node->getTitle(),
                '@id' => $node->id(),
                '@apiEventId' => $event->getItemId(),
              ]
              )
            );
          }
          else {
            $this->messenger->addMessage(
              $this->t('Cannot update event "@title" in Event database', [
                '@title' => $node->getTitle(),
              ]), 'error');
            $this->logger->error(
              $this->t('Cannot update event "@title" (@id; @apiEventId) in Event database', [
                '@title' => $node->getTitle(),
                '@id' => $node->id(),
                '@apiEventId' => $event->getItemId(),
              ])
            );
          }
        }
        else {
          $apiData = new \stdClass();
          $event = $client->createEvent($eventData);
          if ($event) {
            $this->logger->info($this->t('Event "@title" (@id; @apiEventId) created in Event database', [
              '@title' => $node->getTitle(),
              '@id' => $node->id(),
              '@apiEventId' => $event->getItemId(),
            ]));
          }
          else {
            $this->messenger->addMessage(
              $this->t('Cannot create event "@title" in Event database', [
                '@title' => $node->getTitle(),
              ]), 'error');
            $this->logger->error($this->t('Cannot create event "@title" in Event database', [
              '@title' => $node->getTitle(),
              '@id' => $node->id(),
            ]));
            return;
          }
        }
        $this->updateApiData($node, $event);
        break;
    }
  }

  /**
   * Get event data.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to get event data for.
   *
   * @return array<string, mixed>
   *   The event data.
   */
  private function getEventData(NodeInterface $node) {
    $valueHandler = new ValueHandler();
    $transformer = new ObjectTransformer($valueHandler);
    $config = $this->getMapping($node);

    $data = $transformer->transformObject($node, $config);

    $url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()], ['absolute' => TRUE]);
    $data += [
      'url' => $url->toString(),
    ];

    return $data;
  }

  /**
   * Get API Data.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to get api data for.
   *
   * @return mixed|null
   *   The api data.
   */
  private function getApiData(NodeInterface $node) {
    $sql = 'select * from {event_database_push_data} where type = :type and nid = :nid';
    $params = ['type' => $node->getType(), 'nid' => $node->id()];
    $result = $this->connection->query($sql, $params)->fetchObject();
    $apiData = $result ? json_decode($result->data) : NULL;

    return $apiData;
  }

  /**
   * Update data.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \Itk\EventDatabaseClient\Item\Event $event
   *   Event db event.
   */
  private function updateApiData(NodeInterface $node, Event $event): void {
    $now = (new \DateTime())->format(\DateTime::ISO8601);

    $apiData = $this->getApiData($node);
    if ($apiData) {
      $sql = 'UPDATE {event_database_push_data} SET data = :data WHERE type = :type AND nid = :nid AND eid = :eid';
    }
    else {
      $apiData = new \stdClass();
      $apiData->created_at = $now;
      $sql = 'INSERT INTO {event_database_push_data}(type, nid, data, eid) VALUES (:type, :nid, :data, :eid)';
    }
    $apiData->event = [
      'id' => $event->getItemId(),
    ];
    $apiData->updated_at = $now;
    $params = [
      'type' => $node->getType(),
      'nid' => $node->id(),
      'data' => json_encode($apiData),
      'eid' => $event->getItemId(),
    ];
    $this->connection->query($sql, $params);
  }

  /**
   * Delete data.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   */
  private function deleteApiData(NodeInterface $node): void {
    $sql = 'DELETE FROM {event_database_push_data} WHERE type = :type and nid = :nid';
    $params = [
      'type' => $node->getType(),
      'nid' => $node->id(),
    ];
    $this->connection->query($sql, $params);
  }

  /**
   * Get Api client.
   *
   * @return \Itk\EventDatabaseClient\Client
   *   The api client.
   */
  private function getApiClient() {
    $config = $this->configuration->get('api');
    $client = new Client($config['url'], $config['username'], $config['password']);

    return $client;
  }

  /**
   * Check if node is handleable.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *   The node.
   *
   * @return bool
   *   True if node is handleable.
   */
  private function canHandle(EntityInterface $node) {
    if (!$node instanceof NodeInterface) {
      return FALSE;
    }
    return $this->getMapping($node) !== NULL;
  }

  /**
   * Get node mapping.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return mixed|null
   *   The mapping.
   */
  private function getMapping(NodeInterface $node) {
    try {
      $value = $this->configuration->get('mapping.content_types');
      $config = Yaml::parse($value);
      return $config[$node->getType()] ?? NULL;
    }
    catch (ParseException $ex) {
      return NULL;
    }
  }

}

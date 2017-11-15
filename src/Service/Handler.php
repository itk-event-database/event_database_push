<?php

namespace Drupal\event_database_push\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Itk\EventDatabaseClient\Client;
use Itk\EventDatabaseClient\ObjectTransformer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 */
class Handler {
  protected $configuration;
  protected $connection;
  protected $logger;

  public function __construct(ConfigFactoryInterface $configFactory, Connection $connection, LoggerInterface $logger) {
    $this->configuration = $configFactory->get('event_database_push.settings');
    $this->connection = $connection;
    $this->logger = $logger;
  }

  public function handle(EntityInterface $node, $action) {
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
            $this->logger->info(t('Event "@title" (@id; @apiEventId) deleted from Event database', ['@title' => $node->title->value, '@id' => $node->id(), '@apiEventId' => $event->id]));
          } else {
            drupal_set_message(t('Error deleting event "@title" from Event database', ['@title' => $node->title->value]), 'error');
            $this->logger->error(t('Error deleting event "@title" (@id) from Event database', ['@title' => $node->title->value, '@id' => $node->id()]));
          }
        }
        break;

      case 'insert':
        $eventData = $this->getEventData($node);
        $event = $client->createEvent($eventData);
        if ($event) {
          $this->logger->info(t('Event "@title" (@id; @apiEventId) created in Event database', ['@title' => $node->title->value, '@id' => $node->id(), '@apiEventId' => $event->id]));
        } else {
          drupal_set_message(t('Cannot create event "@title" in Event database', ['@title' => $node->title->value]), 'error');
          $this->logger->error(t('Cannot create event "@title" (@id) in Event database', ['@title' => $node->title->value, '@id' => $node->id()]));
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
            $this->logger->info(t('Event "@title" (@id; @apiEventId) updated in Event database', ['@title' => $node->title->value, '@id' => $node->id(), '@apiEventId' => $event->id]));
          } else {
            drupal_set_message(t('Cannot update event "@title" in Event database', ['@title' => $node->title->value]), 'error');
            $this->logger->error(t('Cannot update event "@title" (@id; @apiEventId) in Event database', ['@title' => $node->title->value, '@id' => $node->id(), '@apiEventId' => $event->id]));
          }
        } else {
          $apiData = new \stdClass();
          $event = $client->createEvent($eventData);
          if ($event) {
            $this->logger->info(t('Event "@title" (@id; @apiEventId) created in Event database', [
              '@title' => $node->title->value,
              '@id' => $node->id(),
              '@apiEventId' => $event->id
            ]));
          }
          else {
            drupal_set_message(t('Cannot create event "@title" in Event database', ['@title' => $node->title->value]), 'error');
            $this->logger->error(t('Cannot create event "@title" (@id; @apiEventId) in Event database', [
              '@title' => $node->title->value,
              '@id' => $node->id(),
              '@apiEventId' => $event->id
            ]));
            return;
          }
        }
        $this->updateApiData($node, $event);
        break;
    }
  }

  private function getEventData(NodeInterface $node) {
    $valueHandler = new ValueHandler();
    $transformer = new ObjectTransformer($valueHandler);
    $config = $this->getMapping($node);

    $data = $transformer->transformObject($node, $config);

    $url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()], ['absolute' => true]);
    $data += [
      'url' => $url->toString(),
    ];

    return $data;
  }

  private function getApiData(NodeInterface $node) {
    $sql = 'select * from {event_database_push_data} where type = :type and nid = :nid';
    $params = ['type' => $node->getType(), 'nid' => $node->id()];
    $result = $this->connection->query($sql, $params)->fetchObject();
    $apiData = $result ? json_decode($result->data) : null;

    return $apiData;
  }

  private function updateApiData(NodeInterface $node, $event) {
    $now = (new \DateTime())->format(\DateTime::ISO8601);

    $apiData = $this->getApiData($node);
    if ($apiData) {
      $sql = 'UPDATE {event_database_push_data} SET data = :data WHERE type = :type AND nid = :nid AND eid = :eid';
    } else {
      $apiData = new \stdClass();
      $apiData->created_at = $now;
      $sql = 'INSERT INTO {event_database_push_data}(type, nid, data, eid) VALUES (:type, :nid, :data, :eid)';
    }
    $apiData->event = [
      'id' => $event->id,
    ];
    $apiData->updated_at = $now;
    $params = [
      'type' => $node->getType(),
      'nid' => $node->id(),
      'data' => json_encode($apiData),
      'eid' => $event->id,
    ];
    $this->connection->query($sql, $params);
  }

  private function deleteApiData(NodeInterface $node) {
    $sql = 'DELETE FROM {event_database_push_data} WHERE type = :type and nid = :nid';
    $params = [
      'type' => $node->getType(),
      'nid' => $node->id(),
    ];
    $this->connection->query($sql, $params);
  }

  private function getApiClient() {
    $config = $this->configuration->get('api');
    $client = new Client($config['url'], $config['username'], $config['password']);

    return $client;
  }

  private function canHandle(EntityInterface $node) {
    if (!$node instanceof NodeInterface) {
      return false;
    }
    return $this->getMapping($node) !== null;
  }

  private function getMapping(NodeInterface $node) {
    try {
      $value = $this->configuration->get('mapping.content_types');
      $config = Yaml::parse($value);
      return isset($config[$node->getType()]) ? $config[$node->getType()] : null;
    } catch (ParseException $ex) {
      return null;
    }
  }

}

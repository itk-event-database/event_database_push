<?php

namespace Drupal\event_database_push\Tests\Service;

use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Itk\EventDatabaseClient\Client;

/**
 * Functional test for the Event push handler.
 *
 * @group event_database_push
 */
class HandlerTest extends BrowserTestBase {

  /**
   * Default theme.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * List of modules.
   *
   * @var string[]
   */
  public static $modules = [
    'user',
    'system',
    'field',
    'text',
    'filter',
    'entity_test',
    'node',
    'event_database_push',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    $config = $this->config('event_database_push.settings');
    $config->setData([
      'api' => [
        'url' => 'http://event-database-api.vm/api',
        'username' => 'api-write',
        'password' => 'apipass',
      ],
      'mapping' => [
        'content_types' => '
event:
 type: event
 mapping:
  name: title
  description: field_description
  occurrences:
   mapping:
    startDate: field_start_time
    endDate: field_end_time
    place:
     defaults:
      name: Dokk1
 defaults:
  langcode: da

room:
 type: place
 mapping:
  name: name
',
      ],
      'feed' => [
        'content_types' => '
event:
 type: event
 mapping:
  name: title
  description: field_description
  startDate: field_start_time
  endDate: field_end_time
  place:
   defaults:
    name: Dokk1
 defaults:
  langcode: da

room:
 type: place
 mapping:
  name: name
',
      ],
    ]);
    $config->save();
  }

  /**
   * Create a test event.
   */
  public function testCreateEvent() {
    $client = new Client('http://event-database-api.vm/api', 'api-write', 'apipass');

    $name = uniqid(__FUNCTION__) . ' ' . date('c');

    // Test that creating and saving a node creates an Event with the API.
    $numberOfEvents = $client->getEvents()->getCount();

    $event = Node::create([
      'type' => 'event',
      'title' => $name,
    ]);
    $event->save();
    $this->assertEqual($numberOfEvents + 1, $client->getEvents()->getCount());

    // Test that we can get the event (by name)
    $events = $client->getEvents(['name' => $name])->getItems();
    $this->assertEqual(1, count($events));
    $this->assertEqual($name, $events[0]->getName());

    // Test that renaming the node also renames in the API.
    $newName = uniqid(__FUNCTION__) . ' ' . date('c');

    $event = Node::load($event->id());
    $event->set('title', $newName);
    $event->save();

    $this->assertEqual(0, $client->getEvents(['name' => $name])->getCount());
    $this->assertEqual(1, $client->getEvents(['name' => $newName])->getCount());

    // Test that deleting the node also deleted in the API.
    $event->delete();

    $this->assertEqual(0, $client->getEvents(['name' => $newName])->getCount());
  }

}

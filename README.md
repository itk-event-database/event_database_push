# Event database push (Drupal module)

## Installation ##

```
cd «drupal root»
composer config repositories.itk composer https://composer.etek.dk/
composer require drupal/event_database_push
```

## Running tests


Install the SimpleTest module:

```
drush --yes pm-enable simpletest
```

Create a test database:

```
mysql --user=root --password=... <<<EOL
create database test;
EOL
```

Run the tests:

```
php $(drush php-eval 'print DRUPAL_ROOT')/core/scripts/run-tests.sh --verbose event_database_push
```

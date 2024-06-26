# Quicksilver New Relic Deployment Tracking

This Quicksilver project is used for automation of deployment tracking with New Relic on Pantheon platform.

### Installation

This project is designed to be included from a site's `composer.json` file, and placed in its appropriate installation directory by [Composer Installers](https://github.com/composer/installers). 

The project can be included by using the command:

`composer require kalamuna/quicksilver-newrelic-tracking`

Don't forget to update the `pantheon.yml` file for your Drupal 8/9 installation and connect your application to NewRelic thought Pantheon dashboard.

### Example `pantheon.yml`

Here's an example of what your `pantheon.yml` would look like if this were the only Quicksilver operation you wanted to use.

```yaml
workflows:
  # Log to New Relic when deploying to test or live.
  deploy:
    after:
      - type: webphp
        description: Log to New Relic
        script: private/scripts/quicksilver/quicksilver-newrelic-tracking/new_relic_deploy.php
  # Also log sync_code so you can track new code going into dev/multidev.
  sync_code:
    after:
      - type: webphp
        description: Log to New Relic
        script: private/scripts/quicksilver/quicksilver-newrelic-tracking/new_relic_deploy.php
```

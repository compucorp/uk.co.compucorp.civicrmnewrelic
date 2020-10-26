# CiviCRM New Relic

New Relic has [builtin support for many PHP frameworks and libraries](https://docs.newrelic.com/docs/agents/php-agent/frameworks-libraries/php-frameworks-integrate-support-new-relic). This includes the automatic generation of transaction names based on how routes are defined/executed in these frameworks. For example, this is how it generates the transaction names for Drupal 7:

> The dispatching is done in menu_execute_active_handler(). This calls call_user_func_array(), with the first argument being the name of the action. That is what to set as the web transaction name.

Since almost all the requests to [CiviCRM are handled by the same callback](https://github.com/civicrm/civicrm-drupal/blob/e01c65287f18f34c110ff826097692752e4891a3/civicrm.module#L118), they will all receive the same transaction name ('civicrm_invoke') in New Relic.

This extension generates more descriptive transaction names for CiviCRM requests on New Relic:

- For API requests using the Ajax Interface (i.e. requests sent to `/civicrm/ajax/rest`), the transaction name will be set to `Entity.Action`.
- For regular HTTP requests, the transaction name will be the entire URL path. For example, requests to the Contact Summary page will be recorded as `/civicrm/contact/view`.

## Requirements

- PHP 7.2+
- The PHP New Relic agent must be installed and enabled

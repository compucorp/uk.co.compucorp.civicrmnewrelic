<?php

/**
 * @file
 * Extension file.
 */

/**
 * Generates a better, more descriptive, transaction name for New Relic.
 *
 * For APIs, the transaction name will look like `Entity.Action`.
 *
 * For calls to the Api3.call API, a inner_calls param will be added to the
 * transaction and it will contain a list of the inner API calls.
 *
 * For any other requests, the transaction name will be the Request URI. This
 * will result in names like /civicrm/contact/view, for example, instead of a
 * generic civicrm_invoke (which is what New Relic uses by default).
 *
 * The hook_civicrm_config hook is the very first one triggered in the
 * CiviCRM boostrap process and it gets called in every request, so it's the
 * perfect candidate for this piece of functionality.
 *
 * @param \CRM_Core_Config $config
 *   The CiviCRM config object.
 */
function civicrmnewrelic_civicrm_config(CRM_Core_Config $config): void {
  /*
   * When Civi is called from the CLI, the $_SERVER variable will not contain
   * the necessary data for this function to generate the transaction name, so
   * there's nothing we can do other than stop here.
   */
  if (PHP_SAPI === 'cli' || !extension_loaded('newrelic')) {
    return;
  }

  $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
  if (empty($uri)) {
    // No usable path (e.g. missing/malformed REQUEST_URI); nothing to name.
    return;
  }

  if ($uri === '/civicrm/ajax/rest') {
    $entity = $_REQUEST['entity'] ?? NULL;
    $action = $_REQUEST['action'] ?? NULL;

    /*
     * entity/action are always string|null from CiviCRM's REST dispatcher.
     * Guard against non-string values (e.g. array-style "entity[]=x") and
     * empty strings. The literal string "0" is intentionally allowed here
     * (the previous truthiness check rejected it); this is harmless as no
     * real entity/action is "0".
     */
    $entityValid = is_string($entity) && $entity !== '';
    $actionValid = is_string($action) && $action !== '';
    if (!$entityValid || !$actionValid) {
      return;
    }

    $innerCalls = [];

    /*
     * This handles the case where multiple API calls are sent in one single
     * HTTP request. A custom parameter called inner_calls is added to the
     * New Relic transaction so it's possible to know exactly which API calls
     * were sent.
     */
    if ($entity === 'api3' && $action === 'call'
      && !empty($_POST['json']) && is_string($_POST['json'])) {
      $apiCalls = json_decode($_POST['json'], TRUE, 512);

      if (is_array($apiCalls)) {
        foreach ($apiCalls as $apiCall) {
          if (is_array($apiCall) && isset($apiCall[0], $apiCall[1])
            && is_string($apiCall[0]) && is_string($apiCall[1])) {
            $innerCalls[] = "{$apiCall[0]}.{$apiCall[1]}";
          }
        }
      }
    }

    newrelic_name_transaction("$entity.$action");
    if (!empty($innerCalls)) {
      newrelic_add_custom_parameter('inner_calls', implode(', ', $innerCalls));
    }
  }
  else {
    newrelic_name_transaction($uri);
  }
}

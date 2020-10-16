<?php

/**
 * This function inspects any requests to CiviCRM pages and API and generates
 * a better, more descriptive, transaction name for New Relic.
 *
 * For APIs, the transaction name will look like Entity.Action.
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

  $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  $transactionName = null;

  if ($uri === '/civicrm/ajax/rest') {
    $entity = $_REQUEST['entity'] ?? null;
    $action = $_REQUEST['action'] ?? null;
    $innerCalls = null;

    /*
     * This handles the case where multiple API calls are sent in one single
     * HTTP request.
     * In cases like this, a custom parameter called inner_calls will be added
     * to the New Relic transaction, so that it's possible to know exactly
     * which API calls have been sent.
     */
    if ($entity === 'api3' && $action === 'call' && !empty($_POST['json'])) {
      $apiCalls = json_decode($_POST['json'], false, 512);

      if ($apiCalls === null) {
        // it was not possible to parse the json, so let's set it to an empty array
        $apiCalls = [];
      }

      $innerCalls = [];
      foreach($apiCalls as $apiCall) {
        $innerCalls[] = "$apiCall[0].$apiCall[1]";
      }
    }


    if ($entity && $action) {
      newrelic_name_transaction("$entity.$action");
      if (!empty($innerCalls)) {
        newrelic_add_custom_parameter('inner_calls', implode(', ', $innerCalls));
      }
    }
  } else {
    newrelic_name_transaction($uri);
  }
}


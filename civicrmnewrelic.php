<?php

/**
 * @file
 * Extension file.
 */

/**
 * Builds the New Relic transaction name and custom parameters for a request.
 *
 * Pure function (no superglobals, no New Relic calls) so it can be unit
 * tested in isolation. Given the request superglobals it returns:
 *   - name:   the transaction name to set, or NULL to leave NR's default.
 *   - params: custom parameters to attach (scalars only).
 *
 * @param array $server
 *   The $_SERVER superglobal (or equivalent).
 * @param array $request
 *   The $_REQUEST superglobal (or equivalent).
 * @param array $post
 *   The $_POST superglobal (or equivalent).
 *
 * @return array
 *   ['name' => string|null, 'params' => array<string,scalar>].
 */
function _civicrmnewrelic_resolve(array $server, array $request, array $post): array {
  $result = ['name' => NULL, 'params' => []];

  $uri = parse_url($server['REQUEST_URI'] ?? '', PHP_URL_PATH);
  if (empty($uri)) {
    // No usable path (e.g. missing/malformed REQUEST_URI); nothing to name.
    return $result;
  }

  /*
   * Attach CiviCRM record context (contact/group IDs only, never names) when
   * present, so a slow/errored transaction can be tied to a specific record
   * in New Relic (e.g. "which contact is this slow contact/view for?"). Scoped
   * to /civicrm/ routes to avoid collisions with non-CiviCRM params (e.g.
   * Drupal's comment "cid"). Values are cast to int and only accepted when
   * numeric; newrelic_add_custom_parameter needs scalars.
   */
  if (strpos($uri, '/civicrm/') === 0) {
    foreach (['cid', 'gid'] as $idKey) {
      $value = $request[$idKey] ?? NULL;
      if (is_scalar($value) && is_numeric($value)) {
        $result['params']["civicrm.$idKey"] = (int) $value;
      }
    }
  }

  if ($uri === '/civicrm/ajax/rest') {
    $entity = $request['entity'] ?? NULL;
    $action = $request['action'] ?? NULL;

    /*
     * entity/action are always string|null from CiviCRM's REST dispatcher.
     * Guard against non-string values (e.g. array-style "entity[]=x") and
     * empty strings. The literal string "0" is intentionally allowed (the
     * previous truthiness check rejected it); harmless, as no real
     * entity/action is "0".
     */
    $entityValid = is_string($entity) && $entity !== '';
    $actionValid = is_string($action) && $action !== '';
    if (!$entityValid || !$actionValid) {
      return $result;
    }

    $result['name'] = "$entity.$action";

    /*
     * For Api3.call (multiple API calls in one request) record the inner
     * calls so it's possible to know exactly which API calls were sent.
     */
    if ($entity === 'api3' && $action === 'call' && !empty($post['json'])) {
      $apiCalls = json_decode($post['json'], FALSE, 512);
      $innerCalls = [];
      if (is_array($apiCalls)) {
        foreach ($apiCalls as $apiCall) {
          if (is_array($apiCall) && isset($apiCall[0], $apiCall[1])) {
            $innerCalls[] = "{$apiCall[0]}.{$apiCall[1]}";
          }
        }
      }
      if (!empty($innerCalls)) {
        $result['params']['inner_calls'] = implode(', ', $innerCalls);
      }
    }
  }
  else {
    $result['name'] = $uri;
  }

  return $result;
}

/**
 * Generates a better, more descriptive, transaction name for New Relic.
 *
 * For APIs, the transaction name will look like `Entity.Action`.
 *
 * For calls to the Api3.call API, an inner_calls param will be added to the
 * transaction containing a list of the inner API calls.
 *
 * For any other requests, the transaction name will be the Request URI (e.g.
 * /civicrm/contact/view), instead of the generic civicrm_invoke New Relic
 * uses by default. CiviCRM record IDs (cid/gid) are attached as custom
 * parameters when present.
 *
 * hook_civicrm_config is the very first hook triggered in the CiviCRM
 * bootstrap and runs on every request, so it's the right place for this.
 *
 * @param \CRM_Core_Config $config
 *   The CiviCRM config object.
 */
function civicrmnewrelic_civicrm_config(CRM_Core_Config $config): void {
  /*
   * Under the CLI SAPI $_SERVER lacks the data we need and there is no web
   * transaction to name, so there is nothing to do.
   */
  if (PHP_SAPI === 'cli' || !extension_loaded('newrelic')) {
    return;
  }

  $resolved = _civicrmnewrelic_resolve($_SERVER, $_REQUEST, $_POST);

  if ($resolved['name'] !== NULL) {
    newrelic_name_transaction($resolved['name']);
  }
  foreach ($resolved['params'] as $key => $value) {
    newrelic_add_custom_parameter($key, $value);
  }
}

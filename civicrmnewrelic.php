<?php

/**
 * @file
 * Extension file.
 */

/**
 * Validates a request value as a positive integer ID.
 *
 * @param mixed $value
 *   The raw request value (e.g. from $_REQUEST).
 *
 * @return int|null
 *   The integer when $value is a positive integer, otherwise NULL.
 */
function _civicrmnewrelic_positive_int($value): ?int {
  if (!is_scalar($value) || is_bool($value)) {
    return NULL;
  }
  $id = filter_var($value, FILTER_VALIDATE_INT);
  return ($id !== FALSE && $id > 0) ? $id : NULL;
}

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
 *   The $_REQUEST superglobal (or equivalent). Also carries the api3.call
 *   `json` payload (GET or POST), so $_POST need not be passed separately.
 *
 * @return array
 *   ['name' => string|null, 'params' => array<string,scalar>].
 */
function _civicrmnewrelic_resolve(array $server, array $request): array {
  $result = ['name' => NULL, 'params' => []];

  $uri = parse_url($server['REQUEST_URI'] ?? '', PHP_URL_PATH);
  if (empty($uri)) {
    // No usable path (e.g. missing/malformed REQUEST_URI); nothing to name.
    return $result;
  }

  /*
   * Tag every transaction with the HTTP method so HEAD link-scanner traffic
   * (e.g. mail clients pre-fetching tracking URLs) can be segmented from real
   * GET clicks in New Relic.
   */
  $result['params']['http_method'] = $server['REQUEST_METHOD'] ?? 'UNKNOWN';

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
      $id = _civicrmnewrelic_positive_int($request[$idKey] ?? NULL);
      if ($id !== NULL) {
        $result['params']["civicrm.$idKey"] = $id;
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
    if ($entity === 'api3' && $action === 'call'
      && !empty($request['json']) && is_string($request['json'])) {
      $apiCalls = json_decode($request['json'], TRUE, 512);
      $innerCalls = [];
      if (is_array($apiCalls)) {
        foreach ($apiCalls as $apiCall) {
          if (is_array($apiCall) && isset($apiCall[0], $apiCall[1])
            && is_string($apiCall[0]) && is_string($apiCall[1])) {
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

    /*
     * Attach mailing click-through context on /civicrm/mailing/url so slow or
     * errored tracking hits can be correlated back to a specific mailing URL
     * and queue, and HEAD-based link scanners can be told apart from real
     * clicks. CiviCRM reads these as ?u={url_id}&qid={queue_id}
     * (see CRM_Mailing_Page_Url); IDs are validated as positive ints.
     */
    if ($uri === '/civicrm/mailing/url') {
      $urlId = _civicrmnewrelic_positive_int($request['u'] ?? NULL);
      if ($urlId !== NULL) {
        $result['params']['mailing_url_id'] = $urlId;
      }
      $queueId = _civicrmnewrelic_positive_int($request['qid'] ?? NULL);
      if ($queueId !== NULL) {
        $result['params']['mailing_queue_id'] = $queueId;
      }
      $isScanner = ($server['REQUEST_METHOD'] ?? '') === 'HEAD';
      $result['params']['mailing_is_scanner'] = $isScanner;
    }
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

  $resolved = _civicrmnewrelic_resolve($_SERVER, $_REQUEST);

  if ($resolved['name'] !== NULL) {
    newrelic_name_transaction($resolved['name']);
  }
  foreach ($resolved['params'] as $key => $value) {
    newrelic_add_custom_parameter($key, $value);
  }
}

/**
 * Reports unhandled CiviCRM exceptions to New Relic.
 *
 * CiviCRM fires this hook from CRM_Core_Error::handleUnhandledException() for
 * any uncaught exception (for example the CRM_Core_Exception thrown by
 * CRM_Mailing_Page_Url when a tracking URL can't be resolved). Without it New
 * Relic only records a completed transaction with no error attached, so
 * 500-level failures never show up in NR's error traces.
 *
 * The hook is dispatched as the Symfony event hook_civicrm_unhandled_exception,
 * which CiviEventDispatcher::delegateToUF() maps to the legacy hook
 * civicrm_unhandled_exception — hence the snake_case function name.
 *
 * @param \Throwable $exception
 *   The unhandled exception.
 */
function civicrmnewrelic_civicrm_unhandled_exception(\Throwable $exception): void {
  if (!extension_loaded('newrelic')) {
    return;
  }

  newrelic_notice_error($exception);
}

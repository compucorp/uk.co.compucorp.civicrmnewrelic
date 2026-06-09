<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../civicrmnewrelic.php';

/**
 * Unit tests for _civicrmnewrelic_resolve() (pure, no CiviCRM/New Relic).
 */
class ResolveTest extends TestCase {

  private function resolve(array $server, array $request = [], array $post = []): array {
    return _civicrmnewrelic_resolve($server, $request, $post);
  }

  public function testCleanUrlPageNamedByPathWithCid(): void {
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/contact/view?reset=1&cid=5'], ['cid' => '5']);
    $this->assertSame('/civicrm/contact/view', $r['name']);
    $this->assertSame(5, $r['params']['civicrm.cid']);
  }

  public function testGroupGidAttribute(): void {
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/group?reset=1&gid=12'], ['gid' => '12']);
    $this->assertSame(12, $r['params']['civicrm.gid']);
  }

  public function testNonNumericCidIgnored(): void {
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/x'], ['cid' => 'abc']);
    $this->assertArrayNotHasKey('civicrm.cid', $r['params']);
  }

  public function testArrayCidIgnored(): void {
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/x'], ['cid' => ['1', '2']]);
    $this->assertArrayNotHasKey('civicrm.cid', $r['params']);
  }

  public function testApi3SingleNamedEntityAction(): void {
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/ajax/rest'], ['entity' => 'Contact', 'action' => 'get']);
    $this->assertSame('Contact.get', $r['name']);
    $this->assertArrayNotHasKey('inner_calls', $r['params']);
  }

  public function testApi3CallInnerCalls(): void {
    $json = json_encode([['Contact', 'get', []], ['Activity', 'getcount', []]]);
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/ajax/rest'], ['entity' => 'api3', 'action' => 'call'], ['json' => $json]);
    $this->assertSame('api3.call', $r['name']);
    $this->assertSame('Contact.get, Activity.getcount', $r['params']['inner_calls']);
  }

  public function testApi3CallMalformedJsonObjectNoFatalNoInner(): void {
    // Valid JSON but objects, not arrays — must not fatal and must skip.
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/ajax/rest'], ['entity' => 'api3', 'action' => 'call'], ['json' => '[{"0":"x","1":"y"}]']);
    $this->assertSame('api3.call', $r['name']);
    $this->assertArrayNotHasKey('inner_calls', $r['params']);
  }

  public function testArrayEntityRejected(): void {
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/ajax/rest'], ['entity' => ['Contact'], 'action' => 'get']);
    $this->assertNull($r['name']);
  }

  public function testEmptyEntityRejected(): void {
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/ajax/rest'], ['entity' => '', 'action' => 'get']);
    $this->assertNull($r['name']);
  }

  public function testEmptyUriReturnsNothing(): void {
    $r = $this->resolve(['REQUEST_URI' => ''], ['cid' => '5']);
    $this->assertNull($r['name']);
    $this->assertSame([], $r['params']);
  }

  public function testMissingRequestUriDoesNotError(): void {
    $r = $this->resolve([], []);
    $this->assertNull($r['name']);
  }

  public function testPlainPageNamedByPath(): void {
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/dashboard?reset=1']);
    $this->assertSame('/civicrm/dashboard', $r['name']);
  }

  public function testNonCiviRouteDoesNotAttachCid(): void {
    // Drupal comment routes also use ?cid= — must NOT become civicrm.cid.
    $r = $this->resolve(['REQUEST_URI' => '/node/5?cid=10'], ['cid' => '10']);
    $this->assertArrayNotHasKey('civicrm.cid', $r['params']);
  }

  public function testFloatStringIdRejected(): void {
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/contact/view'], ['cid' => '12.3']);
    $this->assertArrayNotHasKey('civicrm.cid', $r['params']);
  }

  public function testScientificStringIdRejected(): void {
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/contact/view'], ['cid' => '1e3']);
    $this->assertArrayNotHasKey('civicrm.cid', $r['params']);
  }

  public function testInnerCallObjectElementSkippedNoFatal(): void {
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/ajax/rest'], ['entity' => 'api3', 'action' => 'call'], ['json' => '[[{}, "get"]]']);
    $this->assertSame('api3.call', $r['name']);
    $this->assertArrayNotHasKey('inner_calls', $r['params']);
  }

  public function testZeroAndNegativeIdRejected(): void {
    $this->assertArrayNotHasKey('civicrm.cid', $this->resolve(['REQUEST_URI' => '/civicrm/x'], ['cid' => '0'])['params']);
    $this->assertArrayNotHasKey('civicrm.cid', $this->resolve(['REQUEST_URI' => '/civicrm/x'], ['cid' => '-3'])['params']);
  }

  public function testJsonAsArrayDoesNotFatal(): void {
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/ajax/rest'], ['entity' => 'api3', 'action' => 'call'], ['json' => ['x']]);
    $this->assertSame('api3.call', $r['name']);
    $this->assertArrayNotHasKey('inner_calls', $r['params']);
  }

  public function testObjectFormMulticallRecordsInnerCalls(): void {
    $r = $this->resolve(['REQUEST_URI' => '/civicrm/ajax/rest'], ['entity' => 'api3', 'action' => 'call'], ['json' => '{"c1":["Contact","get"]}']);
    $this->assertSame('Contact.get', $r['params']['inner_calls']);
  }

}

<?php
namespace App\utils;
use App\utils\BaseFacebook;
use Illuminate\Support\Facades\Log;

/**
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may
 * not use this file except in compliance with the License. You may obtain
 * a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */

//require_once "base_facebook.php";

/**
 * Extends the BaseFacebook class with the intent of using
 * PHP sessions to store user ids and access tokens.
 */
class Facebook extends BaseFacebook
{
  const API_URL = 'https://graph.facebook.com/v1.0/';
  /**
   * Cookie prefix
   */
  const FBSS_COOKIE_NAME = 'fbss';

  /**
   * We can set this to a high number because the main session
   * expiration will trump this.
   */
  const FBSS_COOKIE_EXPIRE = 31556926; // 1 year

  /**
   * Stores the shared session ID if one is set.
   *
   * @var string
   */
  protected $sharedSessionID;

  /**
   * Identical to the parent constructor, except that
   * we start a PHP session to store the user ID and
   * access token if during the course of execution
   * we discover them.
   *
   * @param array $config the application configuration. Additionally
   * accepts "sharedSession" as a boolean to turn on a secondary
   * cookie for environments with a shared session (that is, your app
   * shares the domain with other apps).
   *
   * @see BaseFacebook::__construct
   */
  public function __construct($config) {
    if ((function_exists('session_status') 
      && session_status() !== PHP_SESSION_ACTIVE) || !session_id()) {
      session_start();
    }
    parent::__construct($config);
    if (!empty($config['sharedSession'])) {
      $this->initSharedSession();

      // re-load the persisted state, since parent
      // attempted to read out of non-shared cookie
      $state = $this->getPersistentData('state');
      if (!empty($state)) {
        $this->state = $state;
      } else {
        $this->state = null;
      }

    }
  }

  /**
   * Supported keys for persistent data
   *
   * @var array
   */
  protected static $kSupportedKeys =
    array('state', 'code', 'access_token', 'user_id');

  /**
   * Initiates Shared Session
   */
  protected function initSharedSession() {
    $cookie_name = $this->getSharedSessionCookieName();
    if (isset($_COOKIE[$cookie_name])) {
      $data = $this->parseSignedRequest($_COOKIE[$cookie_name]);
      if ($data && !empty($data['domain']) &&
          self::isAllowedDomain($this->getHttpHost(), $data['domain'])) {
        // good case
        $this->sharedSessionID = $data['id'];
        return;
      }
      // ignoring potentially unreachable data
    }
    // evil/corrupt/missing case
    $base_domain = $this->getBaseDomain();
    $this->sharedSessionID = md5(uniqid(mt_rand(), true));
    $cookie_value = $this->makeSignedRequest(
      array(
        'domain' => $base_domain,
        'id' => $this->sharedSessionID,
      )
    );
    $_COOKIE[$cookie_name] = $cookie_value;
    if (!headers_sent()) {
      $expire = time() + self::FBSS_COOKIE_EXPIRE;
      setcookie($cookie_name, $cookie_value, $expire, '/', '.'.$base_domain);
    } else {
      // @codeCoverageIgnoreStart
      self::errorLog(
        'Shared session ID cookie could not be set! You must ensure you '.
        'create the Facebook instance before headers have been sent. This '.
        'will cause authentication issues after the first request.'
      );
      // @codeCoverageIgnoreEnd
    }
  }

  /**
   * Provides the implementations of the inherited abstract
   * methods. The implementation uses PHP sessions to maintain
   * a store for authorization codes, user ids, CSRF states, and
   * access tokens.
   */

  /**
   * {@inheritdoc}
   *
   * @see BaseFacebook::setPersistentData()
   */
  protected function setPersistentData($key, $value) {
    if (!in_array($key, self::$kSupportedKeys)) {
      self::errorLog('Unsupported key passed to setPersistentData.');
      return;
    }

    $session_var_name = $this->constructSessionVariableName($key);
    $_SESSION[$session_var_name] = $value;
  }

  /**
   * {@inheritdoc}
   *
   * @see BaseFacebook::getPersistentData()
   */
  protected function getPersistentData($key, $default = false) {
    if (!in_array($key, self::$kSupportedKeys)) {
      self::errorLog('Unsupported key passed to getPersistentData.');
      return $default;
    }

    $session_var_name = $this->constructSessionVariableName($key);
    return isset($_SESSION[$session_var_name]) ?
      $_SESSION[$session_var_name] : $default;
  }

  /**
   * {@inheritdoc}
   *
   * @see BaseFacebook::clearPersistentData()
   */
  protected function clearPersistentData($key) {
    if (!in_array($key, self::$kSupportedKeys)) {
      self::errorLog('Unsupported key passed to clearPersistentData.');
      return;
    }

    $session_var_name = $this->constructSessionVariableName($key);
    if (isset($_SESSION[$session_var_name])) {
      unset($_SESSION[$session_var_name]);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @see BaseFacebook::clearAllPersistentData()
   */
  protected function clearAllPersistentData() {
    foreach (self::$kSupportedKeys as $key) {
      $this->clearPersistentData($key);
    }
    if ($this->sharedSessionID) {
      $this->deleteSharedSessionCookie();
    }
  }

  /**
   * Deletes Shared session cookie
   */
  protected function deleteSharedSessionCookie() {
    $cookie_name = $this->getSharedSessionCookieName();
    unset($_COOKIE[$cookie_name]);
    $base_domain = $this->getBaseDomain();
    setcookie($cookie_name, '', 1, '/', '.'.$base_domain);
  }

  /**
   * Returns the Shared session cookie name
   *
   * @return string The Shared session cookie name
   */
  protected function getSharedSessionCookieName() {
    return self::FBSS_COOKIE_NAME . '_' . $this->getAppId();
  }

  /**
   * Constructs and returns the name of the session key.
   *
   * @see setPersistentData()
   * @param string $key The key for which the session variable name to construct.
   *
   * @return string The name of the session key.
   */
  protected function constructSessionVariableName($key) {
    $parts = array('fb', $this->getAppId(), $key);
    if ($this->sharedSessionID) {
      array_unshift($parts, $this->sharedSessionID);
    }
    return implode('_', $parts);
  }
  
  public function getPublicMedia($hashtag, $id = 'self', $limit = 100) {
      //$url = "https://graph.facebook.com/v1.0/search?q=" + $keyword + "&limit=100&access_token=" + $access_token;
      return $this->_makeCall('search', ($id === 'self'), array( 'q' => $hashtag, 'limit' => $limit));
  }
  
  protected function _makeCall($function, $auth = false, $params = null, $method = 'GET') {
      
    if (false === $auth) {
      // if the call doesn't requires authentication
      $authMethod = '?client_id=' . $this->getAppId();
    } else {
      // if the call needs an authenticated user
      if (true == isset($this->accessToken)) {
        $authMethod = '?access_token=' . $this->getAccessToken();
      } else {
        throw new \Exception("Error: _makeCall() | $function - This method requires an authenticated users access token.");
      }
    }

    if (isset($params) && is_array($params)) {
      $paramString = '&' . http_build_query($params);
    } else {
      $paramString = null;
    }

    $apiCall = self::API_URL . $function . $authMethod . (('GET' === $method) ? $paramString : null);
    
    // signed header of POST/DELETE requests
    $headerData = array('Accept: application/json');
//    if (true === $this->_signedheader && 'GET' !== $method) {
//      $headerData[] = 'X-Insta-Forwarded-For: ' . $this->_signHeader();
//    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiCall);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headerData);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_COOKIESESSION, true);

    if ('POST' === $method) {
      curl_setopt($ch, CURLOPT_POST, count($params));
      curl_setopt($ch, CURLOPT_POSTFIELDS, ltrim($paramString, '&'));
    } else if ('DELETE' === $method) {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $jsonData = curl_exec($ch);
    if (false === $jsonData) {
      throw new \Exception("Error: _makeCall() - cURL error: " . curl_error($ch));
    }
    curl_close($ch);
    
    return json_decode($jsonData);
  }
  
}
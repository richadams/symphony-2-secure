<?php

	/**
	 * @package core
	 */
	if(!defined('__IN_SYMPHONY__')) die('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');

	/**
	 * The Cookie class is a wrapper to save Symphony cookies. Typically this
	 * is used to maintain if an Author is logged into Symphony, or by extensions
	 * to determine similar things. The Cookie class is tightly integrated with
	 * PHP's `$_SESSION` global and it's related functions.
	 */
	require_once(CORE . '/class.session.php');

	Class Cookie{

		/**
		 * Used to prevent Symphony cookies from completely polluting the
		 * `$_SESSION` array. This will act as a key and all
		 * cookies will live under that key. By default, the index is read from
		 * the Symphony configuration, and unless changed, is `sym-`
		 *
		 * @var string
		 */
		private $_index;

		/**
		 * This variable determines if the Cookie was set by the Symphony Session
		 * class, or if it was set directly. By default, this is false as the Symphony cookie
		 * created directly in the Symphony constructor, otherwise it will be an instance
		 * of the Session class
		 *
		 * @see core.Symphony#__construct()
		 * @var Session|boolean
		 */
		private $_session = false;

		/**
		 * How long this cookie is valid for. By default, this is 0 if used by an extension,
		 * but it is usually set for 2 weeks in the Symphony context.
		 *
		 * @var integer
		 */
		private $_timeout = 0;

		/**
		 * The path that this cookie is valid for, by default Symphony makes this the whole
		 * domain using /
		 *
		 * @var string
		 */
		private $_path;

		/**
		 * The domain that this cookie is valid for. This is null by default which implies
		 * the entire domain and all subdomains created will have access to this cookie.
		 *
		 * @var string
		 */
		private $_domain;

		/**
		 * Determines whether this cookie can be read by Javascript or not, by default
		 * this is set to false, meaning cookies written by Symphony can be read by
		 * Javascript
		 *
		 * @var boolean
		 */
		private $_httpOnly = false;

		/**
		 * Constructor for the Cookie class intialises all class variables with the
		 * given parameters. Most of the parameters map to PHP's setcookie
		 * function. It creates a new Session object via the `$this->__init()`
		 *
		 * @see __init()
		 * @link http://php.net/manual/en/function.setcookie.php
		 * @param string $index
		 *  The prefix to used to namespace all Symphony cookies
		 * @param integer $timeout
		 *  The Time to Live for a cookie, by default this is zero, meaning the
		 *  cookie never expires
		 * @param string $path
		 *  The path the cookie is valid for on the domain
		 * @param string $domain
		 *  The domain this cookie is valid for
		 * @param boolean $httpOnly
		 *  Whether this cookie can be read by Javascript. By default the cookie
		 *  can be read using Javascript and PHP
		 */
		public function __construct($index, $timeout = 0, $path = '/', $domain = NULL, $httpOnly = false) {
			$this->_index = $index;
			$this->_timeout = $timeout;
			$this->_path = $path;
			$this->_domain = $domain;
			$this->_httpOnly = $httpOnly;
			$this->_session = $this->__init();
		}

		/**
		 * Initialises a new Session instance using this cookie's params
		 *
		 * @return Session
		 */
		private function __init() {
			$this->_session = Session::start($this->_timeout, $this->_path, $this->_domain, $this->_httpOnly);

			// If wasn't created, exit early.
			if (!$this->_session) return false;

			// If session is empty, init to empty array.
			if (!isset($_SESSION[$this->_index])) $_SESSION[$this->_index] = array();

			// Init and validate that the session isn't suspicious
			$this->initialize();
			if (!$this->validate())
			{
				$this->expire();
				return false;
			}

			// Update user's last activity time
			$_SESSION[$this->_index]["_user_last_active"] = time();

			// Class FrontendPage uses $_COOKIE directly (inside it's __buildPage() function), so try to emulate it.
			$_COOKIE[$this->_index] = &$_SESSION[$this->_index];

			return $this->_session;
		}

		// This sets up some flags we'll use to determine if the session becomes suspicious. This
		// only needs to be setup when the session is initially created.
		private function initialize()
		{
			// Need some way to determine when session ACTUALLY got created, as opposed to just started.
			if (!isset($_SESSION[$this->_index]["_sym"]))
			{
				// Session was just created, so store it's start time and last activity time.
				$_SESSION[$this->_index]["_start_time"]       = time();
				$_SESSION[$this->_index]["_user_last_active"] = time();

				// So I know the server generated the session
				$_SESSION[$this->_index]["_sym"] = true;

				// Store a browser signature into the session so we can check on subsequent requests.
				// Use hash since we don't want to deal with privacy concerns.
				$_SESSION[$this->_index]["_user_signature"] = sha1($_SERVER['HTTP_USER_AGENT']
					.$_SERVER['HTTP_ACCEPT_ENCODING']
					.$_SERVER['HTTP_ACCEPT_LANGUAGE']
					.$_SERVER['HTTP_ACCEPT_CHARSET']);

				// Only use the first two blocks of the IP (loose IP check). Use a
				// netmask of 255.255.0.0 to get the first two blocks only.
				$_SESSION[$this->_index]["_user_loose_ip"] = long2ip(ip2long($_SERVER['REMOTE_ADDR'])
					& ip2long("255.255.0.0"));
			}
		}

		// This will validate a session to make sure it's not suspicious. We immediately destroy
		// any suspicious sessions.
		private function validate()
		{
			// Validate Session Origin
			// Check that this application created the session.
			if (!isset($_SESSION[$this->_index]["_sym"]))
			{
				return false;
			}

			// Suspicious Sessions
			if ($_SESSION[$this->_index]["_user_loose_ip"]     != long2ip(ip2long($_SERVER['REMOTE_ADDR'])
					& ip2long("255.255.0.0"))
				|| $_SESSION[$this->_index]["_user_signature"] != sha1($_SERVER['HTTP_USER_AGENT']
					.$_SERVER['HTTP_ACCEPT_ENCODING']
					.$_SERVER['HTTP_ACCEPT_LANGUAGE']
					.$_SERVER['HTTP_ACCEPT_CHARSET']))
			{
				return false;
			}

			// Validate Duration
			// Time validation check. Expire sessions after a lifetime or inactivity time.
			// Whichever comes first. Times can be set in config
			if ($_SESSION[$this->_index]["_start_time"] < (strtotime("-" . Symphony::Configuration()->get('lifetime', 'session')))
			|| $_SESSION[$this->_index]["_user_last_active"] < (strtotime("-" . Symphony::Configuration()->get('inactive_time', 'session'))))
			{
				return false;
			}

			return true;
		}

		/**
		 * A basic setter, which will set a value to a given property in the
		 * `$_SESSION` array, stored in the key of `$this->_index`
		 *
		 * @param string $name
		 *  The name of the property
		 * @param string $value
		 *  The value of the property
		 */
		public function set($name, $value) {
			$_SESSION[$this->_index][$name] = $value;
		}

		/**
		 * Accessor function for properties in the `$_SESSION` array
		 *
		 * @param string $name
		 *  The name of the property to retrieve
		 * @return string|null
		 *  The value of the property, or null if it does not exist
		 */
		public function get($name) {
			if (is_array($_SESSION[$this->_index]) && array_key_exists($name, $_SESSION[$this->_index])) {
				return $_SESSION[$this->_index][$name];
			}
			return null;
		}

		/**
		 * Expires the current `$_SESSION` by unsetting the Symphony
		 * namespace (`$this->_index`). If the `$_SESSION`
		 * is empty, the function will destroy the entire `$_SESSION`
		 *
		 * @link http://au2.php.net/manual/en/function.session-destroy.php
		 */
		public function expire() {
			if(!isset($_SESSION[$this->_index]) || !is_array($_SESSION[$this->_index]) || empty($_SESSION[$this->_index])) return;

			// Clear the session on the server side.
			unset($_SESSION[$this->_index]);
			session_destroy();
		}

	}

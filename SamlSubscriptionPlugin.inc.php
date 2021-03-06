<?php

/**
 * @file SamlSubscriptionPlugin.inc.php
 *
 * Copyright (c) 2015-2020 Simon Fraser University
 * Copyright (c) 2015-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Plugin to defer subscription checks to an external (SAML-based, e.g.
 * Shibboleth) system.
 */

import('classes.plugins.GenericPlugin');

class SamlSubscriptionPlugin extends GenericPlugin {
	/**
	 * @copydoc GenericPlugin::register
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return true;
		if ($success && $this->getEnabled()) {
			$this->addLocaleData();
			HookRegistry::register('LoadHandler',array(&$this, 'loadHandlerCallback'));
			HookRegistry::register('IssueAction::subscribedUser', array(&$this, 'subscribedUserCallback'));
			return true;
		}
		return $success;
	}

	/**
	 * Callback when a handler is loaded. Used to check for the presence
	 * of an incoming authentication, which needs to be verified.
	 * @param $hookName string Hook name
	 * @param $args array Hook arguments
	 * @return boolean Hook return status
	 */
	function loadHandlerCallback($hookName, $args) {
		$journal = Request::getJournal();
		$incomingParameterName = $this->getSetting($journal->getId(), 'incomingParameterName');
		// Using $_GET rather than Request because this may be case
		// sensitive (e.g. differentiating myid from myId)
		if ($incomingParameterName != '' && isset($_GET[$incomingParameterName])) {
			$incomingKey = $_GET[$incomingParameterName];

			// This is an incoming authorization. Contact the remote service.
			$verificationUrl = $this->getSetting($journal->getId(), 'verificationUrl');
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $verificationUrl . urlencode($incomingKey));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1) ;
			curl_setopt($ch, CURLOPT_HEADER, 0);
			$result = curl_exec($ch);
			curl_close($ch);

			// Verify the result.
			$resultRegexp = $this->getSetting($journal->getId(), 'resultRegexp');
			if (preg_match($resultRegexp, $result)) {
				// Successfully validated.
				$_SESSION['subscriptionSSOTimestamp'] = time();
			} else {
				// Failed to validate.
				unset($_SESSION['subscriptionSSOTimestamp']);
				Request::redirectURL($this->getSetting($journal->getId(), 'redirectUrl'));
			}
		}
		return false;
	}

	/**
	 * Callback when a handler is loaded. Used to check for the presence
	 * of an incoming authentication, which needs to be verified.
	 * @param $hookName string Hook name
	 * @param $args array Hook arguments
	 * @return boolean Hook return status
	 */
	function subscribedUserCallback($hookName, $args) {
		// Exclude the index and issue pages.
		if (in_array(Request::getRequestedPage(), array('', 'index', 'issue', 'search'))) return false;

		// Permit an abstract view.
		if (Request::getRequestedPage() == 'article' && Request::getRequestedOp() == 'view' && count(Request::getRequestedArgs())==1) return false;

		require_once('vendor/autoload.php');
		$as = new SimpleSAML_Auth_Simple('example-sql');
		$as->requireAuth();
		$attributes = $as->getAttributes();
		print_r($attributes);
		die('HOW TO DO NOW');

		$journal =& $args[0];
		$result =& $args[1]; // Reference required
		$result = isset($_SESSION['subscriptionSSOTimestamp']) && $_SESSION['subscriptionSSOTimestamp'] + ($this->getSetting($journal->getId(), 'hoursValid') * 3600) > time();
		if (!$result) {
			// If we're not subscribed, redirect.
			Request::redirectURL($this->getSetting($journal->getId(), 'redirectUrl') . '?redirectUrl=' . urlencode(Request::getRequestUrl()));
		}
	}

	/**
	 * Extend the {url ...} smarty to support this plugin.
	 * @param $params array
	 * @param $smarty Smarty
	 */
	function smartyPluginUrl($params, &$smarty) {
		$path = array($this->getCategory(), $this->getName());
		if (is_array($params['path'])) {
			$params['path'] = array_merge($path, $params['path']);
		} elseif (!empty($params['path'])) {
			$params['path'] = array_merge($path, array($params['path']));
		} else {
			$params['path'] = $path;
		}

		if (!empty($params['id'])) {
			$params['path'] = array_merge($params['path'], array($params['id']));
			unset($params['id']);
		}
		return $smarty->smartyUrl($params, $smarty);
	}

	/**
	 * Set the page's breadcrumbs, given the plugin's tree of items
	 * to append.
	 * @param $subclass boolean
	 */
	function setBreadcrumbs($isSubclass = false) {
		$templateMgr =& TemplateManager::getManager();
		$pageCrumbs = array(
			array(
				Request::url(null, 'user'),
				'navigation.user'
			),
			array(
				Request::url(null, 'manager'),
				'user.role.manager'
			)
		);
		if ($isSubclass) $pageCrumbs[] = array(
			Request::url(null, 'manager', 'plugins'),
			'manager.plugins'
		);

		$templateMgr->assign('pageHierarchy', $pageCrumbs);
	}

	/**
	 * Display verbs for the management interface.
	 * @return array
	 */
	function getManagementVerbs() {
		$verbs = array();
		if ($this->getEnabled()) {
			$verbs[] = array('settings', __('plugins.generic.samlSubscription.settings'));
		}
		return parent::getManagementVerbs($verbs);
	}

	/**
	 * Execute a management verb on this plugin
	 * @param $verb string
	 * @param $args array
	 * @param $message string Result status message
	 * @param $messageParams array Parameters for the message key
	 * @return boolean
	 */
	function manage($verb, $args, &$message, &$messageParams) {
		if (!parent::manage($verb, $args, $message, $messageParams)) return false;

		switch ($verb) {
			case 'settings':
				$templateMgr =& TemplateManager::getManager();
				$templateMgr->register_function('plugin_url', array(&$this, 'smartyPluginUrl'));
				$journal =& Request::getJournal();

				$this->import('SubscriptionSSOSettingsForm');
				$form = new SubscriptionSSOSettingsForm($this, $journal->getId());
				if (Request::getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						Request::redirect(null, 'manager', 'plugin');
						return false;
					} else {
						$this->setBreadcrumbs(true);
						$form->display();
					}
				} else {
					$this->setBreadcrumbs(true);
					$form->initData();
					$form->display();
				}
				return true;
			default:
				// Unknown management verb
				assert(false);
				return false;
		}
	}

	/**
	 * @copydoc Plugin::getDisplayName
	 */
	function getDisplayName() {
		return __('plugins.generic.samlSubscription.name');
	}

	/**
	 * @copydoc Plugin::getDescription
	 */
	function getDescription() {
		return __('plugins.generic.samlSubscription.description');
	}
}


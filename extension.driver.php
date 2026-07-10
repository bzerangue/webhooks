<?php
	require_once TOOLKIT.'/class.sectionmanager.php';
	require_once TOOLKIT.'/class.gateway.php';

	/**
	 * @package extensions/webhooks
	 */
	/**
	 * A simple Symphony extension that allows developers to assocate WebHooks with content publishing
	 * events. These hooks are assigned to a content section and then to a specific event delegate for
	 * the content associated within this section: PUT, POST, DELETE. If a matching event occurs within
	 * an assigned content section, this extension will send a push notification to the specified URL
	 * with the event type and all information associated with the content entry.
	 */
	class Extension_WebHooks extends Extension {
		/**
		 * Instantiates this class and assigns values to properties.
		 *
		 * @access public
		 * @param array $args
		 *  Any arguments passed via URL query string with be relayed through here.
		 * @return NULL
		 */
		public function __construct(array $args){
			parent::__construct($args);
		}

		/**
		 * Method that provides metadata relevant to this extension.
		 *
		 * @access public
		 * @param none
		 * @return array
		 *	Extension metadata.
		 */


		/**
		 * Installs this extension by adding the appropriate tables to the database.
		 *
		 * @access public
		 * @param none
		 * @return boolean
		 *	TRUE if successful, FALSE if failed.
		 */
		public function install() {
			return Symphony::Database()->import("
				DROP TABLE IF EXISTS `tbl_extensions_webhooks`;
				CREATE TABLE `tbl_extensions_webhooks` (
					`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
					`label` varchar(64) DEFAULT NULL,
					`section_id` int(11) DEFAULT NULL,
					`verb` enum('POST','PUT','DELETE') DEFAULT NULL,
					`callback` varchar(255) DEFAULT NULL,
					`is_active` tinyint(1) DEFAULT 1,
				PRIMARY KEY (`id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
			");
		}

		/**
		 * Uninstalls this extension by removing the appropriate tables from the database.
		 *
		 * @access public
		 * @param none
		 * @return boolean
		 *	TRUE if successful, FALSE if failed.
		 */
		public function uninstall() {
			return Symphony::Database()->import("
				DROP TABLE IF EXISTS `tbl_extensions_webhooks`
			");
		}

		/**
		 * Adds a navigation entry at the specified position to the administrative control panel.
		 *
		 * @access public
		 * @param none
		 * @return array
		 *	Menu entries and their associated locations, positions within existing menus.
		 */
		public function fetchNavigation() {
			return array(
				array(
					'location' => __('System'),
					'name' => __('WebHooks'),
					'link' => '/hooks/'
				)
			);
		}

		/**
		 * Utility method for grabbing the base URL for the WebHook management area.
		 *
		 * @access public
		 * @param none
		 * @return string
		 *	Base URL for WebHook management.
		 */
		public static function baseURL(){
			return SYMPHONY_URL . '/extension/webhooks/hooks';
		}

		/**
		 * The delegates, and associated callbacks, used by this extension. This extension
		 * essentially watches for edited, removed and created content from ALL sections.
		 *
		 * @access public
		 * @param none
		 * @return array
		 *	Array containing a list of delegates and their associated callbacks for this extensiono.
		 */
		public function getSubscribedDelegates() {
			return array(
				array(
					'page' => '/publish/new/',
					'delegate' => 'EntryPostCreate',
					'callback' => '__pushNotification'
				),
				array(
					'page' => '/publish/edit/',
					'delegate' => 'EntryPostEdit',
					'callback' => '__pushNotification'
				),
				array(
					'page' => '/publish/',
					'delegate' => 'EntryPostDelete',
					'callback' => '__pushNotification'
				)
			);
		}

		/**
		 * Responsible for sending push notifications for all active WebHooks.
		 *
		 * @access public
		 * @param array $context
		 *  The current Symphony context.
		 * @return NULL
		 * @uses Gateway
		 * @uses Entry
		 * @uses Log
		 * @uses Section
		 */
		public function __pushNotification(array $context)
		{
			switch ($context['delegate']) {
				case 'EntryPostEdit':
					$verb = 'PUT';
					break;

				case 'EntryPostDelete':
					$verb = 'DELETE';
					break;

				case 'EntryPostCreate':
				default:
					$verb = 'POST';
					break;
			}

			$webHooks = Symphony::Database()->fetch("
				SELECT
					`id`,
					`label`,
					`section_id`,
					`verb`,
					`callback`,
					`is_active`
				FROM `tbl_extensions_webhooks`
				WHERE `is_active` = TRUE
			");

			if (empty($webHooks)) {
				return;
			}

			$pageCallback = Administration::instance()->getPageCallback();
			$sectionHandle = $pageCallback['context']['section_handle'] ?? null;

			if (!$sectionHandle) {
				Symphony::Log()->pushToLog(
					'WebHooks: Unable to determine the current section handle.',
					E_ERROR,
					true
				);

				return;
			}

			$section = $this->__getSectionByHandle($sectionHandle);

			if (!$section) {
				return;
			}

			$Gateway = new Gateway();
			$Gateway->init();

			$Gateway->setopt($verb, true);

			$Gateway->setopt('HTTPHEADER', array(
				'Content-Type: application/json',
				'Accept: application/json'
			));

			$Gateway->setopt('TIMEOUT', __NOTIFICATION_TIMEOUT);

			$Log = new Log(__NOTIFICATION_LOG);

			foreach ($webHooks as $webHook) {
				if (
					(int) $section['id'] !== (int) $webHook['section_id']
					|| $webHook['verb'] !== $verb
				) {
					continue;
				}

				if ($verb === 'DELETE') {
					$payload = array(
						'verb' => 'DELETE',
						'callback' => $webHook['callback'],
						'section' => array(
							'id' => (int) $section['id'],
							'handle' => $pageCallback['context']['section_handle']
						),
						'entry_ids' => array_map(
							'intval',
							$context['entry_id'] ?? array()
						)
					);
				} else {
					$payload = $this->__compilePayload(
						$context['section'],
						$context['entry'],
						$webHook
					);
				}

				$json = json_encode(
					$payload,
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				);

				if ($json === false) {
					$Log->pushToLog(
						sprintf(
							'Webhook JSON encoding failed: %s',
							json_last_error_msg()
						),
						E_ERROR,
						true
					);

					continue;
				}

				$Gateway->setopt('URL', $webHook['callback']);
				$Gateway->setopt('POSTFIELDS', $json);

				$response = $Gateway->exec();

				if ($response === false) {
					$responseInfo = $Gateway->getInfoLast();

					$Log->pushToLog(
						sprintf(
							'Webhook request failed: section=%d, verb=%s, url=%s, curl_error=%s',
							(int) $section['id'],
							$verb,
							$webHook['callback'],
							$responseInfo['curl_error'] ?? 'unknown'
						),
						E_ERROR,
						true
					);

					continue;
				}

				$responseInfo = $Gateway->getInfoLast();
				$statusCode = (int) ($responseInfo['http_code'] ?? 0);

				if ($statusCode < 200 || $statusCode >= 300) {
					$Log->pushToLog(
						sprintf(
							'Webhook returned HTTP %d: section=%d, verb=%s, url=%s',
							$statusCode,
							(int) $section['id'],
							$verb,
							$webHook['callback']
						),
						E_ERROR,
						true
					);
				}
			}
		}

		/**
		 * Responsible for compiling the body of the notification payload.
		 *
		 * @access private
		 * @param object $Section
		 *  An instance of class `Section` representing the current entry's associated Symphony section.
		 * @param object $Entry
		 *  An instance of class `Entry` representing the current Symphony entry
		 * @param array $webHook
		 *  The current active WebHook we are preparing the notification payload for.
		 * @return array
		 *	Contains the current payload that will be passed along the notification in the POST body of the request.
		 */
		private function __compilePayload(Section $Section, Entry $Entry, array $webHook) {
			$body = array();
			$data = $Entry->getData();
			$entry = $Entry->get();
			
			foreach($Section->fetchFieldsSchema() as $field) {
				$field['value'] = $data[$field['id']];
				$body[] = $field;
			}

			$return = array(
				'verb'     => $webHook['verb'],
				'callback' => $webHook['callback'],
				'entry'  =>   json_encode($entry),
				'body'     => json_encode(array_values($body))
			);

			/**
			 * Notification body has been created.
			 *
			 * @delegate WebHookBodyCompile
			 * @param string $context
			 * '/publish/'
			 * @param Section $Section
			 * @param Entry $Entry
			 * @param array $webHook
			 * @param array $return
			 */
			Symphony::ExtensionManager()->notifyMembers('WebHookBodyCompile', '/publish/', array('section' => $Section, 'entry' => $Entry, 'webhook' => &$webHook, 'return' => &$return));

			return $return;
		}

		/**
		 * Returns a section record from the given section handle value.
		 *
		 * @access private
		 * @param string $sectionHandle
		 *  The handle of the section to search for.
		 * @return array
		 *	Associative array containing the database record of the matching section.
		 */
		private function __getSectionByHandle($sectionHandle) {
			$id = SectionManager::fetchIDFromHandle($sectionHandle);

			if (!$id) {
				return false;
			}

			return array('id' => (int) $id);
		}
	}

	/**
	 * Absolute file path to the WebHooks log file (Must be writable!):
	 */
	define_safe('__NOTIFICATION_LOG', EXTENSIONS.'/webhooks/logs/main');

	/**
	 * Request timeout, in seconds, for push notifications.
	 */
	define_safe('__NOTIFICATION_TIMEOUT', 15);
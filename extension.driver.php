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
				DROP TABLE IF EXISTS `sym_extensions_webhooks`;
				CREATE TABLE `sym_extensions_webhooks` (
					`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
					`label` varchar(64) DEFAULT NULL,
					`section_id` int(11) DEFAULT NULL,
					`verb` enum('POST','PUT','DELETE') DEFAULT NULL,
					`callback` varchar(255) DEFAULT NULL,
					`is_active` tinyint(1) DEFAULT 1,
				PRIMARY KEY (`id`)
				) ENGINE=InnoDB AUTO_INCREMENT=1 CHARSET=utf8 COLLATE=utf8_unicode_ci;
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
				DROP TABLE IF EXISTS `sym_extensions_webhooks`
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
					'delegate' => 'Delete',
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
		public function __pushNotification(array $context) {
			/**
			 * Determine the proper HTTP method verb based on the given Symphony delegate:
			 */
			
			 
			switch($context['delegate']) {
				case 'EntryPostEdit':
					$verb = 'PUT';
					break;
				case 'Delete':
					$verb = 'DELETE';
					break;
				case 'EntryPostCreate':
				default:
					$verb = 'POST';
			}

			/**
			 * POST, PUT, DELETE action has been intercepted.
			 *
			 * @delegate WebHookInit
			 * @param string $context
			 * '/publish/'
			 * @param Section $Section
			 * @param Entry $Entry
			 * @param string $verb
			 */
			Symphony::ExtensionManager()->notifyMembers('WebHookInit', '/publish/', array('section' => $Section, 'entry' => $Entry, 'verb' => $verb));

			/**
			 * Grab all active WebHooks so we can begin cycling through them;
			 */
			$webHooks = Symphony::Database()->fetch('
				SELECT
					`id`,
					`label`,
					`section_id`,
					`verb`,
					`callback`,
					`is_active` 
				FROM `sym_extensions_webhooks`
				WHERE `is_active` = TRUE
			');


			/**
			 * Obviously, we don't want to go any farther if we don't have any active
			 * WebHooks to iterate through:
			 */
			if(false == count($webHooks))
				return;

			/**
			 * Declare a few variables we'll be using throughout the rest of the process:
			 *
			 * $section: an instance of class `Section` containing the current entry's associated Symphony section `class.section.php`
			 * $entry:   an instance of class `Entry` containing the current Symphony entry `class.entry.php`
			 * $Gateway: an instance of class `Gateway`, Symphony's HTTP request utility `class.gateway.php`
			 * $Log:     an instance of class `Log`, Symphony's logging utility. We use this to track any issues we might come accross
			 */
			$pageCallback = Administration::instance()->getPageCallback();
			$section = $this->__getSectionByHandle($pageCallback['context']['section_handle']); 
			 
			if($verb != 'DELETE') {
				$entry   = $context['entry']->getData();
			}

			$Gateway = new Gateway;

			$Gateway->init();
			$Gateway->setopt('HTTPHEADER', array('Content-Type:' => 'application/json'));
			$Gateway->setopt('TIMEOUT', __NOTIFICATION_TIMEOUT);
			$Gateway->setopt('POST', TRUE);

			$Log = new Log(__NOTIFICATION_LOG);


			/**
			 * Begin iterating through our active WebHooks. Only send push notifications using WebHooks that contain
			 * a `section_id` and `verb` that corresponds with the current entry:
			 */
			foreach($webHooks as $webHook) {
			
				if($section['id'] == $webHook['section_id'] && $webHook['verb'] == $verb) {
				
					
					/**
					 * Being the notification process by setting the appropriate request options:
					 * 
					 * URL:        This is the destination of our notification
					 * POSTFIELDS: This contains the body of our notification: verb: $webHook['verb'], callback: $webHook['callback'], body: JSON-encoded string representing our entry
					 */
					$Gateway->setopt('URL',  $webHook['callback']);
					$Gateway->setopt('POSTFIELDS', ($verb == 'DELETE' ? json_encode($section) : $this->__compilePayload($context['section'], $context['entry'], $webHook)));

						//echo "<pre>";
						//var_dump($this->__compilePayload($context['section'], $context['entry'], $webHook));
						//echo "</pre>";
						//die();
					/**
					 * Obviously, we don't want to continue if something goes wrong. So, let's log this error
					 * and move on to the next active WebHook:
					 *
					 * @todo Probably best to use exception handling for this stuff; possibly create a WebHook exception class.
					 */
					if(false === $response = $Gateway->exec()) {
						$Log->pushToLog(
							sprintf(
								'Notification Failed[cURL Error]: section: %d, entry: %d, verb: %d, url: %d',
								$section['id'],
								($verb == 'DELETE' ? $context['entry_id'][0] : $context['entry']->get('id')),
								$verb,
								$webHook['callback']
							), E_ERROR, true
						);	
						continue;
					} else {
						/**
						 * We need to make sure we have a valid response from our URL. At the moment, we consider only responses with the
						 * HTTP response code of 200 OK.
						 *
						 * @todo Allow for more extensive error checking and better HTTP response support.
						 */
						$responseInfo = $Gateway->getInfoLast();

						if($responseInfo['http_code'] != 200) {
							$Log->pushToLog(
								sprintf(
									'Notification Failed[Response Code: %s]: section: %d, entry: %d, verb: %s, url: %s',
									$responseInfo['http_code'],
									$section['id'],
									($verb == 'DELETE' ? $context['entry_id'][0] : $context['entry']->get('id')),
									$verb,
									$webHook['callback']
								), E_ERROR, true
							);
							continue;
						}
					}
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
			return current(Symphony::Database()->fetch("SELECT `id` FROM `sym_sections` WHERE `handle` = '{$sectionHandle}'"));
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
<?php
	/**
	 * This extension requires the `pager` extension to be installed and active. This is used
	 * to generate standardized pagination for some of the management pages. This can be 
	 * obtained from Github:
	 *
	 * @link https://github.com/wilhelm-murdoch/pager
	 */


	/**
	 * We need a few standard Symphony libraries as well:
	 */
	require_once EXTENSIONS.'/webhooks/lib/class.pager.php';
	require_once TOOLKIT.'/class.administrationpage.php';
	require_once TOOLKIT.'/class.sectionmanager.php';


	/**
	 * @package extensions/webhooks
	 */
	/**
	 * This class is responsible for generating the management areas of this extension.
	 */
	class ContentExtensionWebhooksHooks extends AdministrationPage {
		/**
		 * Represents the total number of records for the particular data set. This is used to 
		 * calculate the number of total pages to navigate through.
		 * @var integer
		 * @access private
		 */
		private $sectionNamesArray;

		/**
		 * Instantiates the extension and populates array ContentExtensionWebhooksHooks::$sectionNamesArray
		 * with a key/value set representing a list of sections.
		 *
		 * @param Administration $parent
		 *  Instance of class AdministrationPage
		 * @access public
		 * @return NULL
		 */
		public function __construct() {
			parent::__construct();

			$this->sectionNamesArray = array();
			
			foreach(SectionManager::fetch(NULL, 'ASC', 'sortorder') as $Section) {
				$this->sectionNamesArray[$Section->get('id')] = $Section->get('name');
			}
		}

		/**
		 * Displays the WebHooks index page within the Symphony administration panel.
		 *
		 * @access public
		 * @param none
		 * @return NULL
		 */
		public function __viewIndex() {
			if(isset($this->_context[1]) && $this->_context[1] == 'removed') {
				$this->pageAlert(__('WebHook has been removed!'), Alert::SUCCESS);
			}

			$this->setPageType('table');
			$this->setTitle(__(
				'%1$s &ndash; %2$s',
				array(
					__('Symphony'),
					__('WebHooks')
				)
			));

			$this->appendSubheading(
				__('WebHooks'), 
				Widget::Anchor(
					__('Create New'), 
					Extension_Webhooks::baseUrl().'/new/', 
					__('Create a new webhook'), 
					'create button', 
					NULL, 
					array('accesskey' => 'c')
				)
			);

			$WebHookTableHead = array(
					array(__('Label'),        'col'),
					array(__('Section'),      'col'),
					array(__('Verb'),         'col'),
					array(__('Active'),       'col'),
					array(__('Callback URL'), 'col')
			);

			$totalWebHooks = array_pop(Symphony::Database()->fetch("SELECT COUNT(1) AS count FROM `sym_extensions_webhooks`"));

			$Pager = Pager::factory($totalWebHooks['count'], Symphony::Configuration()->get('pagination_maximum_rows', 'symphony'), 'pg');

			$webHooks = Symphony::Database()->fetch('
				SELECT
					`id`,
					`label`,
					`section_id`,
					`verb`,
					`callback`,
					`is_active` 
				FROM `sym_extensions_webhooks`
				ORDER BY `id` DESC '.$Pager->getLimit(true)
			);

			$webHookTableBody = array();
			if(false == $webHooks) {
				$webHookTableBody[] = Widget::TableRow(array(
						Widget::TableData(__('None found.'), 'inactive', NULL, count($WebHookTableHead))
					)
				);
			} else foreach($webHooks as $webHook) {
				$labelRow = Widget::TableData(Widget::Anchor($webHook['label'], Extension_Webhooks::baseUrl()."/edit/{$webHook['id']}"));
				$labelRow->appendChild(Widget::Input('items['.$webHook['id'].']', 'on', 'checkbox'));

				$webHookTableBody[] = Widget::TableRow(array(
						$labelRow,
						Widget::TableData($this->sectionNamesArray[$webHook['section_id']]),
						Widget::TableData($webHook['verb']),
						Widget::TableData((bool) $webHook['is_active'] ? 'Yes' : 'No'),
						Widget::TableData(Widget::Anchor($webHook['callback'], $webHook['callback']))
					), 
					'odd'
				);
			}

			$webHookTable = Widget::Table(
				Widget::TableHead($WebHookTableHead),
				NULL,
				Widget::TableBody($webHookTableBody),
				'selectable'
			);

			$this->Form->appendChild($webHookTable);

			$options = array(
				array(NULL, false, __('With Selected...')),
				array('disable', false, __('Disable')),
				array('enable', false, __('Enable')),
				array('delete', false, __('Delete'), 'confirm', null, array(
					'data-message' => __('Are you sure you want to delete the selected webhooks(s)')
				))
			);

			$tableActions = new XMLElement('div');
			$tableActions->setAttribute('class', 'actions');

			$tableActions->appendChild(Widget::Select('with-selected', $options));
			$tableActions->appendChild(Widget::Input('action[apply]', __('Apply'), 'submit'));

			$this->Form->appendChild($tableActions);
			$this->Form->appendChild($Pager->save());
		}

		/**
		 * Displays the WebHooks index page within the Symphony administration panel.
		 *
		 * @access public
		 * @param none
		 * @return NULL
		 */
		public function __actionIndex() {
			if(false === isset($_POST['with-selected'])) {
				return;
			}

			foreach($_POST['items'] as $id => $state) {
				switch($_POST['with-selected']) {
					case 'enable':
						/**
						 * Fires off before a WebHook is enabled.
						 *
						 * @delegate WebHookPreEnable
						 * @param string $context
						 * '/extensions/webhooks/'
						 * @param integer id
						 *  WebHook record id
						 */
						Symphony::ExtensionManager()->notifyMembers('WebHookPreEnable', '/extension/webhooks/', array('id' => (int) $id));

						Symphony::Database()->update(array('is_active' => true), 'sym_extensions_webhooks', '`id` = '.(int) $id);
						break;
					case 'disable':
						/**
						 * Fires off before a WebHook is disabled.
						 *
						 * @delegate WebHookPreDisable
						 * @param string $context
						 * '/extensions/webhooks/'
						 * @param integer id
						 *  WebHook record id
						 */
						Symphony::ExtensionManager()->notifyMembers('WebHookPreDisable', '/extension/webhooks/', array('id' => (int) $id));

						Symphony::Database()->update(array('is_active' => false), 'sym_extensions_webhooks', '`id` = '.(int) $id);
						break;
					case 'delete':
						/**
						 * Fires off before a WebHook is deleted.
						 *
						 * @delegate WebHookPreDelete
						 * @param string $context
						 * '/extensions/webhooks/'
						 * @param integer id
						 *  WebHook record id
						 */
						Symphony::ExtensionManager()->notifyMembers('WebHookPreDelete', '/extension/webhooks/', array('id' => (int) $id));

						Symphony::Database()->delete('sym_extensions_webhooks', '`id` = '.(int) $id);
						break;
				}
			}

			redirect(Extension_WebHooks::baseUrl());
		}

		/**
		 * Processes and validates new and updated webhook records.
		 *
		 * @access public
		 * @param none
		 * @return NULL
		 */
		public function __actionNew() {
			require_once TOOLKIT.'/util.validators.php';
			$fields = $_POST['fields'];
			$this->_errors = array();

			if(false === isset($fields['label']) || trim($fields['label']) == '')
				$this->_errors['label'] = __('`Label` is a required field.');

			if(false === isset($this->sectionNamesArray[$fields['section_id']]))
				$this->_errors['section_id'] = __('`Section` is a required field.');

			if(false === isset($fields['callback']) || false === preg_match($validators['URI'], $fields['callback']) || false == trim($fields['callback']))
				$this->_errors['callback'] = __('`Callback URL` is a required field and must be a valid address.');

			if(empty($this->_errors) && false === isset($fields['id'])) {
				$uniqueConstraintCheck = array_pop(Symphony::Database()->fetch("
					SELECT COUNT(1) AS count 
					FROM `sym_extensions_webhooks`
					WHERE
						    `section_id` = ".(int) $fields['section_id']."
						AND `verb`       = '".trim($fields['verb'])."'
						AND `callback`   = '".trim($fields['callback'])."'
				"));

				if($uniqueConstraintCheck['count']) {
					$this->_errors = array(
						'section_id' => __('Unique constraint violation.'),
						'verb'       => __('Unique constraint violation.'),
						'callback'   => __('Unique constraint violation.')
					);

					$this->pageAlert(
						__('The WebHook could not be saved. There has been a unique constraint violation. Please ensure you have a unique combination of `Verb`, `Section` and `Callback URL`.'),
						Alert::ERROR
					);
					return;
				}
			}

			if($this->_errors) {
				$this->pageAlert(
					__('The WebHook could not be saved. Please ensure you filled out the form properly.'),
					Alert::ERROR
				);
				return;
			}

			try {
				if(isset($fields['id'])) {
					/**
					 * Fires off before a WebHook is updated.
					 *
					 * @delegate WebHookPreUpdate
					 * @param string $context
					 * '/extensions/webhooks/'
					 * @param array $fields
					 *  Values representing a webhook
					 */
					Symphony::ExtensionManager()->notifyMembers('WebHookPreUpdate', '/extension/webhooks/', array('fields' => &$fields));

					Symphony::Database()->update(array(
						'label'      => General::sanitize($fields['label']),
						'section_id' => (int) $fields['section_id'],
						'verb'       => $fields['verb'],
						'callback'   => General::sanitize($fields['callback']),
						'is_active'  => isset($fields['is_active']) ? TRUE : FALSE
					), 'sym_extensions_webhooks', '`id` = '.(int) $fields['id']);
				} else {
					/**
					 * Fires off before a WebHook is created.
					 *
					 * @delegate WebHookPreInsert
					 * @param string $context
					 * '/extensions/webhooks/'
					 * @param array $fields
					 *  Values representing a webhook
					 */
					Symphony::ExtensionManager()->notifyMembers('WebHookPreInsert', '/extension/webhooks/', array('fields' => &$fields));

					Symphony::Database()->insert(array(
						'label'      => General::sanitize($fields['label']),
						'section_id' => (int) $fields['section_id'],
						'verb'       => $fields['verb'],
						'callback'   => General::sanitize($fields['callback']),
						'is_active'  => isset($fields['is_active']) ? TRUE : FALSE
					), 'sym_extensions_webhooks');

					/**
					 * Fires off after a WebHook is created.
					 *
					 * @delegate WebHookPostInsert
					 * @param string $context
					 * '/extensions/webhooks/'
					 * @param integer $id
					 *  WebHook record id
					 */
					Symphony::ExtensionManager()->notifyMembers('WebHookPostInsert', '/extension/webhooks/', array('id' => (int) Symphony::Database()->getInsertID()));
				}
			} catch(Exception $Exception) {
				$this->pageAlert(
					$Exception->getMessage(),
					Alert::ERROR
				);
				return;
			}

			if(isset($fields['id'])) {
				$this->pageAlert(
					__('WebHook has been updated successfully!'),
					Alert::SUCCESS
				);
				return;
			}

			redirect(Extension_WebHooks::baseUrl().'/edit/'.Symphony::Database()->getInsertID().'/created/');
		}

		/**
		 * Generates the form used to create new, and edit existing, webhooks. Nothing really special
		 * going on here except that when field values are either passed as a parameter or as a $_POST
		 * value, this method will automatically populate the forms with the relevant information.
		 *
		 * @access public
		 * @param none
		 * @return NULL
		 */
		public function __viewNew() {
			if(false === empty($_POST) && false == $fields) {
				$fields = $_POST['fields'];
			}

			$this->setPageType('form');
			$this->setTitle(__('%1$s &ndash; %2$s', array(__('Symphony'), __('WebHooks'))));
			$this->appendSubheading(false === isset($fields['id']) ? __('Untitled') : $fields['label']);

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('WebHook Settings')));

			$label = Widget::Label(__('Label'));
			$label->appendChild(Widget::Input(
				'fields[label]', General::sanitize($fields['label'])
			));

			if(isset($this->_errors['label']))
				$label = $this->wrapFormElementWithError($label, $this->_errors['label']);

			$callback = Widget::Label(__('Callback URL'));
			$callback->appendChild(Widget::Input(
				'fields[callback]', General::sanitize($fields['callback'])
			));

			if(isset($this->_errors['callback']))
				$callback = $this->wrapFormElementWithError($callback, $this->_errors['callback']);

			$options = array(array(NULL, false, __('Select One...')));
			foreach($this->sectionNamesArray as $id => $name) {
				$options[] = array($id, false, $name);
			}

			if(isset($fields['section_id'])) {
				foreach($options as &$option) {
					if($option[0] == $fields['section_id'])
						$option[1] = true;
				}
			}

			$section = Widget::Label(__('Target Section'));
			$section->appendChild(Widget::Select('fields[section_id]', $options));

			if(isset($this->_errors['section_id']))
				$section = $this->wrapFormElementWithError($section, $this->_errors['section_id']);

			$options = array(
				array('POST',   false, 'POST'), 
				array('PUT',    false, 'PUT'), 
				array('DELETE', false, 'DELETE')
			);

			if(isset($fields['verb'])) {
				foreach($options as &$option) {
					if($option[0] == $fields['verb'])
						$option[1] = true;
				}
			}

			$verb = Widget::Label(__('Verb'));
			$verb->appendChild(Widget::Select('fields[verb]', $options));

			if(isset($this->_errors['verb']))
				$verb = $this->wrapFormElementWithError($verb, $this->_errors['verb']);

			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');

			$group->appendChild($section);
			$group->appendChild($verb);

			$isActive = Widget::Label();
			$isActiveCheckbox = Widget::Input('fields[is_active]', 'yes', 'checkbox', ($fields['is_active'] ? array('checked' => 'checked') : NULL));

			$isActive->setValue(__('%1$s Activate this WebHook', array($isActiveCheckbox->generate())));

			$actions = new XMLElement('div');
			$actions->setAttribute('class', 'actions');
			$actions->appendChild(Widget::Input(
				'action[save]', isset($fields['id']) ? __('Update WebHook') : __('Create WebHook'),
				'submit', array('accesskey' => 's')
			));

			if(isset($fields['id'])){
				$button = new XMLElement('button', __('Delete'));
				$button->setAttributeArray(array('name' => 'action[delete]', 'class' => 'button confirm delete', 'title' => __('Delete this webhook'), 'accesskey' => 'd', 'data-message' => __('Are you sure you want to delete this webhook?')));
				$actions->appendChild($button);
			}

			$fieldset->appendChild($label);
			$fieldset->appendChild($group);
			$fieldset->appendChild($callback);
			$fieldset->appendChild($isActive);
			$fieldset->appendChild($actions);

			if(isset($fields['id'])) {
				$fieldset->appendChild(Widget::Input('fields[id]', $fields['id'], 'hidden'));
			}

			$this->Form->appendChild($fieldset);
		}

		/**
		 * Called when the 'save changes' button is clicked on the webhook edit screen. Or, alternatively,
		 * when the 'delete' button is clicked from the edit screen. In the case of the latter, we populate
		 * the $_POST array and redirect to '__actionIndex' so we can use the code that's already there to
		 * delete the chosen webhook. However, saving changes to an existing webhook just redirects to the 
		 * '__actionIndex' method as it houses code for both editing and saving.
		 *
		 * @access public
		 * @param none
		 * @return ContentExtensionWebhooksHooks::__actionIndex() OR ContentExtensionWebhooksHooks::__actionNew()
		 */
		public function __actionEdit() {
			if(isset($_POST['action']['delete']) && isset($_POST['fields']['id'])) {
				$_POST['with-selected'] = 'delete';
				$_POST['items'] = array($_POST['fields']['id'] => '');
				return $this->__actionIndex();
			}

			return $this->__actionNew();
		}

		/**
		 * If a record id of an existing webhook has been provided, this method will attempt
		 * to retrieve the corresponding record from the database and provide the result to
		 * ContentExtensionWebhooksHooks::__viewNew() to generate the resulting form for editing
		 * purposes.
		 *
		 * @access public
		 * @param none
		 * @return ContentExtensionWebhooksHooks::__viewNew();
		 */
		public function __viewEdit() {
			if(
				isset($this->_context[0]) && $this->_context[0] === 'edit' && 
				isset($this->_context[1]) && is_numeric($this->_context[1])
			) {
				$webHook = Symphony::Database()->fetch('
					SELECT
						`id`,
						`label`,
						`section_id`,
						`verb`,
						`callback`,
						`is_active` 
					FROM `sym_extensions_webhooks`
					WHERE `id` = '.(int) $this->_context[1]
				);

				if(false == $webHook) {
					$this->pageAlert(
						__('The WebHook you specified could not be located.'),
						Alert::ERROR
					);
					return $this->__viewIndex();
				}

				if(isset($this->_context[2]) && $this->_context[2] == 'created') {
					$this->pageAlert(
						__(
							'WebHook created at %1$s. <a href="%2$s" accesskey="c">Create another?</a> <a href="%3$s" accesskey="a">View all WebHooks</a>',
							array(
								Widget::Time()->generate(__SYM_TIME_FORMAT__),
								SYMPHONY_URL . '/extension/webhooks/hooks/new/',
								SYMPHONY_URL . '/extension/webhooks/hooks/'
							)
						),
						Alert::SUCCESS
					);
				}

				return $this->__viewNew($webHook[0]);
			}
		}
	}

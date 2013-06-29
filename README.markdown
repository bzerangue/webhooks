# WebHooks
 
* Version: v1.0
* Author: Wilhelm Murdoch, Korelogic, Brian Zerangue
* Build Date: 2012-06-29
* Requirements: Symphony 2.3.x

## Purpose
A simple Symphony extension that allows developers to assocate WebHooks with content publishing events. These hooks are assigned to a content section and then to a specific event delegate for the content associated within this section: PUT, POST, DELETE. If a matching event occurs within an assigned content section, this extension will send a push notification to the specified callback URL with the event type and all information associated with the content entry.

## Use Case
Say, you want to notify an external service whenever you post a new blog article. This extension allows you to assign a WebHook which will listen for a POST event within your 'Articles' section. Whenever an entry is created within this section, this extension will send a push notification, along with a payload, to a callback URL you specify.

## Installation
 
1. Upload the "webhooks" folder in this archive to your Symphony 'extensions' folder
2. Enable it by selecting "WebHooks" in the list, choose Enable from the with-selected menu, then click Apply

## Usage
### Overview
Once installation is complete, you will find a new menu item under 'System' within the Symphony administration panel. Clicking on this menu item will take you to the WebHooks index. From here, you will have a bird's eye view of all your current WebHooks, as well as options for managing them.

### Creating
Creating a new WebHook is a fairly straight forward process. Just click on the 'Create New' button located on the top right-hand corner of the WebHooks index screen, you will be taken to a new screen where you will provide the details of your new WebHook.

Here is a breakdown of the form fields:

* `Label` This is a required field and is used only has a visual reference for that WebHook
* `Target Section` This is the content section this WebHook will be associated with
* `Verb` This is the event this WebHook will listen for in its assigned `Target Section` (POST: New Records, PUT: Updated Records, DELETE: Removed Records)
* `Callback URL` This is the URL notifications will be sent to
* `Activate this WebHook` Obviously, this will activate or deactivate your WebHook

Once the form is filled out, just click on the 'Create Webhook' button in the lower right-hand side of the screen and you should be good to go. Something I should note is that there is a unique constraint when creating new WebHooks. You must ensure you have a unique combination of `Verb`, `Section` and `Callback URL` upon creation, otherwise, Symphony will throw an error your way.

### Editing
To edit an existing WebHook, just go back to the index screen and click on the `Label` of any entry. You will be taken to the edit screen where you can make any changes you like. Remember, before saving your changes, be sure this WebHook doesn't conflict with another existing one; unique constraints still apply here.

### Deleting
There are two methods of WebHook deletion:

1. On the index, select any number of existing WebHooks. Then, on the lower right-hand corner of the screen, select 'Delete' from the 'With selected...' dropdown box and click 'Apply'. You will be asked to confirm your decision before Symphony removes the records.
2. On an edit screen, there exists a small 'delete' button in the lower left-hand corner of the screen. Click it, confirm your decision and you will be redirected back to the index screen with the record removed.

## Notification Payload
### Overview
All payloads are delivered to the specified `Callback URL` as an HTTP POST request. The receiving end of the notification will see the following values:

* `$_POST['verb']` This is the event that originally triggered the notification
* `$_POST['callback']` This is the `Callback URL` this notification was originally meant to hit
* `$_POST['body']` This contains a JSON object that contains the sections fields and corresponding values of the associated `Target Section` entry

### JSON Body
POST and PUT payloads, currently, are delivered in the following standard JSON format:

	[
	   {
	      "id":"1",
	      "element_name":"title",
	      "type":"input",
	      "location":"main",
	      "value":{
	         "value":"Push Test (PUT)",
	         "handle":"push-test-put"
	      }
	   },
	   {
	      "id":"2",
	      "element_name":"body",
	      "type":"textarea",
	      "location":"main",
	      "value":{
	         "value":"<p>This is a test.<\/p>",
	         "value_formatted":"<p>This is a test.<\/p>"
	      }
	   },
	   {
	      "id":"23",
	      "element_name":"documents",
	      "type":"subsectionmanager",
	      "location":"sidebar",
	      "value":'/path/to/document.doc'
	   }
	]

This is simply an array that contains object representing `Target Section` fields and their associated values for this entry.

DELETE payloads, however, differ as they only provide the entry id of the delete record:

	[
		{
			"id":"1"
		}
	]

### Headers
Here are the following HTTP headers that are sent along each notification. This list may change in the future.

1. `Content-Type: application/json`

## Delegates
I originally intended the first version of this extension to have a plugin architecture so that others may create highly specialized custom WebHooks (ie: Facebook, Flickr, Twitter integrations). Unfortunately, time is an issue at the moment, so I can't really move forward with this idea. On the flip side, this gives me the opportunity to perfecet the architecture before taking the time to develop it.

In lieu of this, I have added the following comprehensive list of new delegates which can be used for other 3rd-party Symphony extensions who may want to integrate with WebHook functionality:

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
	 * Fires off before a WebHook is enabled.
	 *
	 * @delegate WebHookPreEnable
	 * @param string $context
	 * '/extensions/webhooks/'
	 * @param integer id
	 *  WebHook record id
	 */
	Symphony::ExtensionManager()->notifyMembers('WebHookPreEnable', '/extension/webhooks/', array('id' => (int) $id));

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

## Requirements

1. This extension now requires Symphony 2.3. 

## Note 

1. Removed requirement for Pager Extension and moved the Pager Library into the lib folder of this extension. 

## Issues

There are currently no known issues with this extension. If you find anything wrong, feel free to submit a pull request or submit an issue to the tracker.
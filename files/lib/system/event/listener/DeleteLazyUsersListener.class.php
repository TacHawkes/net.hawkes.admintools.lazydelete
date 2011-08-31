<?php
// wcf imports
require_once(WCF_DIR.'lib/system/event/EventListener.class.php');

/**
 * Handles the deletion of lazy users.
 *
 *
 * This file is part of Admin Tools 2.
 *
 * Admin Tools 2 is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Admin Tools 2 is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Admin Tools 2.  If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * @author	Oliver Kliebisch
 * @copyright	2009 Oliver Kliebisch
 * @license	GNU General Public License <http://www.gnu.org/licenses/>
 * @package	net.hawkes.admintools.lazydelete
 * @subpackage 	system.event.listener
 * @category 	WBB
 */
class DeleteLazyUsersListener implements EventListener {
	protected $data;
	protected $deletedLazyUsers = array();
	protected $warnedLazyUsers = array();
	protected $ignoreCondition;
	protected $timeField = 'lastActivityTime';

	/**
	 * @see EventListener::execute()
	 */
	public function execute($eventObj, $className, $eventName) {
		$this->data = $eventObj->data;
		$generalOptions = $this->data['parameters']['user.inactiveUsers.general'];
		$this->ignoreCondition = new ConditionBuilder(false);
		if (!empty($generalOptions['ignoredUserIDs'])) $this->ignoreCondition->add('user.userID NOT IN ('.$generalOptions['ignoredUserIDs'].')');
		if (!empty($generalOptions['ignoredUsergroupIDs'])) $this->ignoreCondition->add('user.userID NOT IN (SELECT userID FROM wcf'.WCF_N.'_user_to_groups WHERE groupID IN ('.$generalOptions['ignoredUsergroupIDs'].'))');
		$this->ignoreCondition->add('user.registrationDate < '.(TIME_NOW - $generalOptions['periodOfGrace'] * 86400));
		$deleteOptions = $this->data['parameters']['user.inactiveUsers.lazy'];
		if ($deleteOptions['postSinceRegistration']) {
			$this->timeField = 'registrationDate';
		}
		switch($eventName) {
			case 'execute' :
				$this->handleLazyUsers($this->data['parameters']['user.inactiveUsers.general']);
				$eventObj->setReturnMessage('success',  WCF::getLanguage()->get('wbb.acp.admintools.function.user.inactiveUsers.lazy.success', array('$countDeleted' => count($this->deletedLazyUsers), '$countWarned' => count($this->warnedLazyUsers))));
				break;
			case 'generateMessage' :
				$this->appendMessage($eventObj);
				break;
		}
	}

	/**
	 * Handles the warning and deletion of lazy users
	 *
	 * @param $generalOptions
	 */
	protected function handleLazyUsers($generalOptions) {
		$deleteOptions = $this->data['parameters']['user.inactiveUsers.lazy'];
		switch($deleteOptions['action']) {
			case 'none' : 
				return;
			case 'warn' : 
				$this->warnUsers($generalOptions, true);
				break;
			case 'delete' : 
				$this->deleteUsers($generalOptions);
				break;
			case 'warnanddelete' : 
				$this->warnUsers($generalOptions);
				$this->deleteUsers($generalOptions);
				break;
		}
	}

	/**
	 * Warns lazy users
	 *
	 * @param $generalOptions
	 * @param $warnOnly
	 */
	protected function warnUsers($generalOptions, $warnOnly = false) {
		if (!$generalOptions['sendWarnMail']) return;
		$deleteOptions = $this->data['parameters']['user.inactiveUsers.lazy'];

		$sql = "SELECT user.* FROM wcf".WCF_N."_user user
				LEFT JOIN wcf".WCF_N."_user_option_value user_option ON (user_option.userID = user.userID)
				LEFT JOIN wbb".WBB_N."_user wbb_user ON (wbb_user.userID = user.userID)				
				WHERE user_option.useroption".WCF::getUser()->getUserOptionID('adminCanMail')." = 1
				AND wbb_user.posts < ".$deleteOptions['postThreshold']."
				AND user.".$this->timeField." < ".(TIME_NOW - ($deleteOptions['time'] - $generalOptions['warnTime']) * 86400)."
				AND user.".$this->timeField." > ".(TIME_NOW - ($deleteOptions['time'] - $generalOptions['warnTime'] + 1) * 86400)."
				AND ".$this->ignoreCondition->get()."
				GROUP BY user.userID";
		$result = WCF::getDB()->sendQuery($sql);
		$users = array();

		while($row = WCF::getDB()->fetchArray($result)) {
			$users[] = new User(null, $row);
		}
		$from = MAIL_FROM_NAME." <".MAIL_FROM_ADDRESS.">";
		foreach($users as $user) {
			$messageData = array(
				'$username' => $user->username,
				'$pagetitle' => PAGE_TITLE,
				'$lastvisit' => ($deleteOptions['time'] - $generalOptions['warnTime']),
				'$warntime' => $generalOptions['warnTime'],
				'$warnonly' => $warnOnly,
				'$lazythreshold' => $deleteOptions['postThreshold']
			);

			$languageID = $user->languageID;
			if (!$languageID > 0) $languageID = '';
			$mail = new Mail(array($user->username => $user->email), WCF::getLanguage($languageID)->get('wbb.acp.admintools.function.user.inactiveUsers.lazy.mailsubject', array('$pagetitle' => PAGE_TITLE)), WCF::getLanguage($languageID)->get('wbb.acp.admintools.function.user.inactiveUsers.lazy.mailmessage', $messageData), $from);
			$mail->send();
			$this->warnedLazyUsers[] = $user;
		}
	}

	/**
	 * Deletes lazy users
	 *
	 * @param $generalOptions
	 */
	protected function deleteUsers($generalOptions) {
		$deleteOptions = $this->data['parameters']['user.inactiveUsers.lazy'];

		$sql = "SELECT 		user.* 
			FROM 		wcf".WCF_N."_user user
			LEFT JOIN 	wcf".WCF_N."_user_option_value user_option 
			ON 		(user_option.userID = user.userID)
			LEFT JOIN 	wbb".WBB_N."_user wbb_user 
			ON 		(wbb_user.userID = user.userID)				
			WHERE 		user.".$this->timeField." < ".(TIME_NOW - ($deleteOptions['time'] * 86400))."
			AND 		wbb_user.posts < ".$deleteOptions['postThreshold']."								
			AND 		".$this->ignoreCondition->get()."
			GROUP BY 	user.userID";		
		$result = WCF::getDB()->sendQuery($sql);
		$userIDs = array();

		while($row = WCF::getDB()->fetchArray($result)) {
			$this->deletedLazyUsers[] = new User(null, $row);
			$userIDs[] = $row['userID'];
		}

		UserEditor::deleteUsers($userIDs);
	}

	/**
	 * Appends the mail message
	 *
	 * @param $eventObj
	 */
	protected function appendMessage($eventObj) {
		$deleteOptions = $this->data['parameters']['user.inactiveUsers.lazy'];
		$generalOptions = $this->data['parameters']['user.inactiveUsers.general'];
		$message = $eventObj->message;
		if (($deleteOptions['action'] == 'warn' || $deleteOptions['action'] == 'warnanddelete') && $generalOptions['sendWarnMail']) {
			$message .= "\n\n";
			$message .= WCF::getLanguage()->get('wbb.acp.admintools.function.user.inactiveUsers.lazy.mailwarned', array('$count' => count($this->warnedLazyUsers))).$eventObj->messageTableHeader;
			if(count($this->warnedLazyUsers)) {
				foreach($this->warnedLazyUsers as $user) {
					$message .= "\n".str_pad($user->username, 26, " ")
					.str_pad($user->userID, 12, " ", STR_PAD_LEFT)
					."    "
					.str_pad(DateUtil::formatDate(WCF::getLanguage()->get('wcf.global.timeFormat'), $user->registrationDate), 20, " ")
					.DateUtil::formatDate(WCF::getLanguage()->get('wcf.global.timeFormat'), $user->lastActivityTime);
				}
			}
			else $message .= "\n-";
		}

		if ($deleteOptions['action'] == 'delete' || $deleteOptions['action'] == 'warnanddelete') {
			$message .= "\n\n";
			$message .= WCF::getLanguage()->get('wbb.acp.admintools.function.user.inactiveUsers.lazy.adminmail', array('$count' => count($this->deletedLazyUsers))).$eventObj->messageTableHeader;
			if(count($this->deletedLazyUsers)) {
				foreach($this->deletedLazyUsers as $user) {
					$message .= "\n".str_pad($user->username, 26, " ")
					.str_pad($user->userID, 12, " ", STR_PAD_LEFT)
					."    "
					.str_pad(DateUtil::formatDate(WCF::getLanguage()->get('wcf.global.timeFormat'), $user->registrationDate), 20, " ")
					.DateUtil::formatDate(WCF::getLanguage()->get('wcf.global.timeFormat'), $user->lastActivityTime);
				}
			}
			else $message .= "\n-";
		}

		$eventObj->message = $message;
	}
}
?>

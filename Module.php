<?php
/**
 * @copyright Copyright (c) 2017, Afterlogic Corp.
 * @license AGPL-3.0 or AfterLogic Software License
 *
 * This code is licensed under AGPLv3 license or AfterLogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\MailNotesPlugin;

/**
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
	public function init()
	{
		$this->subscribeEvent('Mail::GetFolders::before', array($this, 'onBeforeGetFolders'));
	}
	
	public function onBeforeGetFolders(&$aArgs, &$mResult)
	{
		$oMailModule = \Aurora\System\Api::GetModule('Mail');
		$oApiAccountsManager = $oMailModule->oApiAccountsManager;
		$oApiMailManager = $oMailModule->oApiMailManager;
		
		$iAccountID = $aArgs['AccountID'];
		$oAccount = $oApiAccountsManager->getAccountById($iAccountID);
		$oNamespace = $oApiMailManager->getFoldersNamespace($oAccount);
		$sNamespace = $oNamespace ? $oNamespace->GetPersonalNamespace() : '';
		$aResult = $oApiMailManager->getFolderListInformation($oAccount, array($sNamespace . 'Notes'));
		if (empty($aResult))
		{
			$oMailModule->CreateFolder($iAccountID, $sNamespace . 'Notes', '', '/');
		}
	}
	
	protected function populateFromOrigMessage($AccountId, $FolderFullName, $MessageUid, &$oMessage)
	{
		$oOrigMessage = \Aurora\Modules\Mail\Module::Decorator()->GetMessage($AccountId, $FolderFullName, $MessageUid);
		
		if ($oOrigMessage)
		{
			$oFromCollection = $oOrigMessage->getFrom();
			if (isset($oFromCollection) && $oFromCollection->Count() > 0)
			{
				$oMessage->SetFrom($oFromCollection->GetByIndex(0));
			}
			$oToCollection = $oOrigMessage->getTo();
			if (isset($oToCollection) && $oToCollection->Count() > 0)
			{
				$oMessage->SetTo($oToCollection);
			}
		}
	}
	
	public function SaveNote($AccountId, $FolderFullName, $Text, $Subject, $MessageUid = null)
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		$oMailModule = \Aurora\System\Api::GetModule('Mail');
		$oApiAccountsManager = $oMailModule->oApiAccountsManager;
		$oAccount = $oApiAccountsManager->getAccountById($AccountId);
		$oApiMailManager = $oMailModule->oApiMailManager;
		
		$oMessage = \MailSo\Mime\Message::NewInstance();
		$oMessage->RegenerateMessageId();
		$oMessage->SetSubject($Subject);
		$oMessage->AddText($Text, true);
		$oMessage->SetCustomHeader('X-Uniform-Type-Identifier', 'com.apple.mail-note');
		$oMessage->SetCustomHeader('X-Universally-Unique-Identifier', uniqid());
		
		if (!empty($MessageUid))
		{
			$this->populateFromOrigMessage($AccountId, $FolderFullName, $MessageUid, $oMessage);
			$oApiMailManager->deleteMessage($oAccount, $FolderFullName, array($MessageUid));
		}
		
		$rMessageStream = \MailSo\Base\ResourceRegistry::CreateMemoryResource();
		$iMessageStreamSize = \MailSo\Base\Utils::MultipleStreamWriter($oMessage->ToStream(true), array($rMessageStream), 8192, true, true, true);
		$iNewUid = 0;
		$oApiMailManager->appendMessageFromStream($oAccount, $rMessageStream, $FolderFullName, $iMessageStreamSize, $iNewUid);
		$oApiMailManager->setMessageFlag($oAccount, $FolderFullName, [$iNewUid], \MailSo\Imap\Enumerations\MessageFlag::SEEN, \Aurora\Modules\Mail\Enums\MessageStoreAction::Add);
		
		return $iNewUid;
	}
}

<?php if (!defined('APPLICATION')) exit();
/*
Copyright 2008, 2009 Vanilla Forums Inc.
This file is part of Garden.
Garden is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
Garden is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
You should have received a copy of the GNU General Public License along with Garden.  If not, see <http://www.gnu.org/licenses/>.
Contact Vanilla Forums Inc. at support [at] vanillaforums [dot] com
*/

/**
 * Handles relating external actions to comments and discussions. Flagging, Praising, Reporting, etc
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @copyright 2003 Mark O'Sullivan
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPL
 * @package Garden
 * @version @@GARDEN-VERSION@@
 * @namespace Garden.Core
 */

class Gdn_Regarding extends Gdn_Pluggable implements Gdn_IPlugin {

   public function __construct() {
      parent::__construct();
   }

   /* With regard to... */

   /**
    * Start a RegardingEntity for a comment
    *
    * Able to autoparent to its discussion owner if verfied.
    *
    * @param $CommentID int ID of the comment
    * @param $Verify optional boolean whether or not to verify this. default true.
    * @param $AutoParent optional boolean whether or not to try to autoparent. default true.
    * @return Gdn_RegardingEntity
    */
   public function Comment($CommentID, $Verify = TRUE, $AutoParent = TRUE) {
      $Regarding = $this->Regarding('Comment', $CommentID, $Verify);
      if ($Verify && $AutoParent) $Regarding->AutoParent('discussion');
      return $Regarding;
   }

   /**
    * Start a RegardingEntity for a discussion
    *
    * @param $DiscussionID int ID of the discussion
    * @param $Verify optional boolean whether or not to verify this. default true.
    * @return Gdn_RegardingEntity
    */
   public function Discussion($DiscussionID, $Verify = TRUE) {
      return $this->Regarding('Discussion', $DiscussionID, $Verify);
   }

   /**
    * Start a RegardingEntity for a conversation message
    *
    * Able to autoparent to its conversation owner if verfied.
    *
    * @param $MessageID int ID of the conversation message
    * @param $Verify optional boolean whether or not to verify this. default true.
    * @param $AutoParent optional boolean whether or not to try to autoparent. default true.
    * @return Gdn_RegardingEntity
    */
   public function Message($MessageID, $Verify = TRUE, $AutoParent = TRUE) {
      $Regarding = $this->Regarding('ConversationMessage', $MessageID, $Verify);
      if ($Verify && $AutoParent) $Regarding->AutoParent('conversation');
      return $Regarding;
   }

   /**
    * Start a RegardingEntity for a conversation
    *
    * @param $ConversationID int ID of the conversation
    * @param $Verify optional boolean whether or not to verify this. default true.
    * @return Gdn_RegardingEntity
    */
   public function Conversation($ConversationID, $Verify = TRUE) {
      return $this->Regarding('Conversation', $ConversationID, $Verify);
   }

   protected function Regarding($ThingType, $ThingID, $Verify = TRUE) {
      $Verified = FALSE;
      if ($Verify) {
         $ModelName = ucfirst($ThingType).'Model';

         if (!class_exists($ModelName))
            throw new Exception(sprintf(T("Could not find a model for %s objects."), ucfirst($ThingType)));

         // If we can lookup this object, it is verified
         $VerifyModel = new $ModelName;
         $SourceElement = $VerifyModel->GetID($ThingID);
         if ($SourceElement !== FALSE)
            $Verified = TRUE;

      } else {
         $Verified = NULL;
      }

      if ($Verified !== FALSE) {
         $Regarding = new Gdn_RegardingEntity($ThingType, $ThingID);
         if ($Verify)
            $Regarding->VerifiedAs($SourceElement);

         return $Regarding;
      }

      throw new Exception(sprintf(T("Could not verify entity relationship '%s(%d)' for Regarding call"), $ModelName, $ThingID));
   }

   // Transparent forwarder to built-in starter methods
   public function That() {
      $Args = func_get_args();
      $ThingType = array_shift($Args);

      return call_user_func_array(array($this, $ThingType), $Args);
   }

   /*
    * Event system: Provide information for external hooks
    */

   public function GetEvent(&$EventArguments) {
      /**
      * 1) Entity
      * 2) Regarding Data
      * 3) [optional] Options
      */
      $Response = array(
         'EventSender'     => NULL,
         'Entity'          => NULL,
         'RegardingData'   => NULL,
         'Options'         => NULL
      );

      if (sizeof($EventArguments) >= 1)
         $Response['EventSender'] = $EventArguments[0];

      if (sizeof($EventArguments) >= 2)
         $Response['Entity'] = $EventArguments[1];

      if (sizeof($EventArguments) >= 3)
         $Response['RegardingData'] = $EventArguments[2];

      if (sizeof($EventArguments) >= 4)
         $Response['Options'] = $EventArguments[3];

      return $Response;
   }

   public function MatchEvent($RegardingType, $ForeignType, $ForeignID = NULL) {
      $EventOptions = $Sender->GetEvent($this->EventArguments);

      return $EventOptions;
   }

   /*
    * Event system: Hook into core events
    */

   // Cache regarding data for displayed comments
   public function DiscussionController_BeforeDiscussionRender_Handler($Sender) {
      if (GetValue('RegardingCache', $Sender, NULL) != NULL) return;

      $Comments = $Sender->Data('CommentData');
      $CommentIDList = array();
      if ($Comments && $Comments instanceof Gdn_DataSet) {
         $Comments->DataSeek(-1);
         while ($Comment = $Comments->NextRow())
            $CommentIDList[] = $Comment->CommentID;
      }

      $this->CacheRegarding($Sender, 'discussion', $Sender->Discussion->DiscussionID, 'comment', $CommentIDList);
   }

   protected function CacheRegarding($Sender, $ParentType, $ParentID, $ForeignType, $ForeignIDs) {

      $Sender->RegardingCache = array();

      $ChildRegardingData = $this->RegardingModel()->GetAll($ForeignType, $ForeignIDs);
      $ParentRegardingData = $this->RegardingModel()->Get($ParentType, $ParentID);

/*
      $MediaArray = array();
      if ($MediaData !== FALSE) {
         $MediaData->DataSeek(-1);
         while ($Media = $MediaData->NextRow()) {
            $MediaArray[$Media->ForeignTable.'/'.$Media->ForeignID][] = $Media;
            $this->MediaCacheById[GetValue('MediaID',$Media)] = $Media;
         }
      }
*/

      $this->RegardingCache = array();
   }

   public function DiscussionController_BeforeCommentBody_Handler($Sender) {
      echo "beforecommentbody\n";
      $Context = strtolower($Sender->EventArguments['Type']);
      if ($Context != 'discussion') return;
      echo "post context\n";

      $RegardingID = GetValue('RegardingID', $Sender->EventArguments['Object'], NULL);
      if (is_null($RegardingID) || $RegardingID < 0) return;
      echo "post regardingID\n";

      try {
         $RegardingData = $this->RegardingModel()->GetID($RegardingID);
         $this->EventArguments = array_merge($this->EventArguments,array(
            'EventSender'     => $Sender,
            'Entity'          => $Sender->EventArguments['Object'],
            'RegardingData'   => $RegardingData,
            'Options'         => NULL
         ));
         $this->FireEvent('RegardingDisplay');
      } catch (Exception $e) {}
   }

   public function RegardingModel() {
      static $RegardingModel = NULL;
      if (is_null($RegardingModel))
         $RegardingModel = new RegardingModel();
      return $RegardingModel;
   }

   public function Setup(){}

}

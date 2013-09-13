<?php
/*
 +--------------------------------------------------------------------------+
 | Copyright IT Bliss LLC (c) 2012-2013                                     |
 +--------------------------------------------------------------------------+
 | This program is free software: you can redistribute it and/or modify     |
 | it under the terms of the GNU Affero General Public License as published |
 | by the Free Software Foundation, either version 3 of the License, or     |
 | (at your option) any later version.                                      |
 |                                                                          |
 | This program is distributed in the hope that it will be useful,          |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of           |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the            |
 | GNU Affero General Public License for more details.                      |
 |                                                                          |
 | You should have received a copy of the GNU Affero General Public License |
 | along with this program.  If not, see <http://www.gnu.org/licenses/>.    |
 +--------------------------------------------------------------------------+
*/
require_once 'googleapps.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function googleapps_civicrm_config(&$config) {
  _googleapps_civix_civicrm_config($config);
  // Include path is not working if relying only on the above function
  // seems to be a side-effect of CRM_Core_Smarty::singleton(); also calling config hook
  $extRoot = dirname( __FILE__ ) . DIRECTORY_SEPARATOR;
  set_include_path($extRoot . PATH_SEPARATOR . get_include_path());
  if (is_dir($extRoot . 'packages')) {
    set_include_path($extRoot . 'packages' . PATH_SEPARATOR . get_include_path());
  }
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function googleapps_civicrm_xmlMenu(&$files) {
  _googleapps_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function googleapps_civicrm_install() {
  googleapps_civicrm_config(CRM_Core_Config::singleton());
  return _googleapps_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function googleapps_civicrm_uninstall() {
  // required to define the CONST below
  googleapps_civicrm_config(CRM_Core_Config::singleton());
  require_once 'CRM/Sync/BAO/GoogleApps.php';
  // Delete scheduled job
  $scheduledJob = CRM_Sync_BAO_GoogleApps::get_scheduledJob();
  $scheduledJob->delete();
  // Delete custom group & fields
  $custom_group = CRM_Sync_BAO_GoogleApps::get_customGroup();
  $custom_fields = CRM_Sync_BAO_GoogleApps::get_customFields($custom_group);
  foreach ($custom_fields as $custom_field) {
    $params = array('version' => 3, 'id' => $custom_field['id']);
    $result = civicrm_api('CustomField', 'delete', $params);
  }
  $params = array('version' => 3, 'id' => $custom_group['id']);
  $result = civicrm_api('CustomGroup', 'delete', $params);
  CRM_Core_DAO::executeQuery($query);
  // Delete all settings
//   CRM_Core_BAO_Setting::deleteItem(CRM_Sync_BAO_GoogleApps::GOOGLEAPPS_PREFERENCES_NAME);
  return _googleapps_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function googleapps_civicrm_enable() {
  // Create and enable custom group
  googleapps_civicrm_config(CRM_Core_Config::singleton());
  $params = CRM_Sync_BAO_GoogleApps::get_customGroup();
  $params['version'] = 3;
  $params['is_active'] = 1;
  $result = civicrm_api('CustomGroup', 'create', $params);
  // Create custom fields in this group
  $custom_fields = CRM_Sync_BAO_GoogleApps::get_customFields($params['id']);
  // Reminder to go to the configuration screen
  CRM_Core_Session::setStatus(
    ts('Extension enabled. Please go to the <a href="%1">setup screen</a> to configure it.',
      array(1 => CRM_Utils_System::url('civicrm/admin/sync/googleapps')))
  );
  return _googleapps_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function googleapps_civicrm_disable() {
  // Disable custom group
  $params = CRM_Sync_BAO_GoogleApps::get_customGroup();
  $params['version'] = 3;
  $params['is_active'] = 0;
  $result = civicrm_api('CustomGroup', 'delete', $params);
  return _googleapps_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function googleapps_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _googleapps_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function googleapps_civicrm_managed(&$entities) {
  return _googleapps_civix_civicrm_managed($entities);
}

/**
 * Implementation of hook_civicrm_navigationMenu
 */
function googleapps_civicrm_navigationMenu( &$params ) {
  googleapps_civicrm_config(CRM_Core_Config::singleton());

  // get the id of Administer Menu
  $administerMenuId = CRM_Core_DAO::getFieldValue('CRM_Core_BAO_Navigation', 'Administer', 'id', 'name');
  $sysSettingsMenuId = CRM_Core_DAO::getFieldValue('CRM_Core_BAO_Navigation', 'System Settings', 'id', 'name');
  
  // skip adding menu if there is no administer menu
  if ($sysSettingsMenuId) {
    // get the maximum key under adminster menu
    $maxKey = max( array_keys($params[$administerMenuId]['child'][$sysSettingsMenuId]['child']));
    $params[$administerMenuId]['child'][$sysSettingsMenuId]['child'][$maxKey+1] =  array (
      'attributes' => array(
          'label' => 'CiviCRM sync for Google Apps',
          'url' => 'civicrm/admin/sync/googleapps',
          'permission' => 'administer CiviCRM',
          'parentID' => $sysSettingsMenuId,
          'navID' => $maxKey + 1,
          'active' => 1,
      )
    );
  }  
}

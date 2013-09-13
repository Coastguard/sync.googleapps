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

/**
 * Googleapps_sync API call
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_job_googleapps_sync($params) {
  $custom_group = CRM_Sync_BAO_GoogleApps::get_customGroup();
  $custom_fields = CRM_Sync_BAO_GoogleApps::get_customFields($custom_group['id']);
  $settings = CRM_Sync_BAO_GoogleApps::getSettings();
  if (!isset($settings['group'])) {
    return civicrm_api3_create_error(ts('No sync group configured'));
  }
  $sync_group_id=$settings['group'];
  
  try {
    /*
     * As we are relying on the civicrm_group_contact_cache table to indicate group membership
     * based on smart groups we need to ensure that the smart group cache is refreshed before
     * executing. Otherwise there seems to be a condition where the cache is empty (resulting in
     * deletes) depending on the cache timeout value and the "Rebuild Smart Group Cache" job.
     */
    CRM_Contact_BAO_GroupContactCache::loadAll($sync_group_id);
  } catch (Exception $e) {
    return civicrm_api3_create_error(ts('Unable to refresh smart group before sync') . ": " . $e->getMessage());
  }
   
  // Get the last sync from the system preferences
  $last_sync = CRM_Utils_Array::value('last_sync', $settings, '2000-01-01 00:00:00');

  $gapps = new CRM_Sync_BAO_GoogleApps($settings['oauth_email'], $settings['oauth_key'], $settings['oauth_secret']);
  $gapps->setScope($settings['domain']);
  
  $max_processed = CRM_Utils_Array::value('max_processed', $params, 25);
  $result = array('created'=>0, 'updated'=>0, 'deleted'=>0, 'processed'=>0); // holds summary of actions performed
  
  /*****************************
   * Member of group, not yet synced = CREATE
   *****************************/
  $addedNotSyncedQuery = "
      SELECT
        contact.id
      FROM civicrm_contact contact
        LEFT JOIN " . $custom_group['table_name'] . " custom_gapps ON custom_gapps.entity_id=contact.id
        LEFT JOIN civicrm_group_contact_cache g ON contact.id = g.contact_id
      WHERE contact.is_deleted = 0
        AND contact.contact_type = 'Individual'
        AND g.group_id = %1
        AND custom_gapps." . $custom_fields['google_id']['column_name'] . " IS NULL
      LIMIT %2
  ";
  $addedNotSyncedParams = array(
      1 => array( $sync_group_id, 'Integer' ),
      2 => array( $max_processed, 'Integer' )
  );
  
  $dao = CRM_Core_DAO::executeQuery( $addedNotSyncedQuery, $addedNotSyncedParams );

  try {
    while ( $dao->fetch( ) ) {
      $row = $dao->toArray();
      $success = $gapps->call('contact', 'create', $row['id'] );
      if ($success) {
        $result['created']++;
      }
      
      $result['processed']++;
    }
  } catch (Exception $e) {
    return civicrm_api3_create_error(ts('Google API error: ') . $e->getMessage());
  }
  
  /*****************************
   * Member of group, synced, modified = UPDATE
   *****************************/
  $addedSyncedModifiedQuery = "
      SELECT
        contact.id
      FROM civicrm_contact contact
        LEFT JOIN " . $custom_group['table_name'] . " custom_gapps ON custom_gapps.entity_id=contact.id
        LEFT JOIN (
          SELECT * FROM (
            SELECT * FROM civicrm_log
            WHERE entity_table = 'civicrm_contact'
            ORDER BY modified_date desc
          ) AS t1
          GROUP BY entity_id
        ) AS log ON contact.id = log.entity_id
      WHERE contact.is_deleted = 0
        AND custom_gapps." . $custom_fields['google_id']['column_name'] . " IS NOT NULL
        AND custom_gapps." . $custom_fields['last_sync']['column_name'] . " < log.modified_date
      LIMIT %1
  ";
  $addedSyncedModifiedParams = array(
      1 => array( $max_processed - $result['processed'], 'Integer' )
  );
  
  $dao = CRM_Core_DAO::executeQuery( $addedSyncedModifiedQuery, $addedSyncedModifiedParams );

  try {
    while ( $dao->fetch( ) ) {
      $row = $dao->toArray();
      $success = $gapps->call('contact', 'update', $row['id'] );
      if ($success) {
        $result['updated']++;
      }
      
      $result['processed']++;
    }
  } catch (Exception $e) {
    return civicrm_api3_create_error(ts('Google API error: ') . $e->getMessage());
  }
  
  /*****************************
   * Not in group or deleted, synced = DELETE
  *****************************/
  $removedSyncedQuery = "
      SELECT
        contact.id
      FROM civicrm_contact contact
        LEFT JOIN " . $custom_group['table_name'] . " custom_gapps ON custom_gapps.entity_id=contact.id
        LEFT JOIN civicrm_group_contact_cache g ON contact.id = g.contact_id AND g.group_id = %1
      WHERE custom_gapps." . $custom_fields['google_id']['column_name'] . " IS NOT NULL
        AND (contact.is_deleted = 1 OR g.group_id IS NULL)
      GROUP BY contact.id
      LIMIT %2
  ";
  
  $removedSyncedParams = array(
      1 => array( $sync_group_id, 'Integer' ),
      2 => array( $max_processed - $result['processed'], 'Integer' )
  );
  $dao = CRM_Core_DAO::executeQuery( $removedSyncedQuery, $removedSyncedParams );

  try {
    while ( $dao->fetch( ) ) {
      $row = $dao->toArray();
      $success = $gapps->call('contact', 'delete', $row['id'] );
      if ($success) {
        $result['deleted']++;
      }
  
      $result['processed']++;
    }
  } catch (Exception $e) {
    return civicrm_api3_create_error(ts('Google API error: ') . $e->getMessage());
  }
  
  // all done, create summary
  if (empty($result['processed'])) {
    $messages = "Nothing needed to be synchronized.";
  } else {
    foreach( array('created', 'updated', 'deleted') as $action ) {
      $messages[] = $result[$action] . " contact(s) $action.";
    }
    $settings['processed'] += $result['processed'];
    CRM_Sync_BAO_GoogleApps::setSetting($settings['processed'], 'processed');
  }
  
  CRM_Sync_BAO_GoogleApps::setSetting(date('Y-m-d H:m:s'), 'last_sync');
  
  return civicrm_api3_create_success( $messages );
}
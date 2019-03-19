<?php

require_once 'reltoken.civix.php';

/**
 * implementation of CiviCRM hook
 */
function reltoken_civicrm_tokens(&$tokens) {
  // Get a list of the standard contact tokens.
  // Note that CRM_Core_SelectValues::contactTokens() will invoke this hook again.
  $contactTokens = CRM_Core_SelectValues::contactTokens();
  $hashedRelationshipTypes = _reltoken_get_hashed_relationship_types();

  // For each standard contact token, create a corresponding token for each
  // hashedRelationshipType.  If you have 80 standard tokens, 10 symmetrical
  // relationships and 25 asymmetrical relationships,  This will create
  // 80 * ((25 * 2) + 10), or 4800 tokens.
  foreach ($hashedRelationshipTypes as $hash => $relationshipTypeDetails) {
    foreach ($contactTokens as $token => $label) {
      if (strpos($token, '{contact') !== FALSE) {
        $tokenBase = preg_replace('/^\{contact\.(\w+)\}$/', '$1', $token);
        // Must be in the form: $tokens['X']["X.whatever"] where X is not "contact".
        $tokens['related']["related.{$tokenBase}___reltype_{$hash}"] = "Related ({$relationshipTypeDetails['directionLabel']})::{$label}";
      }
    }
  }
}

/**
 * Returns an array with one element for symmetrical relationships and two
 * elements for assymmetrical relationships.
 */
function _reltoken_get_hashed_relationship_types() {
  static $hashedRelationshipTypes;
  if (!isset($hashedRelationshipTypes)) {
    $hashedRelationshipTypes = array();
    // Get the custom field ID of the field that specifies generating tokens.
    $tokenCustomFieldId = civicrm_api3('CustomField', 'getvalue', [
      'name' => 'display_reltokens',
      'return' => 'id',
    ]);

    $result = civicrm_api3('relationshipType', 'get', array(
      'is_active' => 1,
      'custom_' . $tokenCustomFieldId => 1,
      'options' => array(
        'limit' => 0,
      ),
    ));
    $unique_keys = array();
    foreach ($result['values'] as $value) {
      $key = preg_replace('/\W/', '_', "{$value['name_a_b']}_{$value['name_b_a']}");
      if (in_array($key, $unique_keys)) {
        continue;
      }
      $unique_keys[] = $key;

      $directions = array();
      if ($value['name_a_b'] == $value['name_b_a']) {
        $directions[0] = $value['label_a_b'];
      }
      else {
        $directions['a'] = $value['label_a_b'];
        $directions['b'] = $value['label_b_a'];
      }
      foreach ($directions as $direction => $directionLabel) {
        //'b_Benefits_Specialist_is_Benefits_Specialist' => 'Benefits Specialist',
        $hashedRelationshipTypes["{$direction}_{$key}"] = array(
          'directionLabel' => $directionLabel,
          'relationship_type_id' => $value['id'],
        );
      }
    }
  }
  return $hashedRelationshipTypes;
}

/**
 * implementation of CiviCRM hook
 *
 * @param array $values
 * @param $contactIDs
 * @param null $job
 * @param array $tokens
 * @param null $context
 */
function reltoken_civicrm_tokenValues(&$values, $contactIDs, $job = null, $tokens = array(), $context = null) {
//  dsm(debug_backtrace(), 'bt in '. __FUNCTION__);
//  dsm(func_get_args(), __FUNCTION__);
  if (!empty($tokens['related'])) {
    foreach ($tokens['related'] as $token) {
//      dsm($token, '$token');
      if (strpos($token, '___reltype_')) {
        $relatedContactIDsPerContact = _reltoken_get_related_contact_ids_per_contact($contactIDs, $token);
        $relatedContactIDs = array_unique(array_values($relatedContactIDsPerContact));
        /*
         * 1 => 2
         * 3 => 2
         */
        
        $baseToken = preg_replace('/^(.+)___.+$/', '$1', $token);
//        dsm($baseToken, '$baseToken');
//        dsm($relatedContactIDs, "\$relatedContactIDs for $token");
        $tokenDetails = CRM_Utils_Token::getTokenDetails($relatedContactIDs, array($baseToken => 1), FALSE, FALSE, NULL, array('contact' => array($baseToken)), 'CRM_Reltoken');
//        dsm($tokenDetails, "\$tokenDetails for token $token");
//        dsm($tokenDetails, "\$tokenDetails for $baseToken ($token) in ". __FUNCTION__);
        foreach ($contactIDs as $contactID) {
          $tokenValues = $tokenDetails[0][$relatedContactIDsPerContact[$contactID]];
          $values[$contactID]['related.' . $token] = $tokenValues[$baseToken];
        }
        
      }
    }
  }
//  dsm($values, '$values at end of '. __FUNCTION__);
}

function _reltoken_get_related_contact_ids_per_contact($contactIDs, $token) {
  $relatedContactIDs = array();
//  dsm(func_get_args(), __FUNCTION__);
  // Example: first_name___reltype_b_Benefits_Specialist_is_Benefits_Specialist
  list($junk, $relationshipTypeHash) = explode('___reltype_', $token, 2);
//  dsm($relationshipTypeHash, '$relationshipTypeBase');
  // b_Benefits_Specialist_is_Benefits_Specialist
  $direction = substr($relationshipTypeHash, 0, 1);
  $otherDirection = ($direction == 'a' ? 'b' : 'a');
//  dsm ($direction, 'direction');
  
  $hashedRelationshipTypes = _reltoken_get_hashed_relationship_types();
  $relationshipTypeID = $hashedRelationshipTypes[$relationshipTypeHash]['relationship_type_id'];
  
  if ($direction == '0') {
    // Bidirectional relationships are tricky. Sorry, no API call here. Assuming
    // BAO is more future-proof than SQL, but it probably isn't.
    $bao = new CRM_Contact_BAO_Relationship();
    $contactIDsSQLIn = implode(',', $contactIDs);
    $bao->whereAdd("is_active");
    $bao->whereAdd("relationship_type_id = '$relationshipTypeID'");
    $bao->whereAdd("(contact_id_a IN ($contactIDsSQLIn) OR contact_id_b IN ($contactIDsSQLIn))");
    $bao->orderBy('id DESC');
    
    /**
     * 3: 12 - 24
     * 2: 24 - 36
     * 1: 36 - 12
     */
    
    $bao->find();
    while($bao->fetch()) {
      if (in_array($bao->contact_id_a, $contactIDs) && empty($relatedContactIDs[$bao->contact_id_a])) {
        $relatedContactIDs[$bao->contact_id_a] = $bao->contact_id_b;
      }
      if (in_array($bao->contact_id_b, $contactIDs) && empty($relatedContactIDs[$bao->contact_id_b])) {
        $relatedContactIDs[$bao->contact_id_b] = $bao->contact_id_a;
      }
    }
  } else {
    foreach ($contactIDs as $contactID) {
      $result = civicrm_api3('relationship', 'get', array(
        'sequential' => 1,
        'is_active' => 1,
        'relationship_type_id' => $relationshipTypeID,
        'contact_id_' . $direction => $contactID,
        'options' => array(
          'sort' => "id DESC", 
          'limit' => 1
        ),
      ));
      if (!empty($result['values'][0])) {
        $relatedContactIDs[$contactID] = $result['values'][0]['contact_id_'. $otherDirection];
      }
    } 
  }
//  dsm($relatedContactIDs, "returning \$relatedContactIDs for $token in ". __FUNCTION__);
  return $relatedContactIDs;
}

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function reltoken_civicrm_config(&$config) {
  _reltoken_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function reltoken_civicrm_xmlMenu(&$files) {
  _reltoken_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function reltoken_civicrm_install() {
  _reltoken_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function reltoken_civicrm_postInstall() {
  _reltoken_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function reltoken_civicrm_uninstall() {
  _reltoken_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function reltoken_civicrm_enable() {
  _reltoken_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function reltoken_civicrm_disable() {
  _reltoken_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function reltoken_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _reltoken_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function reltoken_civicrm_managed(&$entities) {
  _reltoken_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function reltoken_civicrm_caseTypes(&$caseTypes) {
  _reltoken_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules
 */
function reltoken_civicrm_angularModules(&$angularModules) {
  _reltoken_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function reltoken_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _reltoken_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
function reltoken_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function reltoken_civicrm_navigationMenu(&$menu) {
  _reltoken_civix_insert_navigation_menu($menu, NULL, array(
    'label' => ts('The Page', array('domain' => 'com.joineryhq.reltoken')),
    'name' => 'the_page',
    'url' => 'civicrm/the-page',
    'permission' => 'access CiviReport,access CiviContribute',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _reltoken_civix_navigationMenu($menu);
} // */

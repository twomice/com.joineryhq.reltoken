<?php

require_once 'reltoken.civix.php';

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Implements hook_civicrm_container().
 */
function reltoken_civicrm_container(ContainerBuilder $container) {
  $container->findDefinition('dispatcher')->addMethodCall('addListener', ['civi.token.list', '_reltoken_register_tokens'])->setPublic(TRUE);
  $container->findDefinition('dispatcher')->addMethodCall('addListener', ['civi.token.eval', '_reltoken_evaluate_tokens'])->setPublic(TRUE);
}

/**
 * Listener on 'civi.token.list' event, connected in reltoken_civicrm_container().
 *
 * @staticvar boolean $calledOnce
 * @param \Civi\Token\Event\TokenRegisterEvent $e
 */
function _reltoken_register_tokens(\Civi\Token\Event\TokenRegisterEvent $e) {
  static $calledOnce = FALSE;
  // Get a list of the standard contact tokens.
  // Note that CRM_Core_SelectValues::contactTokens() will invoke this hook again.
  $contactTokens = [];
  if (!$calledOnce) {
    $calledOnce = TRUE;
    $contactTokens = CRM_Core_SelectValues::contactTokens();
  }
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
        $e->entity('related')->register("{$tokenBase}___reltype_{$hash}", "Related ({$relationshipTypeDetails['directionLabel']})::{$label}");
      }
    }
  }
}

/**
 * Listener on 'civi.token.eval' event, connected in reltoken_civicrm_container().
 *
 * @param \Civi\Token\Event\TokenValueEvent $e
 */
function _reltoken_evaluate_tokens(\Civi\Token\Event\TokenValueEvent $e) {
  $tokens = $e->getTokenProcessor()->getMessageTokens();
  $contactIDs = $e->getTokenProcessor()->getContextValues('contactId');
  if (!empty($tokens['related'])) {
    $schema = ['contactId'];

    foreach ($tokens['related'] as $token) {
      if (strpos($token, '___reltype_')) {
        $relatedContactIDsPerContact = _reltoken_get_related_contact_ids_per_contact($contactIDs, $token);
        $relatedContactIDs = array_unique(array_values($relatedContactIDsPerContact));
        // If you're using a token for a relationship this person doesn't have, just skip it
        // Otherwise you create a query that crushes the system.
        if (!$relatedContactIDs) {
          continue;
        }
        $baseToken = preg_replace('/^(.+)___.+$/', '$1', $token);

        $useSmarty = (bool) (defined('CIVICRM_MAIL_SMARTY') && CIVICRM_MAIL_SMARTY);
        $relatedTokenProcessor = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), [
          'controller' => __CLASS__,
          'schema' => $schema,
          'smarty' => $useSmarty,
        ]);
        $relatedTokenProcessor->addMessage($baseToken, '{contact.' . $baseToken . '}', 'text/plain');
        foreach ($relatedContactIDs as $cid) {
          $relatedTokenProcessor->addRow(['contactId' => $cid]);
        }
        $relatedTokenProcessor->evaluate();
        // Get the contact IDs of both token processors, figure out which rows
        // in the original token processor should get new values.
        $relatedTokenProcessorCidToRowMap = array_flip($relatedTokenProcessor->getContextValues('contactId'));
        $originalTokenProcessorContext = $e->getTokenProcessor()->getContextValues('contactId');
        foreach ($relatedContactIDs as $rowId => $cid) {
          $renderedToken[$cid] = $relatedTokenProcessor->getRow($rowId)->render($baseToken);
        }

        // Go through each of the original rows.  Check if this contact has a related
        // contact matching this token. If so, render the reltoken and add it tos
        // the original row.
        foreach ($e->getRows() as $originalRowKey => $originalRow) {
          $originalRowCid = $originalTokenProcessorContext[$originalRowKey];
          $relatedContactId = ($relatedContactIDsPerContact[$originalRowCid] ?? NULL);
          if ($relatedContactId) {
            $originalRow->tokens('related', $token, $renderedToken[$relatedContactId]);
          }
        }
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
 * Reformat $messageTokens if token names are values.
 *
 * Some components (namely CRM_Activity_BAO_Activity) pass in $messageTokens in
 * TokenProcessor format (token name is value), while others pass in hook
 * format (token name is key).
 *
 * This method reformats $messageTokens if it detects that the token names are
 * passed as values.
 *
 * Original code by xurizaemon: https://github.com/xurizaemon/civicrm-core/commit/90539237365ec9ebf36b703116108d50ac79135c
 *
 */
function formatMessageTokens($messageTokens) {
  $result = [];
  // Don't reformat if any entity.token has token names as keys.
  foreach ($messageTokens as $entity => $names) {
    foreach ($names as $k => $v) {
      if (!is_int($k)) {
        return $messageTokens;
      }
    }
  }
  // All entity.token names are as values here, so reformat.
  foreach ($messageTokens as $entity => $names) {
    foreach ($names as $name) {
      $result[$entity][$name] = 1;
    }
  }
  return $result;
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
    while ($bao->fetch()) {
      if (in_array($bao->contact_id_a, $contactIDs) && empty($relatedContactIDs[$bao->contact_id_a])) {
        $relatedContactIDs[$bao->contact_id_a] = $bao->contact_id_b;
      }
      if (in_array($bao->contact_id_b, $contactIDs) && empty($relatedContactIDs[$bao->contact_id_b])) {
        $relatedContactIDs[$bao->contact_id_b] = $bao->contact_id_a;
      }
    }
  }
  else {
    foreach ($contactIDs as $contactID) {
      $result = civicrm_api3('relationship', 'get', array(
        'sequential' => 1,
        'is_active' => 1,
        'relationship_type_id' => $relationshipTypeID,
        'contact_id_' . $direction => $contactID,
        'options' => array(
          'sort' => "id DESC",
          'limit' => 1,
        ),
      ));
      if (!empty($result['values'][0])) {
        $relatedContactIDs[$contactID] = $result['values'][0]['contact_id_' . $otherDirection];
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
  // Get the custom field ID of the field that specifies generating tokens.
  $tokenCustomFieldId = civicrm_api3('CustomField', 'getvalue', [
    'name' => 'display_reltokens',
    'return' => 'id',
  ]);

  // add generating tokens in RelationshipType
  $result = civicrm_api3('RelationshipType', 'get', [
    'sequential' => 1,
    'is_active' => 1,
    'api.RelationshipType.create' => [
      'id' => '$value.id',
      "custom_{$tokenCustomFieldId}" => 1,
    ],
  ]);

}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function reltoken_civicrm_enable() {
  _reltoken_civix_civicrm_enable();
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 */
// function reltoken_civicrm_preProcess($formName, &$form) {

// } // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 */
// function reltoken_civicrm_navigationMenu(&$menu) {
//   _reltoken_civix_insert_navigation_menu($menu, NULL, array(
//     'label' => ts('The Page', array('domain' => 'com.joineryhq.reltoken')),
//     'name' => 'the_page',
//     'url' => 'civicrm/the-page',
//     'permission' => 'access CiviReport,access CiviContribute',
//     'operator' => 'OR',
//     'separator' => 0,
//   ));
//   _reltoken_civix_navigationMenu($menu);
// } // */

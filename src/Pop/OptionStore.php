<?php

namespace Civi\Pop;

/**
 * A place to cache CiviCRM entitiy field options to reduce API calls
 */
class OptionStore {

  private $store = array();

  function getRandomId($entity, $field){
    if(!isset($this->store[$entity][$field])){
      $options = Connection::api4($entity, 'getoptions', [
        'sequential' => 1,
        'field' => $field,
      ]);
      $this->store[$entity][$field] = $options['values'];
    }
    return $this->store[$entity][$field][array_rand($this->store[$entity][$field])]['key'];
  }
}

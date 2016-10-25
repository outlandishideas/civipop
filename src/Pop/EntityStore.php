<?php

namespace Civi\Pop;

/**
 * A place to cache CiviCRM entities to reduce API calls
 */
class EntityStore {

  private $store = array();

  private $entityParams = array('Event' => array('is_template' => false));

  function getRandom($entity, $filter = NULL){
    $key = $this->getKey($entity, $filter);
    return $this->store[$key][$this->getRandomId($entity, $filter)];
  }

  function getSpecific($entity, $filter = NULL, $id){
    $key = $this->getKey($entity, $filter);
    if(!isset($this->store[$key])){
      $this->init($entity, $filter);
    }
    return $this->store[$key][$id];
  }

  function getRandomId($entity, $filter = NULL){
    $key = $this->getKey($entity, $filter);
    if(!isset($this->store[$key])){
      $this->init($entity, $filter);
    }
    return array_rand($this->store[$key]);
  }

  function getKey($entity, $filter){
    $key = $entity;
    if(isset($filter)){
      ksort($filter);
      $key .= json_encode($filter);
    }
    return $key;
  }

  function init($entity, $filter){
    if(isset($entityParams[$entity])){
      $filter = array_merge($filter, $entityParams[$entity]);
    }
    $params = array_merge($filter, array('options' => array('limit' => 10000)));
    $result = civicrm_api3($entity, 'get', $params);
    $this->store[$this->getKey($entity, $filter)] = $result['values'];
  }

  function add($entity, $id){
    $this->store[$entity] = $id;
  }
}

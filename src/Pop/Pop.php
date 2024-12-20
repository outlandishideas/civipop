<?php
namespace Civi\Pop;

use Faker;
use Symfony\Component\Yaml\Yaml;
use Civi\Pop\Populator;
use Civi\Pop\EntityStore;
use Civi\Pop\OptionStore;


class Pop {

  // Summarise the process (number of entities imported, etc.)
  var $summary = array();

  // Whether we are being called in interactive mode or not
  var $interactive = false;

  // The types of entity that we can populate
  var $availableEntities  = array();

  // The fields we can populate for each type of entity
  var $availableFields  = array();

  var $requiredFields  = array();

  function __construct($output){
    // Initialise output interface
    $this->output = $output;

    // Initialise faker
    $this->faker = Faker\Factory::create();

    // Initialise entity store
    $this->entityStore = new EntityStore();

    // Initialise option store
    $this->optionStore = new OptionStore();

    // Initialise entity
    //
    $this->populator = new Populator($this->entityStore, $this->optionStore);

    // Get available entities (pretending that Individuals, Organisations and
    // Households are also entities)

    $availableEntities = Connection::api4('Entity', 'get', ['checkPermissions' => false, 'select' => ['name']]);
    $availableEntities = array_column($availableEntities, 'name');
    $this->availableEntities = array_merge(
      $availableEntities,
      array('Individual', 'Household', 'Organization')
    );

    // Define where to find Pop yml files
    $this->entityDefaultDir = __DIR__.DIRECTORY_SEPARATOR.'EntityDefault'.DIRECTORY_SEPARATOR;

    $this->defaultDefinition = Yaml::parseFile("{$this->entityDefaultDir}default.yml");

  }

  function process($file){

    $this->log("Adding entities...", 'comment');

    // load instructions from files
    $this->load($file);

    // process each instruction
    foreach ($this->instructions as $instruction){

      // create a definition from each instruction
      $definition = $this->translate($instruction);

      // backfill definition with entity defaults
      $definition = $this->backfill($definition);

      // create entities based on the definition
      $this->createEntities($definition);
    }

    // summarise the process
    $this->summarize();
  }

  function log($message, $level = null){
    if($this->isInteractive()){
      if(isset($level)){
        $this->output->writeln("<$level>$message</$level>");
      }else{
        $this->output->writeln("$message");
      }
    }
  }

  function load($file){

    // load the yaml file
    $this->instructions = Yaml::parseFile($file);
    if(!$this->instructions){
      $this->log("Error: could not open yaml file: ($file)", 'error');
      exit(1);
    }
  }

  /**
   * Parses an instructVion, returning a definition when valid and
   * exiting with error messages when not valid.
   * @param  $instruction
   * @return $definition
   */
  function translate($instruction){

    // clone the $instruction for debugging purposes
    $original = $instruction;

    // move valid parts of the instruction to the definition
    $parts = array('fields', 'children', 'populators');

    foreach($parts as $part){
      if(isset($instruction[$part])){
        $definition[$part]=$instruction[$part];
        unset($instruction[$part]);
      }else{
        $definition[$part]=array();
      }
    }

    // at this point, the instruction should be a one
    // element array of form array(Entity => count).
    // Commplain if this is not the case.
    if(count($instruction) != 1){
      $this->log("Error: badly formatted instruction", 'error');
      $this->log(Yaml::dump($original), 'error');
      exit(1);
    }

    // define the entity and check that it is available
    $definition['entity'] = key($instruction);
    if(!in_array($definition['entity'], $this->availableEntities)){
      $this->log("Error: could not find entity: {$definition['entity']} (yaml below)", 'error');
      $this->log(Yaml::dump($original));
      exit(1);
    }

    // check that the count is valid, i.e. an integer or a range specified by
    // two integers seperated by a dash or NULL (which we interpret as 1)
    $definition['count'] = current($instruction);
    if($definition['count']===NULL){
      $definition['count'] = 1;
    }
    if(!preg_match('/^\d+(\-\d+)?$/', $definition['count'])){
      $this->log("Error: invalid value for count: {$definition['count']}", 'error');
      $this->log(yaml_emit($original), 'error');
      exit(1);
    }
    return $definition;
  }

  function backfill($definition) {

    try {
      // get defaults for this entity, if they exist
      $entityDefault = Yaml::parseFile("{$this->entityDefaultDir}{$definition['entity']}.yml");
    } catch (\Exception $err) {
      $entityDefault = [];
    }

    // backfill with default fields for this entity
    if(isset($entityDefault['fields'])){
      $definition['fields'] = array_replace_recursive($entityDefault['fields'], $definition['fields']);
    }

    // backfill with global default fields
    if(isset($this->defaultDefinition['fields'])){
      $definition['fields'] = array_replace_recursive($this->defaultDefinition['fields'], $definition['fields']);
    }

    // only allow fields available for this api
    foreach($this->getAvailableFields($definition['entity']) as $field){
      $availableFields[$field['name']] = null;
    }
    $definition['fields'] = array_intersect_key($definition['fields'], $availableFields);

    // add any children defined in the default entity
    if(isset($entityDefault['children'])){
      $definition['children'] = array_merge($definition['children'], $entityDefault['children']);
    }

    // add any populators defined in the default entity
    if(isset($entityDefault['populators'])){
      $definition['populators'] = array_merge($definition['populators'], $entityDefault['populators']);
    }

    return $definition;
  }

  function getAvailableFields($entity){
    if(!isset($this->availableFields[$entity])){
      $fields = Connection::api4($entity, 'getFields', ['checkPermissions' => false, 'action'=> 'create']);
      $this->availableFields[$entity] = $fields;
    }
    return $this->availableFields[$entity];
  }

  function getRequiredFields($entity){
    if(!isset($this->requiredFields[$entity])){
      $this->requiredFields[$entity] = [];
      foreach($this->getAvailableFields($entity) as $availableField){
        if(!empty($availableField['required'])) {
          $this->requiredFields[$entity][$availableField['name']] = $availableField;
        }
      }
    }
    return $this->requiredFields[$entity];
  }

  // create a set of entities from a definition
  function createEntities($definition, $parent = null){

    // if count is a range, decide how many entities to create
    if(strpos($definition['count'], '-')){
      $definition['count'] = explode('-', $definition['count']);
      $definition['count'] = rand(min($definition['count']) , max($definition['count']));
    }

    // if this is an individual, household or organisation, convert it to a
    // contact with an appropriatly defined contact_type
    if(in_array($definition['entity'], array('Individual', 'Household', 'Organization'))){
      $definition['fields']['contact_type'] = $definition['entity'];
      $definition['entity'] = 'Contact';
    }

    // if this is a child of another entity, populate the parent id
    if($parent){
      $definition['fields'][strtolower("{$parent['entity']}_id")]=$parent['id'];
    }


    // create each entity
    $count = 0;
    while($count < $definition['count']){

      $createdEntity = $this->populate($definition['entity'], $definition['fields'], $definition['populators']);
      // create children if necessary
      if(isset($definition['children'])){;
        foreach($definition['children'] as $childInstruction){
          $childDefinition = $this->translate($childInstruction);
          $childDefinition = $this->backfill($childDefinition);
          $this->createEntities($childDefinition, $createdEntity);
        }
      }
      $count++;
    }
  }

  function populate($entity, $fields, $populators){

    if(count($populators)){
      foreach($populators as $populator){
        if(method_exists($this->populator, $populator)){
          $this->populator->$populator($entity, $fields);
        }else{
          $this->log("Could not find method '{$populator}'", 'error');
          exit;
        };
      }
    }
    // go through fields, making substitutions where necessary
    foreach($fields as $name => &$value){

      // if value is an array, select one at (weighted) random
      if(is_array($value)){
        $value = $this->weightedRandomSelect($value);
      }

      // if we are using a modifier, run the appropriate function
      $this->modify($name, $value, $entity);
    }

    // add any required fields using sensible defaults
    foreach($this->getRequiredFields($entity) as $requiredFieldName => $requiredFieldDef){
      if(!isset($fields[$requiredFieldName])){
        if(isset($requiredFieldDef['fk_entity'])){
          $fields[$requiredFieldName] = $this->entityStore->getRandomId($requiredFieldDef['fk_entity']);
        }elseif(isset($requiredFieldDef['pseudoconstant'])){
          $fields[$requiredFieldName] = $this->optionStore->getRandomId($entity, $requiredFieldDef['name']);
        }
      }
    }
    try{
//        echo 'creating ' . $entity . ': ' . json_encode($fields);
      $result = Connection::api4($entity, 'create', ['checkPermissions' => false, 'values' => $fields]);
    }catch(\Exception $e){
      $this->recordFailure($entity, $fields, $e->getMessage());
      return;
    }

    if(!$result['is_error']){
      $this->recordSuccess($entity, $result['id']);
      //add to the random entity register so they can be selected in future
      $this->entityStore->add($entity, $result['id']);
      return array('entity' => $entity, 'id' => $result['id']);
    }else{
      $this->recordFailure($entity, $fields, $result['error_message']);
    }
  }

  function modify($field, &$value, $entity){

    // TODO refactor this function so that it checks for

    // check for keywords

    // check for modifier prefixes

    if(strpos($value,"r.")===0){
      $value = $this->entityStore->getRandomId(substr($value,2));
    }elseif($value == "choose"){
      $value = $this->optionStore->getRandomId($entity, $field);
    }elseif(stripos($value,"f.")===0){
      $value = $this->getFake($value);
    }
  }

  function weightedRandomSelect($array){

    $total = 0;
    foreach($array as $choice => $value){
      if($value==NULL){
        $value=1;
      }
      $choices[$choice] = $total += $value;
    }
    $selection = rand()/getrandmax()*$total;
    foreach($choices as $choice => $value){
      if($selection < $value){
        return $choice;
      }
    }
  }

  function getFake($field){
    $function = explode(',', substr($field,2));
    $output = call_user_func_array(array($this->faker, array_shift($function)), $function);
    if ($output instanceof \DateTime) {
      $output = $output->format('Y-m-d H:i:s');
    }

    if($field[0]=='F'){
      $output=ucfirst($output);
    }
    return $output;
  }

  function recordSuccess($entity, $id){
    $x = 0;
    while ($x < count($this->summary)){
      if($this->isInteractive()){
        echo "\033[1A";
      }
      $x++;
    }
    if(isset($this->summary[$entity])){
      $this->summary[$entity]['count']++;
      $this->summary[$entity]['last_id']=$id;
    }else{
      $this->summary[$entity]['count']=1;
      $this->summary[$entity]['first_id']=$id;
      $this->summary[$entity]['last_id']=$id;
    }
    ksort($this->summary);
    foreach($this->summary as $entity => $stats){
      $this->log("\033[K<fg=green>{$entity}: </><fg=green>{$stats['count']}</>");
    }
  }

  function recordFailure($entity, $fields, $message){
    $this->summary[$entity]['errors'][]=array('fields' => $fields, 'message' => $message);
    $this->log("Could not create '{$entity}' [{$message}] (fields below)", 'error');
    $this->log(print_r($fields, true));
  }

  function summarize(){
    $x = 0;
    while ($x < count($this->summary)){
      if($this->isInteractive()){
        echo "\033[1A";
      }
      $x++;
    }
//    print_r($this->summary);
    foreach($this->summary as $entity => $stats){
      if(!empty($stats['first_id']) && $stats['first_id']==$stats['last_id']){
        $this->log("\033[K<fg=green>{$entity}: {$stats['count']} ({$stats['first_id']})</>");
      }elseif($stats['first_id'] < $stats['last_id']){
        $this->log("\033[K<fg=green>{$entity}: {$stats['count']} ({$stats['first_id']} to {$stats['last_id']})</>");
      }else{
        $this->log("\033[K<fg=green>{$entity}: {$stats['count']} (unknown ids)</>");
      }
    }
  }

  function getSummary(){
    return $this->summary;
  }

  function setInteractive($interactive){
    $this->interactive = (bool) $interactive;
  }

  function isInteractive(){
    return $this->interactive;
  }
}

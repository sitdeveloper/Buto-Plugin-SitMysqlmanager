<?php
/**
<p>Page plugin to sync mysql database against yml data. Store schema in yml and push creation or changes to mysql server.</p>
*/
class PluginSitMysqlmanager{
  /**
  */
  private $settings = null;
  private function init(){
    if(!wfUser::hasRole("webmaster")){
      exit('Role webmaster is required!');
    }
    wfArray::set($GLOBALS, 'sys/layout_path', '/plugin/sit/mysqlmanager/layout');
    
    
    //$this->settings = wfPlugin::getModuleSettings(null, true);
    wfPlugin::includeonce('wf/array');
    $this->settings = new PluginWfArray(wfArray::get($GLOBALS, 'sys/settings/plugin_modules/'.wfArray::get($GLOBALS, 'sys/class').'/settings'));
  }
  public function page_desktop(){
    $this->init();
    
    wfHelp::yml_dump($this->settings);
    
    $desktop = $this->getYml('page/desktop.yml');
    $desktop->set('content/json/innerHTML', "var app = {class: '".wfArray::get($GLOBALS, 'sys/class')."'}; console.log(app);");
    
    $panel_show_tables = $this->getYml('html_object/panel_show_tables.yml');
    $panel_schema = $this->getYml('html_object/panel_schema.yml');
    $panel_schema_extra_field = $this->getYml('html_object/panel_schema_extra_field.yml');
    $panel_console = $this->getYml('html_object/panel_console.yml');
    
    
    $schema = wfSettings::getSettingsAsObject($this->settings->get('schema'));
    if($schema->get('extra')){
      $panel_schema_extra_field->set('innerHTML/panel/innerHTML/body/innerHTML/pre/innerHTML', wfHelp::getYmlDump($schema->get('extra')));
      $desktop->set('content/', $panel_schema_extra_field->get());
    }
    
            
    $desktop->set('content/', $panel_schema->get());
    $desktop->set('content/', $panel_console->get());
    $desktop->set('content/', $panel_show_tables->get());
    
    wfDocument::mergeLayout($desktop->get());
  }
  public function page_show_tables(){
    $this->init();
    $show_tables = $this->showTables();
    //wfHelp::yml_dump($show_tables);
    $div = array();
    foreach ($show_tables->get() as $key => $value) {
      $div[] = wfDocument::createHtmlElement('text', $key.'<br>');
      foreach ($value['field'] as $key2 => $value2) {
        $div[] = wfDocument::createHtmlElement('text', '&nbsp;&nbsp;'.$key2.'<br>');
      }
    }
    $pre = wfDocument::createHtmlElement('pre', ($div));
    wfDocument::renderElement(array($pre));
    
  }
  
  private function showTables(){
    $show_tables = $this->runSQL("show tables;");
    $temp = array();
    foreach ($show_tables->get() as $key => $value) {
      $columns = $this->runSQL("show columns from ".$value['Tables_in_havanna'].";");
      
      $field = array();
      foreach ($columns->get() as $key2 => $value2) {
        $field[$value2['Field']] = $value2;
      }
      
      //$temp[$value['Tables_in_havanna']] = array('field' => $columns->get());
      $temp[$value['Tables_in_havanna']] = array('field' => $field);
      
      
    }
    $show_tables->set(null, $temp);
    //wfHelp::yml_dump($show_tables);
    return $show_tables;
  }
  
  private function doCreateSql($schema){
    foreach ($schema->get('tables') as $key => $value){
      $fields = array();
      $sql = "CREATE TABLE $key ([fields][primary_key] [key] [CONSTRAINT] ) ENGINE=InnoDB DEFAULT CHARSET=latin1;";
      $schema->set('tables/'.$key.'/sql/table', $sql);
      
      
      /**
       * Add extra fields
       */
      if($schema->get('extra/field')){
        foreach ($schema->get('extra/field') as $key2 => $value2) {
          if(!isset($value['field'][$key2])){
            $value['field'][$key2] = $value2;
          }
        }
      }
      
      /**
       * Handle fields.
       */
      foreach ($value['field'] as $key2 => $value2) {
        $field = new PluginWfArray($value2);
        $type = $field->get('type');
        if(strtolower($type)=='timestamp'){
          $type .= ' NULL';  
        }
        $not_null = null;
        if($field->get('not_null')){
          $not_null = " NOT NULL";
        }
        $default = null;
        $auto_increment = null;
        if($field->get('auto_increment')){
          $auto_increment = " auto_increment";
        }else{
          if(strlen($field->get('default'))){
            $default = " default ".$field->get('default');
          }
        }
        $fields[$key2] = "$key2 $type$not_null$default$auto_increment";
      }
      $schema->set('tables/'.$key.'/sql/fields', $fields);
      
      /**
       * Handle primary keys.
       */
      $primary_key = null;
      foreach ($value['field'] as $key2 => $value2) {
        $field = new PluginWfArray($value2);
        if($field->get('primary_key')){
          if($primary_key){
            $primary_key .= ','.$key2;
          }else{
            $primary_key = $key2;
          }
        }
        
      }
      $schema->set('tables/'.$key.'/sql/primary_key', $primary_key);
      
      /**
       * Key.
       */
      $tkey = array();
      foreach ($value['field'] as $key2 => $value2) {
        $field = new PluginWfArray($value2);
        if($field->get('foreing_key')){
          $tkey[] = "KEY ".$key."_".$key2."_fk (".$key2.")";
        }
      }
      $schema->set('tables/'.$key.'/sql/key', $tkey);
      
      /**
       * Constraint.
       */
      $constraint = array();
      foreach ($value['field'] as $key2 => $value2) {
        $field = new PluginWfArray($value2);
        if($field->get('foreing_key')){
          $on_delete = null;
          if(strtoupper($field->get('foreing_key/on_delete')) == 'CASCADE'){
            $on_delete = " ON DELETE CASCADE";
          }
          $on_update = null;
          if(strtoupper($field->get('foreing_key/on_update')) == 'CASCADE'){
            $on_update = " ON UPDATE CASCADE";
          }
          $constraint[] = "CONSTRAINT ".$key."_".$key2."_fk FOREIGN KEY (".$key2.") REFERENCES ".$field->get('foreing_key/reference')."$on_delete$on_update";
        }
      }
      $schema->set('tables/'.$key.'/sql/constraint', $constraint);
      
      
      
      /**
       * Replace [fields] tag.
       */
      $temp = null;
      foreach ($fields as $key2 => $value2) {
        if(!$temp){
          $temp = $value2;
        }  else {
          $temp .= ','.$value2;
        }
      }
      $sql = str_replace('[fields]', $temp, $sql);
      
      /**
       * Replace [primary_key] tag.
       */
      $temp = null;
      if($primary_key){
        $temp = ",PRIMARY KEY ($primary_key)";
      }
      $sql = str_replace('[primary_key]', $temp, $sql);
      
      
      /**
       * Replace [key] tag.
       */
      $temp = null;
      foreach ($tkey as $key2 => $value2) {
        $temp .= ', '.$value2;
      }
      $sql = str_replace('[key]', $temp, $sql);
      
      
      /**
       * Replace [Constrain] tag.
       */
      $temp = null;
      foreach ($constraint as $key2 => $value2) {
        $temp .= ', '.$value2;
      }
      $sql = str_replace('[CONSTRAINT]', $temp, $sql);
      
      
      $schema->set('tables/'.$key.'/sql/create', $sql);
    }
    return $schema;
  }
  
  public function page_schema(){
    $this->init();
    
    $show_tables = $this->showTables();
    //wfHelp::yml_dump($show_tables->get());
    
    
    $schema = wfSettings::getSettingsAsObject($this->settings->get('schema'));
    $schema = $this->doCreateSql($schema);
    $page = $this->getYml('page/schema_table.yml');
    $panel = $this->getYml('html_object/panel_schema_table.yml');
    foreach ($schema->get('tables') as $key => $value) {
      $table = new PluginWfArray($value);
      $alert = null;
      $panel->set('innerHTML/body/innerHTML/sql_create/innerHTML', $value['sql']['create']);
      $panel->set('innerHTML/body/innerHTML/link_console/attribute/data-table', $key);
      //$panel->set('innerHTML/body/innerHTML/pre/innerHTML', wfHelp::getYmlDump($value['field']));
      $panel->set('innerHTML/body/innerHTML/pre/innerHTML', wfHelp::getYmlDump($value));
      $panel->set('innerHTML/body/innerHTML/pre/settings/disabled', false);
      $panel->set('innerHTML/body/innerHTML/description/innerHTML', $table->get('description'));
      $panel->set('innerHTML/body/innerHTML/alert/settings/disabled', true);
      $panel->set('innerHTML/body/innerHTML/console/attribute/id', 'console_'.$key);
      
      if($show_tables->get($key)==null){
        $panel->set('innerHTML/heading/attribute/style', 'background:yellow');
        $panel->set('innerHTML/heading/innerHTML/h3/innerHTML', $key.' (not in database)');
      }else{
        $panel->set('innerHTML/heading/innerHTML/h3/innerHTML', $key);
        
        foreach ($schema->get("tables/$key/field") as $key2 => $value2) {
          
          if(!$show_tables->get("$key/field/$key2") ){
            //echo $key2.' is not in db...<br>';
            $alert .= "Field $key2 is missing in database.<br>";
          }
          
          
        }
        
        if($alert){
          $panel->set('innerHTML/body/innerHTML/alert/innerHTML', $alert);
          $panel->set('innerHTML/body/innerHTML/alert/settings/disabled', false);
        }else{
        }
      }
      
      
      $page->set('content/', $panel->get());
    }
    wfDocument::mergeLayout($page->get());
  }
  
  public function page_console(){
    $this->init();
    $schema = wfSettings::getSettingsAsObject($this->settings->get('schema'));
    $schema = $this->doCreateSql($schema);
    echo ($schema->get('tables/'.wfRequest::get('table').'/sql/create'));
    echo '<hr>';
    $temp = $this->runSQL($schema->get('tables/'.wfRequest::get('table').'/sql/create'));
    //var_dump($temp);
    
    $script = wfDocument::createHtmlElement('script', "alert('Table ".wfRequest::get('table')." was created.')");
    wfDocument::renderElement(array($script));
    
  }
  
  public function page_get_create_script(){
    
    $this->init();
    $schema = wfSettings::getSettingsAsObject($this->settings->get('schema'));
    $schema = $this->doCreateSql($schema);
    $sql = null;
    foreach ($schema->get('tables') as $key => $value) {
      $sql .= $value['sql']['create']."\n\n";
    }
    
    
    
    $textarea = wfDocument::createHtmlElement('textarea', $sql, array('style' => "width:100%;height:300px;"));
    
    
    wfDocument::renderElement(array($textarea));
  }
  
  
   
  
  
  
  /**
   * Get yml.
   * Example $this->getYml('/page/desktop.yml');
   */
  private function getYml($file){
    return wfSettings::getSettingsAsObject('/plugin/sit/mysqlmanager/'.$file);
  }
  private function runSQL($sql){
    //wfHelp::yml_dump($this->settings->get('mysql'), true);
    wfPlugin::includeonce('wf/mysql');
    $mysql = new PluginWfMysql();
    $mysql->open($this->settings->get('mysql'));
    $test = $mysql->runSql($sql);
    return new PluginWfArray($test['data']);
  }
  
}

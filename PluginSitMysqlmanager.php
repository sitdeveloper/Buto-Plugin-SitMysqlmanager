<?php
/**
<p>Page plugin to sync mysql database against yml data. Store schema in yml and push creation or changes to mysql server.</p>
<p>Setup example</p>
#code-yml#
plugin_modules:
  mysqlmanager:
    plugin: 'sit/mysqlmanager'
    settings:
      mysql:
        server: 'localhost'
        database: '_db_name'
        user_name: '_user_name'
        password: '_password'
      schema: '/theme/_org/_name/mysql/schema.yml'
#code#
<p>Schema example</p>
#code-yml#
tables:
  country:
    _description: Country
    field:
      id:
        type: int(11)
        not_null: true
        auto_increment: true
        primary_key: true
      name:
        type: varchar(50)
        default: 'null'
  county:
    _description: County
    field:
      id:
        type: int(11)
        not_null: true
        auto_increment: true
        primary_key: true
      country_id:
        type: int(11)
        default: 'null'
        foreing_key:
          reference_table: country
          reference_field: id
          on_delete: RESTRICT
          on_update: CASCADE
      name:
        type: varchar(50)
        default: 'null'
extra:
  _description: Extra field to add to each table if not exist in schema.
  field:
    created_at:
      type: timestamp
      default: CURRENT_TIMESTAMP
    updated_at:
      type: timestamp
      default: 'null'
    created_by:
      type: varchar(50)
    updated_by:
      type: varchar(50)
#code#
*/
class PluginSitMysqlmanager{
  /**
  */
  private $settings = null;
  /**
   <p>Init method.</p>
   */
  private function init(){
    if(!wfUser::hasRole("webmaster")){
      exit('Role webmaster is required!');
    }
    wfArray::set($GLOBALS, 'sys/layout_path', '/plugin/sit/mysqlmanager/layout');
    wfPlugin::includeonce('wf/array');
    $this->settings = new PluginWfArray(wfArray::get($GLOBALS, 'sys/settings/plugin_modules/'.wfArray::get($GLOBALS, 'sys/class').'/settings'));
    /**
     * Handle mysql param if string to yml file.
     */
    $this->settings->set('mysql', wfSettings::getSettingsFromYmlString($this->settings->get('mysql')));
  }
  /**
   <p>Start page.</p>
   */
  public function page_desktop(){
    $this->init();
    $desktop = $this->getYml('page/desktop.yml');
    $desktop->set('content/json/innerHTML', "var app = {class: '".wfArray::get($GLOBALS, 'sys/class')."'};");
    $panel_show_tables = $this->getYml('html_object/panel_show_tables.yml');
    $panel_schema = $this->getYml('html_object/panel_schema.yml');
    $panel_schema_extra_field = $this->getYml('html_object/panel_schema_extra_field.yml');
    $schema = wfSettings::getSettingsAsObject($this->settings->get('schema'));
    $desktop->set('content/', $panel_schema->get());
    if($schema->get('extra')){
      $panel_schema_extra_field->set('innerHTML/panel/innerHTML/body/innerHTML/pre/innerHTML', wfHelp::getYmlDump($schema->get('extra')));
      $desktop->set('content/', $panel_schema_extra_field->get());
    }
    $desktop->set('content/', $panel_show_tables->get());
    wfDocument::mergeLayout($desktop->get());
  }
  /**
   <p>Show tables in db.</p>
   */
  public function page_show_tables(){
    $this->init();
    $show_tables = $this->showTables();
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
  /**
   <p>Method to get all tables and fields in db.</p>
   */
  private function showTables(){
    $show_tables = $this->runSQL("show tables;");
    $temp = array();
    foreach ($show_tables->get() as $key => $value) {
      $columns = $this->runSQL("show columns from ".$value['Tables_in_'.wfCrypt::decryptFromString($this->settings->get('mysql/database'))].";");
      $field = array();
      foreach ($columns->get() as $key2 => $value2) {
        $field[$value2['Field']] = $value2;
      }
      $temp[$value['Tables_in_'.wfCrypt::decryptFromString($this->settings->get('mysql/database'))]] = array('field' => $field);
    }
    $show_tables->set(null, $temp);
    return $show_tables;
  }
  /**
   <p>Method to make create sql from schema.</p>
   */
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
          $constraint[] = "CONSTRAINT ".$key."_".$key2."_fk FOREIGN KEY (".$key2.") REFERENCES ".$field->get('foreing_key/reference_table')."(".$field->get('foreing_key/reference_field').")$on_delete$on_update";
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
  /**
   <p>Ajax page to show schema.</p>
   */
  public function page_schema(){
    $this->init();
    $show_tables = $this->showTables();
    $schema = wfSettings::getSettingsAsObject($this->settings->get('schema'));
    $schema = $this->doCreateSql($schema);
    $page = $this->getYml('page/schema_table.yml');
    $panel = $this->getYml('html_object/panel_schema_table.yml');
    foreach ($schema->get('tables') as $key => $value) {
      
      /**
       * Add field from extra param.
       */
      if($schema->get('extra/field')){
        foreach ($schema->get('extra/field') as $key2 => $value2) {
          if(!isset($value['field'][$key2])){
            $value['field'][$key2] = $value2;
            $schema->set("tables/$key/field/$key2", $value2);
          }
        }
      }
      /**
       * 
       */
      $table = new PluginWfArray($value);
      $alert = null;
      $panel->set('innerHTML/body/innerHTML/sql_create/innerHTML', $value['sql']['create']);
      $panel->set('innerHTML/body/innerHTML/link_console/attribute/data-table', $key);
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
            $onclick = "PluginWfAjax.load('console_$key', '/'+app.class+'/add_field/table/$key/field/$key2');";
            $alert .= "Field $key2 is missing in database,  <a href=#! onclick=\"$onclick\">Add</a>.<br>";
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
  /**
   <p>Ajax console page.</p>
   */
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
  
  /**
   * <p>Add field.</p>
   */
  public function page_add_field(){
    $this->init();
    $table = wfRequest::get('table');
    $field = wfRequest::get('field');
    $schema = wfSettings::getSettingsAsObject($this->settings->get('schema'));
    
    
    $do_create_sql = $this->doCreateSql($schema);
    //wfHelp::yml_dump($do_create_sql->get("tables/$table/sql/fields/$field"));
    
    $type = $schema->get("tables/$table/field/$field/type");
    //$sql = "ALTER TABLE $table ADD COLUMN $field $type NULLzzz;";
    $sql = "ALTER TABLE $table ADD COLUMN ".$do_create_sql->get("tables/$table/sql/fields/$field").";";
    wfHelp::yml_dump($sql);
    $temp = $this->runSQL($sql);
    var_dump($temp);
    
    
  }
  
  /**
   <p>Ajax page to get all create script in a textbox.</p>
   */
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
  /**
   <p>Method to run sql.</p>
   */
  private function runSQL($sql){
    wfPlugin::includeonce('wf/mysql');
    $mysql = new PluginWfMysql();
    $mysql->open($this->settings->get('mysql'));
    $test = $mysql->runSql($sql);
    return new PluginWfArray($test['data']);
  }
}

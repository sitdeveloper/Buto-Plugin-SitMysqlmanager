# Buto-Plugin-SitMysqlmanager
Page plugin to sync mysql database against yml data. Store schema in yml and push creation or changes to mysql server.

## Settings

```
plugin_modules:
  mysqlmanager:
    plugin: 'sit/mysqlmanager'
    settings:
      mysql:
        server: 'localhost'
        database: '_db_name'
        user_name: '_user_name'
        password: '_password'
      schema: '/theme/_folder_/_folder_/mysql/schema.yml'
```


## Schema example

Example of tables country and county. 

### Insert

Table county as an optional insert param for file and key where sql script is located. If this is set one could click on button Insert and preview script before running it. The button is only visible if this setting exist.

```
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
    insert:
      file: '/plugin/_folder_/_folder_/data/data.yml'
      key: 'county/sql'
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
```

## Extra

Extra optional param is included in all tables also.

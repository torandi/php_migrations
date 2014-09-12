Introduction
==============
This is a php-script that helps you handling versions of your database in a format that works well with code versioning.

Configuration and setup
=============
1. Either check this out as a subrepo or just paste the files into your project.
1. Create a directory in your project named "migrations" (or whatever)
1. Put the scripts (update_database.php and create_migration.php) in the directory, either directly or with symlinks.
1. Copy config-example.php to config.php and edit it to fit your project (see config-example.php for more info)

Usage
============
Use ./create_migration.php migration_name to create a new migration

This creates a empty migration with a name like 20110821231945_migration_name.sql.
The file name (including the date) is the version name, and must be unique.

You may also specify a second argument to create_migration to select file format (sql or php):
* SQL: SQL to be run for the migration (multiple lines separated by ;)
* PHP: PHP code to be executed, the environment you loaded in config.php is available, remember <?php and be verbose. Not run in global scope

To then run the migrations execute ./update_database.php which runs all unrun migrations.
The table schema_migrations are created (if not exist) containing all run migrations.

PHP-migration-script-helper-functions
-------------------------------------
migration_sql(query): Print and run query
run_sql(query): Run query in silence

update_database.php usage
------------------------------
./update_database.php [options] [username]

Username may be optional, depending on your config.php

### Arguments

    --help,  -h: Show help
    --check, -c: Checks if there are any migrations to run, but does not run them (dry run)
